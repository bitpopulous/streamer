<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Trade\Trade;

class Sell extends Trade
{
    protected $wss_server;

    public function __construct(ServerHandler $server)
    {
        parent::__construct($server);
        $this->wss_server = $server;
    }

    private function _do_sell_trade($selltrade, $buytrade)
    {

        if (
            $buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS &&
            $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS
        ) {

            log_message('debug', '--------DO SELL START--------');


            $coinpair_id = intval($selltrade->coinpair_id);
            $symbol = $this->CI->WsServer_model->get_coinpair_symbol_of_coinpairId($coinpair_id);
            $symbol =  str_replace('_', '', strtoupper($symbol));

            log_message("debug", "Coin pair : " . $symbol);

            $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
            $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

            if ($selltrade->is_market) {
                $trade_price = $buytrade->bid_price;
            } else {
                $trade_price    = $this->DM->biggest($selltrade->bid_price, $buytrade->bid_price);
            }


            $trade_qty      = $this->DM->smallest($selltrade->bid_qty_available, $buytrade->bid_qty_available);
            $trade_amount   = $this->DM->safe_multiplication([$trade_qty, $trade_price]);

            log_message('debug', 'Trade Qty : ' . $trade_qty);
            log_message('debug', 'Trade Price : ' . $trade_price);
            log_message('debug', 'Trade Amount : ' . $trade_amount);


            /**
             * 
             * SELLET will PAY $trade_qty & GET $trade_amount
             * BUYER will PAY $trade_amount & GET $trade_qty
             */

            // BUYER AND SELLER BALANCE UPDATE HERE 

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay        = $trade_qty;

            $seller_receiving_amount = $trade_amount;
            $buyer_will_pay          = $trade_amount;


            /**
             * FEES Deduction
             */

            /**
             * Here buyer always be a TAKER and seller as a MAKER
             */
            $buyerPercent   = $this->_getMakerFees($buytrade->user_id, $primary_coin_id);
            $buyerTotalFees = $this->_calculateFeesAmount($buyer_receiving_amount, $buyerPercent);

            log_message('debug', 'Buyer Fees Percent : ' . $buyerPercent);
            log_message('debug', 'Buyer Total Fees : ' . $buyerTotalFees);

            $sellerPercent   = $this->_getTakerFees($this->user_id, $primary_coin_id);
            $sellerTotalFees = $this->_calculateFeesAmount($seller_receiving_amount, $sellerPercent);

            log_message('debug', 'Seller Fees Percent : ' . $sellerPercent);
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


            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount_after_fees, $buyer_will_pay);


            if ($selltrade->is_market) {
                // NO HOLD USE : TRUE, Direct deduction & credit
                // BUYER WILL PAY SECONDARY COIN AMOUNT
                $this->CI->WsServer_model->get_debit_balance_new($selltrade->user_id, $primary_coin_id, $seller_will_pay);

                // BUYER WILL GET PRIMARY COIN AMOUNT
                $this->CI->WsServer_model->get_credit_balance_new($selltrade->user_id, $secondary_coin_id, $seller_receiving_amount_after_fees);
            } else {

                $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amount_after_fees);
            }


            $buyer_av_bid_amount_after_trade = $this->DM->safe_minus([$buytrade->amount_available, $trade_amount]);
            $buyer_av_qty_after_trade = $this->DM->safe_minus([$buytrade->bid_qty_available, $trade_qty]);
            $seller_av_qty_after_trade = $this->DM->safe_minus([$selltrade->bid_qty_available, $trade_qty]);

            $buyer_qty_fulfilled = $this->DM->safe_minus([$buytrade->bid_qty_available, $trade_qty]);
            $seller_qty_fulfilled = $this->DM->safe_minus([$selltrade->bid_qty_available, $trade_qty]);

            $is_buyer_qty_fulfilled = $this->DM->isZero($buyer_qty_fulfilled);
            $is_seller_qty_fulfilled = $this->DM->isZero($seller_qty_fulfilled);

            log_message("debug", "buyer_av_bid_amount_after_trade : " . $buyer_av_bid_amount_after_trade);
            log_message("debug", "buyer_av_qty_after_trade : " . $buyer_av_qty_after_trade);
            log_message("debug", "seller_av_qty_after_trade : " . $seller_av_qty_after_trade);
            log_message("debug", "buyer_qty_fulfilled : " . $buyer_qty_fulfilled);
            log_message("debug", "seller_qty_fulfilled : " . $seller_qty_fulfilled);
            log_message("debug", "is_buyer_qty_fulfilled : " . $is_buyer_qty_fulfilled ? 'True' : 'False');
            log_message("debug", "is_seller_qty_fulfilled : " . $is_seller_qty_fulfilled ? 'True' : 'False');


            $buyupdate = array(
                'bid_qty_available' => $buyer_av_qty_after_trade,
                'amount_available' => $buyer_av_bid_amount_after_trade,
                'fees_amount' => $this->DM->safe_add([$buytrade->fees_amount, $buyerTotalFees]),
                'status' =>  $is_buyer_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $seller_av_qty_after_trade,
                'fees_amount' => $this->DM->safe_add([$selltrade->fees_amount, $sellerTotalFees]),
                'status' => $is_seller_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );


            if ($selltrade->is_market) {
                // BUYER MARKET
                // 1. Update Average total Amount
                // 2. Update Average Price

                if ($this->DM->isGreaterThan($selltrade->bid_price, 0)) {
                    $tPrice = $this->DM->safe_add([$selltrade->bid_price, $trade_price]);
                    $averagePrice = $this->DM->safe_division([$tPrice, 2]);
                } else {
                    $averagePrice = $trade_price;
                }

                if ($this->DM->isGreaterThan($buytrade->bid_price, 0)) {
                    $tAmount = $this->DM->safe_add([$selltrade->total_amount, $trade_amount]);
                    $averageTotalAmount = $this->DM->safe_division([$tAmount, 2]);
                } else {
                    $averageTotalAmount = $trade_amount;
                }

                $sellupdate['total_amount'] = $averageTotalAmount;
                $sellupdate['bid_price'] = $averagePrice;
            } else {
                $seller_av_bid_amount_after_trade = $this->DM->safe_minus([$selltrade->amount_available, $trade_amount]);
                $sellupdate['amount_available'] = $seller_av_bid_amount_after_trade;
            }

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');
            $success_datetimestamp = strtotime($success_datetime);

            $selltraderlog = array(
                'bid_id' => $selltrade->id,
                'bid_type' => $selltrade->bid_type,
                'complete_qty' => $trade_qty,
                'bid_price' => $trade_price,
                'complete_amount' => $seller_receiving_amount,
                'user_id' => $selltrade->user_id,
                'coinpair_id' => $coinpair_id,
                'success_time' => $success_datetime,
                'fees_amount' => $sellerTotalFees,
                'available_amount' => $selltrade->is_market ? 0 : $seller_av_bid_amount_after_trade, // $seller_available_bid_amount_after_trade,
                'status' => $is_seller_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            // Update coin history
            // $this->CI->WsServer_model->update_coin_history($coinpair_id, $trade_qty, $trade_price);

            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);

            $log_id = $this->CI->WsServer_model->insert_order_log($selltraderlog);

            // Update SL orders and OHLCV
            $this->after_successful_trade($coinpair_id,  $trade_price, $trade_qty, $success_datetimestamp);


            log_message('debug', '--------DO SELL END--------');


            /**
             * =================
             * EVENTS
             * =================
             */

            try {
                // EVENTS for both party
                $this->event_order_updated($buytrade->id, $buytrade->user_id);
                $this->event_order_updated($selltrade->id, $selltrade->user_id);
                $this->event_trade_created($log_id);
                $this->event_market_summary();
            } catch (\Exception $e) {
            }

            return true;
        }
        return false;
    }



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
        $coin_details = $this->CI->WsServer_model->get_coin_pair($coinpair_id);

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

        if ($this->_validate_secondary_value_decimals($price, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell price invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell amount invalid.';
            return $data;
        }

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
        $totalAmount = $this->DM->safe_multiplication([$price, $qty]);

        $balance_primary = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);

        // Check balance
        if ($this->DM->isGreaterThanOrEqual($balance_primary->balance, $qty)) {

            $last_id = $this->create_limit_order('SELL', $qty, $price, $coinpair_id, $this->user_id);

            if ($last_id) {

                log_message('debug', '===========SELL ORDER STARTED===========');

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
                    $selltrade = $this->CI->WsServer_model->get_order($last_id);

                    log_message("debug", "Start : Seller Hold balance ");

                    // SELLER BALANCE P_DN & S_UP
                    $this->CI->WsServer_model->get_credit_hold_balance_from_balance_new($selltrade->user_id, $primary_coin_id, $qty);

                    log_message("debug", "End : Seller Hold balance ");

                    $exchangeType = $this->CI->WsServer_model->get_exchange_type_by_coinpair_id($coinpair_id);
                    log_message("debug", "Exchange to use : " . $exchangeType);

                    if ($exchangeType == 'BINANCE') {
                        // No buyer found in Popex
                        // Find out on Binance
                        log_message('debug', '-------------BEGIN BINANCE----------------------------');
                        log_message('debug', 'No BUYER found forward trade to Binance...');

                        $binanceExecuted = $this->binance_sell_trade($coin_details, $selltrade, 'LIMIT', $selltrade->id);
                        if ($binanceExecuted === true) {
                            log_message('debug', 'Trade executed :)');
                        } else {
                            log_message('debug', 'Could not execute');
                        }
                        log_message('debug', '--------------ENDING BINANCE---------------------------');
                    } else if ($exchangeType == 'POPEX') {

                        log_message('debug', '-------------BEGIN POPEX----------------------------');
                        $buyers = $this->CI->WsServer_model->get_buyers($coinpair_id, $price);
                        // var_dump($buyers);
                        if ($buyers) {

                            log_message('debug', 'Popex Buyers found');

                            foreach ($buyers as $key => $buytrade) {

                                // Provide updated sell trade here
                                $selltrade = $this->CI->WsServer_model->get_order($last_id);

                                // SELLING TO BUYER
                                $this->_do_sell_trade($selltrade, $buytrade);

                                // Updating SL buy order status and make them available if price changed
                                // $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);

                            } // End of buytradequery Loop

                        } else {
                            log_message('debug', 'Popex Buyers not found');
                        }
                        log_message('debug', '--------------ENDING POPEX---------------------------');
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

                    log_message('debug', '===========SELL ORDER FAILED===========');


                    $tadata = array(
                        'status' => PopulousWSSConstants::BID_FAILED_STATUS,
                    );
                    $this->CI->WsServer_model->update_order($last_id, $tadata);

                    $data['isSuccess'] = false;
                    $data['message'] = 'Something went wrong.';
                    return $data;
                }

                log_message('debug', '===========SELL ORDER FINISHED===========');

                // Event for order creator
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );

                // Send event to every client
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $coinpair_id,
                    ]
                );


                $data['isSuccess'] = true;
                $data['message'] = 'Sell order successfully placed.';

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
            $data['message'] = 'Sell amount invalid.';
            return $data;
        }

        // $qty = $this->_convert_to_decimals($qty);

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);


        $balance_prime = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);
        $available_prim_balance = $balance_prime->balance;


        // if ($this->_safe_math_condition_check(" $available_prim_balance  <= 0 ")) {
        if ($this->DM->isZeroOrNegative($available_prim_balance) && $this->DM->isGreaterThanOrEqual($available_prim_balance, $qty) == false) {

            $data['isSuccess'] = false;
            $data['message'] = 'Insufficient balance.';
            return $data;
        }


        $user_id = $this->user_id;

        $last_id = $this->create_market_order('SELL', $qty, $coinpair_id, $this->user_id);

        if (!$last_id) {
            $data['isSuccess'] = false;
            $data['message'] = 'Could not submit order';
            return $data;
        }

        $selltrade = $this->CI->WsServer_model->get_order($last_id);

        $exchangeType = $this->CI->WsServer_model->get_exchange_type_by_coinpair_id($coinpair_id);

        log_message('debug', 'Exchange Type');
        log_message('debug', strtoupper($exchangeType));


        if ($exchangeType == 'BINANCE') {
            log_message('debug', '-------------BEGIN BINANCE----------------------------');

            $binanceExecuted = $this->binance_sell_trade($coin_details, $selltrade, 'MARKET', $selltrade->id);
            if ($binanceExecuted === true) {
                log_message('debug', 'Trade executed :)');
            } else {
                log_message('debug', 'Could not execute');
            }

            log_message('debug', '--------------ENDING BINANCE---------------------------');
        } else if ($exchangeType == 'POPEX') {

            log_message('debug', '-------------BEGIN POPEX----------------------------');

            $buyers = $this->CI->WsServer_model->get_buyers($coinpair_id);
            $remaining_qty = $qty;

            foreach ($buyers as $buytrade) {

                $max_sell_qty = $this->DM->smallest($buytrade->bid_qty_available, $remaining_qty);

                // Transaction start
                $this->DB->trans_start();
                try {
                    $selltrade = $this->CI->WsServer_model->get_order($last_id);

                    // SELLING TO BUYER
                    $this->_do_sell_trade($selltrade, $buytrade);


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

                $this->event_order_updated($last_id, $this->user_id);
                $this->event_order_updated($buytrade->id, $buytrade->user_id);
                $this->event_coinpair_updated($coinpair_id);

                $remaining_qty = $this->DM->safe_minus([$remaining_qty, $max_sell_qty]);

                if ($this->DM->isZeroOrNegative($remaining_qty)) {
                    // ALL QTY SOLD
                    break; // Come out of for loop everything is sold
                }
            }



            // if ($this->_safe_math_condition_check("$remaining_qty > 0 ")) {
            if ($this->DM->isGreaterThan($remaining_qty, 0)) {

                log_message('debug', "**********************************");
                log_message('debug', "SELF SELL CANCELLATION");
                log_message('debug', "Cancelling Remaining qty " . $remaining_qty);
                // Cancel the order if, qty is still remaining
                $sellupdate = ['status' => PopulousWSSConstants::BID_CANCELLED_STATUS];
                $this->CI->WsServer_model->update_order($last_id, $sellupdate);

                $data['message'] = "Could not buy remaining $remaining_qty Qty.";
                log_message('debug', "**********************************");

                $this->event_order_updated($last_id, $this->user_id);
                $this->event_coinpair_updated($coinpair_id);

                return $data;
            } else {
                $this->event_order_updated($last_id, $this->user_id);
                $this->event_coinpair_updated($coinpair_id);

                // All quantity bought

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

        $coinpair_id = intval($coinpair_id);
        $coin_details = $this->CI->WsServer_model->get_coin_pair($coinpair_id);
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($stop, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell Stop price invalid.';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($limit, $coin_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell Limit price invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($qty, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell amount invalid.';
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

        $balance_prim = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);

        // if ($this->_safe_math_condition_check(" $qty > $balance_prim->balance ")) {
        if ($this->DM->isGreaterThan($qty, $balance_prim->balance)) {

            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance.";
            return $data;
        }

        // $totalAmount = $this->_safe_math(" $limit * $qty ");
        $totalAmount = $this->DM->safe_multiplication([$limit, $qty]);

        $totalFees = $this->_calculateTotalFeesAmount($limit, $qty, $coinpair_id, 'SELL');


        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => 'SELL',
            'bid_price' => $limit,
            'bid_price_stop' => $stop,
            'bid_price_limit' => $limit,
            'is_stop_limit' => 1,
            'stop_condition' => $condition,
            'bid_qty' => $qty,
            'bid_qty_available' => $qty,
            'total_amount' => $totalAmount,
            'amount_available' => $totalAmount,
            'coinpair_id' => $coinpair_id,
            'user_id' => $this->user_id,
            'open_order' => $open_date,
            'fees_amount' => $totalFees,
            'status' => PopulousWSSConstants::BID_QUEUED_STATUS,
        );

        $last_id = $this->CI->WsServer_model->insert_order($tadata);


        // Event for order creator
        $this->wss_server->_event_push(
            PopulousWSSConstants::EVENT_ORDER_UPDATED,
            [
                'order_id' => $last_id,
                'user_id' => $this->user_id,
            ]
        );

        if ($last_id) {
            // Transaction start
            $this->DB->trans_start();
            try {
                // BUYER : HOLD SECONDARY COIN
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $primary_coin_id, $qty);

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
            } catch (Exception $e) {
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
