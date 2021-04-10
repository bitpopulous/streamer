<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Trade\Trade;

class Buy extends Trade
{
    public function __construct(ServerHandler $server)
    {
        parent::__construct($server);
        $this->wss_server = $server;
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

                $referralCommission = $this->DM->safe_division([$buyerTotalFees, $referralCommissionPercentRate]);
                log_message("debug", "Referral Commission : " . $referralCommission);

                $adminGetsAfterCommission = $this->DM->safe_minus([$buyerTotalFees, $referralCommission]);

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

                $referralCommission = $this->DM->safe_division([$sellerTotalFees, $referralCommissionPercentRate]);
                log_message("debug", "Referral Commission : " . $referralCommission);

                $adminGetsAfterCommission = $this->DM->safe_minus([$sellerTotalFees, $referralCommission]);


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

                if ($this->DM->isGreaterThan($buytrade->price, 0)) {
                    $tPrice = $this->DM->safe_add([$buytrade->price, $trade_price]);
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
                $buyupdate['price'] = $averagePrice;
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
                // EVENTS for both party
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $buytrade->id,
                        'user_id' => $buytrade->user_id,
                    ]
                );
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $selltrade->id,
                        'user_id' => $selltrade->user_id,
                    ]
                );

                // EVENT for single trade

                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_TRADE_CREATED,
                    [
                        'log_id' => $log_id,
                    ]
                );

                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_MARKET_SUMMARY,
                    []
                );
            } catch (\Exception $e) {
            }

            return true;
        }
    }

    /**
     * @return bool
     */
    public function _limit($coinpair_id, $qty, $price, $auth): array
    {
        $data = [
            'isSuccess' => true,
            'message' => '',
            'trade_type' => PopulousWSSConstants::TRADE_TYPE_LIMIT,
        ];

        $this->user_id = $this->_get_user_id($auth);

        if ($this->user_id == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'User could not found.';
            return $data;
        }

        $coinpair_id = intval($coinpair_id);
        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coinpair_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($price, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy price is invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy amount is invalid.';
            return $data;
        }

        $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);
        $totalAmount = $this->DM->safe_multiplication([$price, $qty]);

        $balance_secondary     = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);

        // Check balance first
        if ($this->DM->isGreaterThanOrEqual($balance_secondary->balance, $totalAmount)) {

            $last_id  = $this->create_limit_order('BUY', $qty, $price, $coinpair_id, $this->user_id);

            if ($last_id) {

                log_message('debug', '===========LIMIT BUY ORDER STARTED===========');

                log_message('debug', 'Order Id : ' . $last_id);
                log_message('debug', 'Price : ' . $price);
                log_message('debug', 'Qty : ' . $qty);
                log_message('debug', 'Total Amount : ' . $totalAmount);

                // Event for order creator
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );
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
                        $data['message'] = 'Something went wrong.';
                        return $data;
                    } else {
                        $this->DB->trans_commit();
                    }
                } catch (\Exception $e) {
                    $this->DB->trans_rollback();
                    log_message('error', '===========LIMIT BUY ORDER FAILED===========');

                    $tadata = array(
                        'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                    );
                    $this->CI->WsServer_model->update_order($last_id, $tadata);

                    $data['isSuccess'] = false;
                    $data['message'] = 'Something went wrong.';
                    return $data;
                }

                log_message('debug', '===========LIMIT BUY ORDER FINISHED===========');

                // Send event to every client
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $coinpair_id,
                    ]
                );


                // Event for order creator
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );

                $data['isSuccess'] = true;
                $data['message'] = 'Buy order successfully placed.';

                return $data;
            } else {

                $data['isSuccess'] = false;
                $data['message'] = 'Trade could not submitted.';
                return $data;
            }
        } else {

            log_message('debug', 'Insufficient balance.');
            $data['isSuccess'] = false;
            $data['message'] = 'Insufficient balance.';
            return $data;
        }
    }

    public function _market($coinpair_id, $qty, $auth): array
    {

        $data = [
            'isSuccess' => true,
            'message' => '',
            'trade_type' => PopulousWSSConstants::TRADE_TYPE_MARKET,
        ];

        $this->user_id = $this->_get_user_id($auth);

        $coinpair_id = intval($coinpair_id);

        if ($this->user_id == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'User could not found.';
            return $data;
        }

        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coinpair_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
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
            $data['message'] = 'Insufficient balance.';
            return $data;
        }


        $last_id = $this->create_market_order('BUY', $qty, $coinpair_id, $this->user_id);

        if (!$last_id) {
            $data['isSuccess'] = false;
            $data['message'] = 'Could not submit order';
            return $data;
        }

        $buytrade = $this->CI->WsServer_model->get_order($last_id);

        $exchangeType = $this->CI->WsServer_model->get_exchange_type_by_coinpair_id($coinpair_id);

        log_message('debug', 'Exchange Type');
        log_message('debug', strtoupper($exchangeType));

        if ($exchangeType == 'BINANCE') {
            log_message('debug', '-------------BEGIN BINANCE----------------------------');

            $binanceExecuted = $this->binance_buy_trade($coin_details, $buytrade, 'MARKET', $buytrade->id);
            if ($binanceExecuted === true) {
                log_message('debug', 'Trade executed :)');
            } else {
                log_message('debug', 'Could not execute');
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


            // if ($this->_safe_math_condition_check(" $balance_sec->balance  < $totalAmount ")) {
            if ($this->DM->isLessThan($balance_sec->balance, $totalAmount)) {

                // $maximumBuy = $this->_safe_math(" $balance_sec->balance / $last_price ");
                $maximumBuy = $this->DM->safe_division([$balance_sec->balance, $last_price]);

                $data['isSuccess'] = false;
                $data['message'] = "Maximum $maximumBuy $primary_coin_symbol you can buy @ price $last_price.";
                return $data;
            }


            $sellers = $this->CI->WsServer_model->get_sellers($coinpair_id); // No price need to provide for Market buyers
            $remaining_qty = $qty;

            foreach ($sellers as $selltrade) {

                // $max_buy_qty = $this->_safe_math(" LEAST( $selltrade->bid_qty_available, $remaining_qty ) ");
                $max_buy_qty = $this->DM->smallest($selltrade->bid_qty_available, $remaining_qty);

                // $totalAmount = $this->_safe_math(" $selltrade->bid_price * $max_buy_qty ");
                $totalAmount = $this->DM->safe_multiplication([$selltrade->bid_price, $max_buy_qty]);

                /**
                 * 
                 * Calculate fees
                 */
                // $totalFees = $this->_calculateTotalFeesAmount($selltrade->bid_price, $max_buy_qty, $coinpair_id, 'BUY');


                // Transaction start
                $this->DB->trans_start();
                try {
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

                        $data['isSuccess'] = false;
                        $data['message'] = 'Something went wrong.';
                        return $data;
                    } else {
                        $this->DB->trans_commit();
                    }
                } catch (\Exception $e) {
                    $this->DB->trans_rollback();

                    $tadata = array(
                        'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                    );
                    $this->CI->WsServer_model->update_order($last_id, $tadata);

                    $data['isSuccess'] = false;
                    $data['message'] = 'Something went wrong.';
                    return $data;
                }
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );

                // Event for order creator
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );


                // $remaining_qty = $this->_safe_math(" $remaining_qty - $max_buy_qty ");
                $remaining_qty = $this->DM->safe_minus([$remaining_qty, $max_buy_qty]);

                // if ($this->_safe_math_condition_check(" $remaining_qty <= 0 ")) {
                if ($this->DM->isZeroOrNegative($remaining_qty)) {
                    // ALL QTY BOUGHT
                    break; // Come out of for loop everything is bought
                }

                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $coinpair_id,
                    ]
                );

                // Updating SL sell orders status and make them available if price changed
                $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);
            } // Sellers loop ends

            // Create new order if qty remained
            if ($this->DM->isGreaterThan($remaining_qty, 0)) {

                $data['message'] = "Could not buy remaining $remaining_qty Qty.";
                return $data;
            } else {
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $coinpair_id,
                    ]
                );
                $data['isSuccess'] = true;
                $data['message'] = 'All ' . $this->_format_number($qty, $coin_details->primary_decimals) .
                    ' bought successfully';
                return $data;
            }
            log_message('debug', '-------------ENDING POPEX----------------------------');
        }
    }

    public function _stop_limit($coinpair_id, $qty, $stop, $limit, $auth): array
    {
        $data = [
            'isSuccess' => true,
            'message' => '',
            'trade_type' => PopulousWSSConstants::TRADE_TYPE_STOP_LIMIT,
        ];

        $this->user_id = $this->_get_user_id($auth);

        if ($this->user_id == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'User could not found.';
            return $data;
        }

        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coinpair_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }
        /**
         *
         * AMOUNT : PRIMARY
         * PRICE : SECONDARY
         */

        if ($this->_validate_secondary_value_decimals($stop, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy Stop price invalid.';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($limit, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy Limit price invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
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

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

        $user_id = $this->user_id;

        // Check balance
        $balance_sec = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $user_id);

        // $totalAmount = $this->_safe_math(" $qty * $limit ");
        $totalAmount = $this->DM->safe_multiplication([$qty, $limit]);


        $availableAmount = $balance_sec->balance;

        // if ($this->_safe_math_condition_check(" $availableAmount < $totalAmount ")) {
        if ($this->DM->isLessThan($availableAmount, $totalAmount)) {
            // Low balance
            $qtyNeeded = $this->_safe_math(" $totalAmount - $availableAmount ");
            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance, More $qtyNeeded needed to create an order.";
            return $data;
        }

        // Enought amount to place order without checking fees

        $totalFees   = $this->_calculateTotalFeesAmount($limit, $qty, $coinpair_id, 'BUY');

        $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);

        // Create one function for holding funds


        // Transaction start
        $this->DB->trans_start();
        try {
            $last_id = $this->create_sl_order('BUY', $qty, $condition, $limit, $stop, $coinpair_id, $this->user_id);

            // Updating SL sell orders status and make them available if price changed
            $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);

            // Transaction end
            $this->DB->trans_complete();

            $trans_status = $this->DB->trans_status();

            if ($trans_status == FALSE) {
                $this->DB->trans_rollback();

                if ($last_id) {
                    $tadata = array(
                        'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                    );
                    $this->CI->WsServer_model->update_order($last_id, $tadata);
                }

                $data['isSuccess'] = false;
                $data['message'] = 'Something went wrong.';
                return $data;
            } else {
                $this->DB->trans_commit();
            }
        } catch (\Exception $e) {
            $this->DB->trans_rollback();

            if ($last_id) {
                $tadata = array(
                    'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                );
                $this->CI->WsServer_model->update_order($last_id, $tadata);
            }

            $data['isSuccess'] = false;
            $data['message'] = 'Something went wrong.';
            return $data;
        }
        /**
         *
         * The stop price is simply the price that triggers a limit order, and the limit price is the specific price of the limit order that was triggered.
         * This means that once your stop price has been reached, your limit order will be immediately placed on the order book.
         *
         */

        if ($last_id) {
            // Transaction start
            $this->DB->trans_start();
            try {
                // BUYER : HOLD SECONDARY COIN
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $totalAmount);

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
                    $data['message'] = 'Something went wrong.';
                    return $data;
                } else {
                    $this->DB->trans_commit();
                }
            } catch (\Exception $e) {
                $this->DB->trans_rollback();

                $tadata = array(
                    'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                );
                $this->CI->WsServer_model->update_order($last_id, $tadata);

                $data['isSuccess'] = false;
                $data['message'] = 'Something went wrong.';
                return $data;
            }

            // Event for order creator
            $this->wss_server->_event_push(
                PopulousWSSConstants::EVENT_ORDER_UPDATED,
                [
                    'order_id' => $last_id,
                    'user_id' => $this->user_id,
                ]
            );

            // Event for order creator
            $this->wss_server->_event_push(
                PopulousWSSConstants::EVENT_ORDER_UPDATED,
                [
                    'order_id' => $last_id,
                    'user_id' => $this->user_id,
                ]
            );

            $data['isSuccess'] = true;
            $data['message'] = 'Stop limit order has been placed';
            return $data;
        } else {
            $data['isSuccess'] = false;
            $data['message'] = 'Could not create order';
            return $data;
        }
    }
}
