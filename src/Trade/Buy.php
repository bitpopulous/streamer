<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Trade\Trade;

class Buy extends Trade
{

    private $executedSellerOrders = [];

    public function __construct($isBroadcasterRequired = false)
    {
        parent::__construct($isBroadcasterRequired);
    }

    /**
     * 
     * True will use hold debit & credit, Using hold balance for deduction and credit 
     * False will NOT use hold debit & credit, Direct deduction and credit 
     */
    private function _do_buy_trade($buytrade, $selltrade)
    {

        if (
            $buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS
            && $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS
        ) {

            $this->executedSellerOrders[] = $selltrade;

            log_message('info', '--------DO BUY START--------');

            $coinpair_id = intval($buytrade->coinpair_id);
            $symbol = $this->CI->WsServer_model->get_coinpair_symbol_of_coinpairId($coinpair_id);
            $symbol =  str_replace('_', '', strtoupper($symbol));

            $primary_coin_id    = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
            $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

            if ($buytrade->is_market) {
                // Using seller's price for market buyer
                $trade_price    = $selltrade->bid_price;
            } else {
                $trade_price    = $this->DM->smallest($selltrade->bid_price, $buytrade->bid_price);
            }

            $trade_qty      = $this->DM->smallest($selltrade->bid_qty_available, $buytrade->bid_qty_available);
            $trade_amount   = $this->DM->safe_multiplication([$trade_qty, $trade_price]);

            log_message('debug', 'Trade Qty : ' . $trade_qty);
            log_message('debug', 'Trade Price : ' . $trade_price);
            log_message('debug', 'Trade Amount : ' . $trade_amount);


            /**
             * 
             * BUYER will PAY $trade_amount & GET $trade_qty
             * SELLET will PAY $trade_qty & GET $trade_amount
             */

            // BUYER AND SELLER BALANCE UPDATE HERE

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay        = $trade_qty;

            $seller_receiving_amount = $trade_amount; //$this->_safe_math(" $trade_qty * $trade_price ");
            $buyer_will_pay          = $trade_amount; //$this->_safe_math(" $trade_qty * $trade_price ");

            /**
             * Here buyer always be a TAKER and seller as a MAKER
             */
            $buyerPercent   = $this->_getTakerFees($buytrade->user_id);
            $buyerTotalFees = $this->_calculateFeesAmount($buyer_receiving_amount, $buyerPercent);

            log_message('debug', 'Buyer Percent : ' . $buyerPercent);
            log_message('debug', 'Buyer Total Fees : ' . $buyerTotalFees);

            $sellerPercent   = $this->_getMakerFees($selltrade->user_id);
            $sellerTotalFees = $this->_calculateFeesAmount($seller_receiving_amount, $sellerPercent);

            log_message('debug', 'Seller Percent : ' . $sellerPercent);
            log_message('debug', 'Seller Total Fees : ' . $sellerTotalFees);

            // $buyer_receiving_amount_after_fees  = $this->_safe_math(" $buyer_receiving_amount - $buyerTotalFees");
            // $seller_receiving_amount_after_fees = $this->_safe_math(" $seller_receiving_amount - $sellerTotalFees");

            $buyer_receiving_amount_after_fees  = $this->DM->safe_minus([$buyer_receiving_amount, $buyerTotalFees]);
            $seller_receiving_amount_after_fees = $this->DM->safe_minus([$seller_receiving_amount, $sellerTotalFees]);

            log_message('debug', 'Buyer receiving after fees : ' . $buyer_receiving_amount_after_fees);
            log_message('debug', 'Seller receiving after fees : ' . $seller_receiving_amount_after_fees);


            /**
             * Credit Fees to admin
             */
            log_message("debug", "---------------------------------------------");
            log_message('debug', 'Start : Admin Fees Credit ');

            $adminPrimaryCoinBalanceDetail     = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->admin_id);
            $adminSecondaryBalanceCoinDetail     = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->admin_id);

            $referralCommissionPercentRate = $this->CI->WsServer_model->getReferralCommissionRate();

            $isBuyerReferredUser = $this->CI->WsServer_model->isReferredUser($buytrade->user_id);
            $isSellerReferredUser = $this->CI->WsServer_model->isReferredUser($selltrade->user_id);

            log_message("debug", "Is BUYER referred User : " . $isBuyerReferredUser);
            log_message("debug", "Is SELLER referred User : " . $isSellerReferredUser);

            // Check if BUYER user is referred user
            if ($isBuyerReferredUser && $this->DM->isZeroOrNegative($referralCommissionPercentRate) == false) {
                // Give 10% of commision to referral user Id
                $buyerReferralUserId = $this->CI->WsServer_model->getReferralUserId($buytrade->user_id);
                log_message("debug", "Referral Buyer User Id : " . $buyerReferralUserId);

                $buyerReferralBalanceDetail = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $buyerReferralUserId);

                $a1 = $this->DM->safe_multiplication([$referralCommissionPercentRate, $buyerTotalFees]);

                $referralCommission = $this->DM->safe_division([$a1, 100]);
                log_message("debug", "Buy Referral Commission : " . $referralCommission);

                $adminGetsAfterCommission = $this->DM->safe_minus([$buyerTotalFees, $referralCommission]);

                log_message("debug", "Buyer Admin commission  : " . $adminGetsAfterCommission);

                // REFERRAL USER
                $this->_referral_user_balance_update($buyerReferralUserId, $primary_coin_id, $referralCommission);
                // Add Referral User Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_REFERRAL_CREDIT, $buyerReferralBalanceDetail->id, $buyerReferralUserId, $primary_coin_id, $referralCommission, 0);

                // ADMIN
                $this->CI->WsServer_model->credit_admin_fees_by_coin_id($primary_coin_id, $adminGetsAfterCommission);
                // Add Admin Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_FEES_CREDIT, $adminPrimaryCoinBalanceDetail->id, $this->admin_id, $primary_coin_id, $adminGetsAfterCommission, 0);
            } else {

                $this->CI->WsServer_model->credit_admin_fees_by_coin_id($primary_coin_id, $buyerTotalFees);
                // Add Admin Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_FEES_CREDIT, $adminPrimaryCoinBalanceDetail->id, $this->admin_id, $primary_coin_id, $buyerTotalFees, 0);
            }


            // Check if BUYER user is referred user
            if ($isSellerReferredUser && $this->DM->isZeroOrNegative($referralCommissionPercentRate) == false) {

                // Give 10% of commision to referral user Id
                $sellerReferralUserId = $this->CI->WsServer_model->getReferralUserId($selltrade->user_id);
                log_message("debug", "Referral Seller User Id : " . $sellerReferralUserId);

                $sellerReferralBalanceDetail = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $sellerReferralUserId);

                $a2 = $this->DM->safe_multiplication([$referralCommissionPercentRate, $sellerTotalFees]);

                $referralCommission = $this->DM->safe_division([$a2, 100]);
                log_message("debug", "Sell Referral Commission : " . $referralCommission);

                $adminGetsAfterCommission = $this->DM->safe_minus([$sellerTotalFees, $referralCommission]);

                log_message("debug", "Seller Admin commission  : " . $adminGetsAfterCommission);
                // REFERRAL USER
                $this->_referral_user_balance_update($sellerReferralUserId, $secondary_coin_id, $referralCommission);
                // Add Referral User Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_REFERRAL_CREDIT, $sellerReferralBalanceDetail->id, $sellerReferralUserId, $secondary_coin_id, $referralCommission, 0);

                // ADMIN
                $this->CI->WsServer_model->credit_admin_fees_by_coin_id($secondary_coin_id, $adminGetsAfterCommission);
                // Add Admin Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_FEES_CREDIT, $adminSecondaryBalanceCoinDetail->id, $this->admin_id, $secondary_coin_id, $adminGetsAfterCommission, 0);
            } else {

                $this->CI->WsServer_model->credit_admin_fees_by_coin_id($secondary_coin_id, $sellerTotalFees);
                // Add Admin Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_FEES_CREDIT, $adminSecondaryBalanceCoinDetail->id, $this->admin_id, $secondary_coin_id, $sellerTotalFees, 0);
            }


            log_message('debug', 'End : Admin Fees Credit ');
            log_message("debug", "---------------------------------------------");


            // SELLER WILL GET SECONDARY COIN
            // THE PRIMARY AMOUNT SELLER HAS HOLD WILL BE DEDUCTED
            // AND SECONDARY COIN WILL BE CREDITED
            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amount_after_fees);

            if ($buytrade->is_market) {
                // NO HOLD USE : TRUE, Direct deduction & credit
                // BUYER WILL PAY SECONDARY COIN AMOUNT
                $this->CI->WsServer_model->get_debit_balance_new($buytrade->user_id, $secondary_coin_id, $buyer_will_pay);

                // BUYER WILL GET PRIMARY COIN AMOUNT
                $this->CI->WsServer_model->get_credit_balance_new($buytrade->user_id, $primary_coin_id, $buyer_receiving_amount_after_fees);
            } else {

                // BUYER WILL GET PRIMARY COIN
                // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTING
                // AND PRIMARY COIN WILL BE CREDITED
                $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount_after_fees, $buyer_will_pay);
            }

            $seller_av_bid_amount_after_trade = $this->DM->safe_minus([$selltrade->amount_available, $trade_amount]);
            $buyer_av_qty_after_trade = $this->DM->safe_minus([$buytrade->bid_qty_available, $trade_qty]);
            $seller_av_qty_after_trade = $this->DM->safe_minus([$selltrade->bid_qty_available, $trade_qty]);

            $buyer_qty_fulfilled = $this->DM->safe_minus([$buytrade->bid_qty_available, $trade_qty]);
            $seller_qty_fulfilled = $this->DM->safe_minus([$selltrade->bid_qty_available, $trade_qty]);

            $is_buyer_qty_fulfilled = $this->DM->isZero($buyer_qty_fulfilled);
            $is_seller_qty_fulfilled = $this->DM->isZero($seller_qty_fulfilled);


            log_message("debug", "seller_av_bid_amount_after_trade : " . $seller_av_bid_amount_after_trade);
            log_message("debug", "buyer_av_qty_after_trade : " . $buyer_av_qty_after_trade);
            log_message("debug", "seller_av_qty_after_trade : " . $seller_av_qty_after_trade);
            log_message("debug", "buyer_qty_fulfilled : " . $buyer_qty_fulfilled);
            log_message("debug", "seller_qty_fulfilled : " . $seller_qty_fulfilled);
            log_message("debug", "is_buyer_qty_fulfilled : " . $is_buyer_qty_fulfilled ? 'True' : 'False');
            log_message("debug", "is_seller_qty_fulfilled : " . $is_seller_qty_fulfilled ? 'True' : 'False');


            $buyupdate = array(
                'bid_qty_available' => $buyer_av_qty_after_trade,
                'fees_amount' => $this->DM->safe_add([$buytrade->fees_amount, $buyerTotalFees]),
                'status' => $is_buyer_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $seller_av_qty_after_trade,
                'amount_available' => $seller_av_bid_amount_after_trade,
                'fees_amount' => $this->DM->safe_add([$selltrade->fees_amount, $sellerTotalFees]),
                'status' => $is_seller_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );


            if ($buytrade->is_market) {
                // BUYER MARKET
                // 1. Update Average total Amount
                // 2. Update Average Price

                if ($this->DM->isGreaterThan($buytrade->bid_price, 0)) {
                    $tPrice = $this->DM->safe_add([$buytrade->bid_price, $trade_price]);
                    $averagePrice = $this->DM->safe_division([$tPrice, 2]);
                } else {
                    $averagePrice = $trade_price;
                }

                if ($this->DM->isGreaterThan($buytrade->total_amount, 0)) {
                    $tAmount = $this->DM->safe_add([$buytrade->total_amount, $trade_amount]);
                    $averageTotalAmount = $this->DM->safe_division([$tAmount, 2]);
                } else {
                    $averageTotalAmount = $trade_amount;
                }

                $buyupdate['total_amount'] = $averageTotalAmount;
                $buyupdate['bid_price'] = $averagePrice;
            } else {
                $buyer_av_bid_amount_after_trade = $this->DM->safe_minus([$buytrade->amount_available, $trade_amount]);
                $buyupdate['amount_available'] = $buyer_av_bid_amount_after_trade;
            }

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');
            $success_datetimestamp = strtotime($success_datetime);

            $buytraderlog = array(
                'bid_id' => $buytrade->id,
                'bid_type' => $buytrade->bid_type,
                'complete_qty' => $trade_qty,
                'bid_price' => $trade_price,
                'complete_amount' => $buyer_will_pay,
                'user_id' => $buytrade->user_id,
                'coinpair_id' => $buytrade->coinpair_id,
                'success_time' => $success_datetime,
                'fees_amount' => $buyerTotalFees,
                'available_amount' => $buytrade->is_market ? 0 : $buyer_av_bid_amount_after_trade,
                'status' => $is_buyer_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);

            $log_id = $this->CI->WsServer_model->insert_order_log($buytraderlog);

            // Update SL orders and OHLCV
            $this->after_successful_trade($coinpair_id,  $trade_price, $trade_qty, $success_datetimestamp);

            log_message('info', '--------DO BUY END--------' . $trade_qty);

            /**
             * =================
             * EVENTS
             * =================
             */

            try {

                $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);
                $this->event_order_updated($buytrade->id, $buytrade->user_id);
                $this->event_order_updated($selltrade->id, $selltrade->user_id);
                $this->event_trade_created($log_id);
                $this->event_market_summary();
            } catch (\Exception $e) {
            }

            return true;
        }
    }

    /**
     * @return bool
     */
    public function _limit($coinpair_id, $qty, $price, $user_id): array
    {
        $data = [
            'isSuccess' => true,
            'message' => '',
            'trade_type' => PopulousWSSConstants::TRADE_TYPE_LIMIT,
        ];

        $this->user_id = $user_id;

        if ($this->user_id == null) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'user_could_not_found';
            $data['message'] = 'User could not found.';
            return $data;
        }

        $coinpair_id = intval($coinpair_id);
        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coinpair_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'invalid_pair';
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($price, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'buy_price_is_invalid';
            $data['message'] = 'Buy price is invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'buy_amount_is_invalid';
            $data['message'] = 'Buy amount is invalid.';
            return $data;
        }

        $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);
        $totalAmount = $this->DM->safe_multiplication([$price, $qty]);

        $balance_secondary     = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);

        // Check balance first
        if ($this->DM->isGreaterThanOrEqual($balance_secondary->balance, $totalAmount)) {

            $last_id  = $this->create_limit_order('BUY', $qty, $price, $coinpair_id, $this->user_id);
            $data['order_id'] = $last_id;

            if ($last_id) {

                log_message('debug', '===========LIMIT BUY ORDER STARTED===========');

                log_message('debug', 'Order Id : ' . $last_id);
                log_message('debug', 'Price : ' . $price);
                log_message('debug', 'Qty : ' . $qty);
                log_message('debug', 'Total Amount : ' . $totalAmount);

                $this->event_order_updated($last_id, $this->user_id);

                // Transaction start
                $this->DB->trans_start();
                try {
                    $buytrade = $this->CI->WsServer_model->get_order($last_id);

                    log_message("debug", "Start : Buyer Hold balance ");

                    $this->CI->WsServer_model->get_credit_hold_balance_from_balance_new($buytrade->user_id, $secondary_coin_id, $totalAmount);

                    log_message("debug", "End : Buyer Hold balance ");
                    $exchangeType = $this->CI->WsServer_model->get_exchange_type_by_coinpair_id($coinpair_id);
                    log_message("debug", "Exchange to use : " . $exchangeType);

                    if ($exchangeType == 'BINANCE') {
                        // No seller found in Popex
                        // Find out on Binance
                        log_message('debug', '-------------BEGIN BINANCE----------------------------');
                        // log_message('debug', 'No Seller found forward trade to Binance...');

                        $binanceExecuted = $this->binance_buy_trade($coin_details, $buytrade, 'LIMIT', $buytrade->id);
                        if ($binanceExecuted === true) {
                            log_message('debug', 'Trade executed :)');
                        } else {
                            log_message('debug', 'Could not execute');
                            throw new \Exception("Could not executed");
                        }
                        log_message('debug', '--------------ENDING BINANCE---------------------------');
                    } else if ($exchangeType == 'POPEX') {
                        log_message('debug', '-------------BEGIN POPEX----------------------------');

                        $sellers = $this->CI->WsServer_model->get_sellers($coinpair_id, $price);

                        if ($sellers) {

                            log_message('debug', 'Popex Sellers found');

                            // BUYER  : P_UP S_DN
                            // SELLER : P_DN S_UP
                            foreach ($sellers as $key => $selltrade) {

                                $buytrade = $this->CI->WsServer_model->get_order($last_id);
                                $this->_do_buy_trade($buytrade, $selltrade);
                            }
                        } else {
                            log_message('debug', 'Popex Sellers not found');
                        }

                        log_message('debug', '-------------ENDING POPEX----------------------------');
                    }


                    // Transaction end
                    $this->DB->trans_complete();

                    $trans_status = $this->DB->trans_status();

                    if ($trans_status == FALSE) {
                        $this->DB->trans_rollback();

                        $tadata = array(
                            'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                        );
                        $this->CI->WsServer_model->update_order($last_id, $tadata);

                        $data['isSuccess'] = false;
                        $data['msg_code'] = 'something_went_wrong';
                        $data['message'] = 'Something went wrong.';
                        return $data;
                    } else {
                        $this->DB->trans_commit();
                        log_message('debug', '===========LIMIT BUY ORDER FINISHED===========');

                        $this->event_order_updated($last_id, $this->user_id);
                        $this->event_coinpair_updated($coinpair_id);


                        $data['isSuccess'] = true;
                        $data['msg_code'] = 'buy_order_successfully_placed';
                        $data['message'] = 'Buy order successfully placed.';
                        $data['order'] = $this->CI->WsServer_model->get_order($last_id);
                        $data['executed_seller_orders'] = $this->executedSellerOrders;

                        return $data;
                    }
                } catch (\Exception $e) {
                    $this->DB->trans_rollback();
                    log_message('error', '===========LIMIT BUY ORDER FAILED===========');

                    $tadata = array(
                        'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                    );
                    $this->CI->WsServer_model->update_order($last_id, $tadata);

                    $this->event_order_updated($last_id, $this->user_id);

                    $data['isSuccess'] = false;
                    $data['msg_code'] = 'something_went_wrong';
                    $data['message'] = 'Something went wrong.';
                    return $data;
                }
            } else {

                $data['isSuccess'] = false;
                $data['msg_code'] = 'trade_could_not_submitted';
                $data['message'] = 'Trade could not submitted.';
                return $data;
            }
        } else {

            log_message('debug', 'Insufficient balance.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'insufficient_balance';
            $data['message'] = 'Insufficient balance.';
            return $data;
        }
    }

    public function _market($coinpair_id, $qty, $user_id): array
    {

        $data = [
            'isSuccess' => true,
            'message' => '',
            'trade_type' => PopulousWSSConstants::TRADE_TYPE_MARKET,
        ];

        $this->user_id = $user_id;

        $coinpair_id = intval($coinpair_id);

        if ($this->user_id == null) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'user_could_not_found';
            $data['message'] = 'User could not found.';
            return $data;
        }

        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coinpair_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'invalid_pair';
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'buy_amount_is_invalid';
            $data['message'] = 'Buy amount is invalid.';
            return $data;
        }

        // $qty = $this->_convert_to_decimals($qty);

        // PPT_USDT
        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

        $primary_coin_symbol = $this->CI->WsServer_model->get_coin_id_of_symbol($primary_coin_id);

        // Check balance
        $balance_prim   = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);
        $balance_sec    = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);

        // if ($this->_safe_math_condition_check(" $balance_sec->balance  <= 0 ")) {
        if ($this->DM->isZeroOrNegative($balance_sec->balance)) {

            $data['isSuccess'] = false;
            $data['msg_code'] = 'insufficient_balance';
            $data['message'] = 'Insufficient balance.';
            return $data;
        }


        $last_id = $this->create_market_order('BUY', $qty, $coinpair_id, $this->user_id);

        if (!$last_id) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'could_not_submit_order';
            $data['message'] = 'Could not submit order';
            return $data;
        }

        $buytrade = $this->CI->WsServer_model->get_order($last_id);

        $exchangeType = $this->CI->WsServer_model->get_exchange_type_by_coinpair_id($coinpair_id);

        log_message('debug', 'Exchange Type');
        log_message('debug', strtoupper($exchangeType));


        if ($exchangeType == 'BINANCE') {
            log_message('debug', '-------------BEGIN BINANCE----------------------------');

            try {
                $binanceExecuted = $this->binance_buy_trade($coin_details, $buytrade, 'MARKET', $buytrade->id);
                if ($binanceExecuted === true) {
                    log_message('debug', 'Trade executed :)');
                } else {
                    log_message('debug', 'Could not execute');
                    throw new \Exception("Could not executed");
                }
            } catch (\Exception $e) {
                $tadata = array(
                    'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                );
                $this->CI->WsServer_model->update_order($last_id, $tadata);
                $this->event_order_updated($last_id, $this->user_id);


                $data['isSuccess'] = false;
                $data['msg_code'] = 'something_went_wrong';
                $data['message'] = 'Something went wrong.';
            }

            log_message('debug', '--------------ENDING BINANCE---------------------------');
        } else if ($exchangeType == 'POPEX') {
            log_message('debug', '-------------BEGIN POPEX----------------------------');

            $count_sell_orders = $this->CI->WsServer_model->count_sell_orders_by_coin_id($coinpair_id);

            if ($count_sell_orders < 1) {
                // No sell orders available, use initial price
                $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);
            } else {
                $highest_seller_price = $this->CI->WsServer_model->get_highest_price_in_seller($coinpair_id);
                if ($highest_seller_price != null) {
                    $last_price = $highest_seller_price;
                }
            }

            // $totalAmount = $this->_safe_math(" $last_price * $qty ");
            $totalAmount = $this->DM->safe_multiplication([$last_price, $qty]);
            $remaining_qty = $qty;


            // if ($this->_safe_math_condition_check(" $balance_sec->balance  < $totalAmount ")) {
            if ($this->DM->isLessThan($balance_sec->balance, $totalAmount)) {

                // $maximumBuy = $this->_safe_math(" $balance_sec->balance / $last_price ");
                $maximumBuy = $this->DM->safe_division([$balance_sec->balance, $last_price]);

                $data['isSuccess'] = false;
                $data['maximumBuy'] = $maximumBuy;
                $data['primary_coin_symbol'] = $primary_coin_symbol;
                $data['last_price'] = $last_price;
                $data['msg_code'] = 'maximum_you_can_buy';
                $data['message'] = "Maximum $maximumBuy $primary_coin_symbol you can buy @ price $last_price.";
            } else {

                try {
                    $sellers = $this->CI->WsServer_model->get_sellers($coinpair_id); // No price need to provide for Market buyers

                    foreach ($sellers as $selltrade) {
                        $this->DB->trans_start();

                        // $max_buy_qty = $this->_safe_math(" LEAST( $selltrade->bid_qty_available, $remaining_qty ) ");
                        $max_buy_qty = $this->DM->smallest($selltrade->bid_qty_available, $remaining_qty);

                        // $totalAmount = $this->_safe_math(" $selltrade->bid_price * $max_buy_qty ");
                        $totalAmount = $this->DM->safe_multiplication([$selltrade->bid_price, $max_buy_qty]);

                        $buytrade = $this->CI->WsServer_model->get_order($last_id);

                        $this->_do_buy_trade($buytrade, $selltrade);

                        // Transaction end
                        $this->DB->trans_complete();

                        $trans_status = $this->DB->trans_status();

                        if ($trans_status == FALSE) {
                            $this->DB->trans_rollback();

                            $tadata = array(
                                'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                            );
                            $this->CI->WsServer_model->update_order($last_id, $tadata);

                            throw new \Exception("something_went_wrong");
                        } else {
                            $this->DB->trans_commit();

                            $this->event_order_updated($last_id, $this->user_id);
                            $this->event_order_updated($selltrade->id, $selltrade->user_id);
                            $this->event_coinpair_updated($coinpair_id);

                            $remaining_qty = $this->DM->safe_minus([$remaining_qty, $max_buy_qty]);

                            if ($this->DM->isZeroOrNegative($remaining_qty)) {
                                // ALL QTY BOUGHT
                                break; // Come out of for loop everything is bought
                            }

                            // Updating SL sell orders status and make them available if price changed
                            $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);
                        }
                    }

                    $this->event_order_updated($last_id, $this->user_id);
                    $this->event_coinpair_updated($coinpair_id);

                    // Create new order if qty remained
                    if ($this->DM->isGreaterThan($remaining_qty, 0)) {

                        log_message('debug', "**********************************");
                        log_message('debug', "BUY FAILURE because of NO LIQUDITY");
                        log_message('debug', "Failed Remaining qty " . $remaining_qty);

                        // Cancel the order if, qty is still remaining
                        $buyupdate = ['status' => PopulousWSSConstants::BID_FAILED_STATUS];

                        $this->CI->WsServer_model->update_order($last_id, $buyupdate);

                        $data['isSuccess'] = false;
                        $data['msg_code'] = 'could_not_buy_remaining_qty';
                        $data['remaining_qty'] = $remaining_qty;
                        $data['message'] = "Could not buy remaining $remaining_qty Qty.";
                        log_message('debug', "**********************************");

                        $this->event_order_updated($last_id, $this->user_id);
                        $this->event_coinpair_updated($coinpair_id);
                    } else {

                        $this->event_order_updated($last_id, $this->user_id);
                        $this->event_coinpair_updated($coinpair_id);

                        $data['isSuccess'] = true;
                        $data['all_qty'] = $this->_format_number($qty, $coin_details->primary_decimals);
                        $data['msg_code'] = 'all_qty_bought_successfully';
                        $data['message'] = 'All ' . $this->_format_number($qty, $coin_details->primary_decimals) . ' bought successfully';
                        $data['order'] = $this->CI->WsServer_model->get_order($last_id);
                        $data['executed_seller_orders'] = $this->executedSellerOrders;
                    }
                } catch (\Exception $e) {
                    $this->DB->trans_rollback();

                    $tadata = array(
                        'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                    );
                    $this->CI->WsServer_model->update_order($last_id, $tadata);
                    $this->event_order_updated($last_id, $this->user_id);

                    $data['isSuccess'] = false;
                    $data['msg_code'] = 'something_went_wrong';
                    $data['message'] = 'Something went wrong.';
                }
            }


            log_message('debug', '-------------ENDING POPEX----------------------------');
        }

        return $data;
    }

    public function _stop_limit($coinpair_id, $qty, $stop, $limit, $user_id): array
    {
        $data = [
            'isSuccess' => true,
            'message' => '',
            'trade_type' => PopulousWSSConstants::TRADE_TYPE_STOP_LIMIT,
        ];

        $this->user_id = $this->_get_user_id($auth);

        if ($this->user_id == null) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'user_could_not_found';
            $data['message'] = 'User could not found.';
            return $data;
        }

        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coinpair_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'invalid_pair';
            $data['message'] = 'Invalid pair';
            return $data;
        }


        $exchangeType = $this->CI->WsServer_model->get_exchange_type_by_coinpair_id($coinpair_id);
        log_message("debug", "Exchange to use : " . $exchangeType);

        if ($exchangeType != 'POPEX') {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'no_sl_order_supported';
            $data['message'] = 'Stop Limit order not supported';
            return $data;
        }
        /**
         *
         * AMOUNT : PRIMARY
         * PRICE : SECONDARY
         */

        if ($this->_validate_secondary_value_decimals($stop, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'buy_stop_price_invalid';
            $data['message'] = 'Buy Stop price invalid.';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($limit, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'buy_limit_price_invalid';
            $data['message'] = 'Buy Limit price invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'buy_amount_is_invalid';
            $data['message'] = 'Buy amount invalid.';
            return $data;
        }

        // $qty = $this->_convert_to_decimals($qty);
        // $stop = $this->_convert_to_decimals($stop);
        // $limit = $this->_convert_to_decimals($limit);

        $is_take_profit = false;
        $is_stop_loss = false;

        $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);

        // if ($this->_safe_math_condition_check(" $stop >= $last_price ")) {
        if ($this->DM->isGreaterThanOrEqual($stop, $last_price)) {


            $is_take_profit = true;
            $is_stop_loss = false;
            // } else if ($this->_safe_math_condition_check(" $stop <= $last_price ")) {
        } else if ($this->DM->isLessThanOrEqual($stop, $last_price)) {

            $is_take_profit = false;
            $is_stop_loss = true;
        }

        $condition = $is_take_profit ? '>=' : '<=';

        // Create new open order
        $open_date = date('Y-m-d H:i:s');

        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

        $user_id = $this->user_id;

        // Check balance
        $balance_sec = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $user_id);

        $totalAmount = $this->DM->safe_multiplication([$qty, $limit]);


        $availableAmount = $balance_sec->balance;

        // if ($this->_safe_math_condition_check(" $availableAmount < $totalAmount ")) {
        if ($this->DM->isLessThan($availableAmount, $totalAmount)) {
            // Low balance
            $qtyNeeded = $this->_safe_math(" $totalAmount - $availableAmount ");
            $data['isSuccess'] = false;
            $data['qtyNeeded'] = $qtyNeeded;
            $data['msg_code'] = 'you_have_insufficient_balance_more_qty_needed_to_create_an_order';
            $data['message'] = "You have insufficient balance, More $qtyNeeded needed to create an order.";
            return $data;
        }

        $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);

        $last_id = $this->create_sl_order('BUY', $qty, $condition, $limit, $stop, $coinpair_id, $this->user_id);

        /**
         *
         * The stop price is simply the price that triggers a limit order, and the limit price is the specific price of the limit order that was triggered.
         * This means that once your stop price has been reached, your limit order will be immediately placed on the order book.
         *
         */

        if ($last_id) {
            try {
                // Transaction start
                $this->DB->trans_start();
                // BUYER : HOLD SECONDARY COIN
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $totalAmount);

                // Transaction end
                $this->DB->trans_complete();

                $trans_status = $this->DB->trans_status();

                if ($trans_status == FALSE) {
                    $this->DB->trans_rollback();
                    throw new \Exception('something_went_wrong');
                } else {
                    $this->DB->trans_commit();

                    $data['isSuccess'] = true;
                    $data['msg_code'] = 'stop_limit_order_has_been_placed';
                    $data['message'] = 'Stop limit order has been placed';
                    $data['order'] = $this->CI->WsServer_model->get_order($last_id);
                    $data['executed_seller_orders'] = $this->executedSellerOrders;

                    $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);
                    $this->event_order_updated($last_id, $this->user_id);
                    $this->event_coinpair_updated($coinpair_id);
                }
            } catch (\Exception $e) {
                $this->DB->trans_rollback();

                $tadata = array(
                    'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                );
                $this->CI->WsServer_model->update_order($last_id, $tadata);

                $data['isSuccess'] = false;
                $data['msg_code'] = 'something_went_wrong';
                $data['message'] = 'Something went wrong.';
            }
        } else {
            $data['isSuccess'] = false;
            $data['msg_code'] = 'could_not_create_order';
            $data['message'] = 'Could not create order';
        }

        return $data;
    }
}
