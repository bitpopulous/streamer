<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Common\Auth;

class Trade
{
    use Auth;

    protected $CI;

    protected $maker_discount = 0;
    protected $taker_discount = 0;

    protected $wss_server;
    protected $user_id;
    protected $admin_id;

    public $DM;
    public $DB;

    public function __construct(ServerHandler $server)
    {
        $this->CI = &get_instance();
        $this->wss_server = $server;

        $this->CI->load->model([
            'WsServer_model',
        ]);

        $this->CI->load->library("PopDecimalMath", null, 'decimalmaths');
        $this->CI->load->library('PopexBinance', NULL, 'PopexBinance');

        $this->DM = &$this->CI->decimalmaths;
        $this->DB = $this->CI->db;
        $this->PopexBinace = &$this->CI->PopexBinance;
        $this->admin_id = getenv('ADMIN_USER_ID');
    }



    protected function _referral_user_balance_update($user_id, $coin_id, $amount)
    {

        log_message("debug", "---------------------------------------------");
        log_message("debug", "START : Referral User Balance Update");
        log_message("debug", "Referral User Id : " . $user_id);

        $this->CI->WsServer_model->get_credit_balance_new($user_id, $coin_id, $amount);

        log_message("debug", "END : Referral User Balance Update");
        log_message("debug", "---------------------------------------------");
    }

    public function _buyer_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {

        log_message("debug", "---------------------------------------------");
        log_message("debug", "START : Buyer Balance Update");
        // P_DN
        $this->CI->WsServer_model->get_debit_hold_balance_new($user_id, $secondary_coin_id, $secondary_amount);
        // S_UP
        $this->CI->WsServer_model->get_credit_balance_new($user_id, $primary_coin_id, $primary_amount);

        log_message("debug", "END : Buyer Balance Update");
        log_message("debug", "---------------------------------------------");
    }

    public function _seller_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {

        log_message("debug", "---------------------------------------------");
        log_message("debug", "START : Seller Balance Update");

        // P_DN
        $this->CI->WsServer_model->get_debit_hold_balance_new($user_id, $primary_coin_id, $primary_amount);
        // S_UP
        $this->CI->WsServer_model->get_credit_balance_new($user_id, $secondary_coin_id, $secondary_amount);

        log_message("debug", "END : Seller Balance Update");
        log_message("debug", "---------------------------------------------");
    }

    protected function _format_number($number, $decimals)
    {
        if (!$number) {
            $number = 0;
        }

        return number_format((float) round($number, $decimals, PHP_ROUND_HALF_DOWN), $decimals, '.', '');
    }

    protected function _convert_to_decimals($number)
    {
        return $this->CI->WsServer_model->convert_long_decimals($number);
    }

    protected function _safe_math_condition_check($q)
    {
        return $this->CI->WsServer_model->condition_check($q);
    }

    protected function _safe_math($q)
    {
        return $this->CI->WsServer_model->calculation_math($q);
    }

    protected function _validate_primary_value_decimals($number, $decimals)
    {

        $a = $this->_validate_decimals($number, $decimals);
        $b = $this->DM->isZeroOrNegative($number) == false;
        $c = $this->_validate_decimal_range($number, $decimals);

        return  $a && $b && $c;
    }

    protected function _validate_secondary_value_decimals($number, $decimals)
    {

        $a = $this->_validate_decimals($number, $decimals);
        $b = $this->DM->isZeroOrNegative($number) == false;
        $c = $this->_validate_decimal_range($number, $decimals);

        return  $a && $b && $c;
    }

    protected function _validate_decimal_range($number, $decimals)
    {

        $_max = $this->max_value($decimals);
        $_min = $this->min_value($decimals);

        $isGreater = $this->DM->isGreaterThanOrEqual($number, $_min);
        $isLess = $this->DM->isLessThanOrEqual($number, $_max);

        return $isGreater && $isLess;
    }

    protected function _validate_decimals($number, $decimals)
    {

        $number = (string) $number;
        $decimals = intval($decimals);

        $l = (int) strlen(substr(strrchr($number, "."), 1));
        if ($l <= $decimals) return TRUE;

        return FALSE;
    }

    public function max_value($decimals)
    {

        $_str = '';

        for ($i = 1; $i <= 8; $i++) {
            $_str .= '9';
        }
        $_str .= '.';
        for ($j = 1; $j <= (int) $decimals; $j++) {
            $_str .= '9';
        }

        return $_str;
    }

    public function min_value($decimals)
    {

        $_str = '0.';

        for ($i = 1; $i < $decimals; $i++) {
            $_str .= '0';
        }

        $_str .= '1';

        return $_str;
    }


    /**
     * ==========================
     * FEES Calculations
     * ==========================
     */


    /**
     * Return Maker fees according to fees stack
     */
    public function _getMakerFees($userId)
    {

        /*
        Note : As per discussion with JASON we will be using stack maker taker default
        
        $makerFeesPercentRes = $this->CI->WsServer_model->get_fees_by_coin_id('MAKER', $coinId);

        $standardFeesPercent = $makerFeesPercentRes != null ? floatval( $makerFeesPercentRes->fees ) : 0;

        if( $standardFeesPercent == 0 ) return 0;

        */

        /**
         * Getting Stack maker percentage, if eligible
         */
        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages($userId);

        if ($mt['maker'] == 0) return 0;
        return $mt['maker'];
    }


    /**
     * Return Taker fees according to fees stack
     */
    public function _getTakerFees($userId)
    {

        /*
        Note : As per discussion with JASON we will be using stack maker taker default

        $takerFeesPercentRes = $this->CI->WsServer_model->get_fees_by_coin_id('TAKER', $coinId);

        $standardFeesPercent = $takerFeesPercentRes != null ? floatval( $takerFeesPercentRes->fees ) : 0;

        if( $standardFeesPercent == 0 ) return 0;
        */

        /**
         * Getting Stack taker percentage, if eligible
         */
        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages($userId);

        if ($mt['taker'] == 0) return 0;
        return $mt['taker'];
    }


    /**
     * Returns calculated fees in amount
     */
    public function _calculateFeesAmount($totalAmount, $feesPercent)
    {

        $totalFees = 0;

        if ($feesPercent != 0) {
            // $totalFees = $this->_safe_math("  ( $totalAmount * $feesPercent)/100  ");

            $a1 = $this->DM->safe_multiplication([$totalAmount, $feesPercent]);
            $totalFees = $this->DM->safe_division([$a1, 100]);
        } else {
            $totalFees = 0;
        }

        return $totalFees;
    }

    /**
     * Returns total fees require
     */

    public function _calculateTotalFeesAmount($price, $qty, $coinpairId, $tradeType)
    {

        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages($this->user_id);

        $makerDiscountPercent = $mt['maker'];
        $takerDiscountPercent = $mt['taker'];

        if ($tradeType == 'BUY') {
            $orderbookQty = $this->CI->WsServer_model->get_available_qty_in_sell_orders_within_price($price, $coinpairId);
        } else if ($tradeType == 'SELL') {
            $orderbookQty = $this->CI->WsServer_model->get_available_qty_in_buy_orders_within_price($price, $coinpairId);
        }

        log_message('debug', "");
        log_message('debug', "");
        log_message('debug', "___________Calculate Total Fees Amount___________");


        $totalFees = 0;
        $totalExchange = $this->DM->safe_multiplication([$price, $qty]);


        $makerFeesPercent =  $this->_getMakerFees($this->user_id);
        $takerFeesPercent =  $this->_getTakerFees($this->user_id);

        // if( $this->_safe_math_condition_check(" $orderbookQty = 0 ") ){
        if ($this->DM->isZero($orderbookQty)) {
            // NO QTY available in O.B
            // FULL MAKER
            log_message('debug', "-----------------------------------");
            log_message('debug', "FULL MAKER FEES CALCULATIONS");

            $makerFeesPercent    = $this->_getMakerFees($this->user_id);
            $totalFees           = $this->_calculateFeesAmount($totalExchange, $makerFeesPercent);

            log_message('debug', "Maker Fees Percent : " . $makerFeesPercent);
            log_message('debug', "Total Fees : " . $totalFees);
            log_message('debug', "-----------------------------------");

            // }else if ( $this->_safe_math_condition_check(" $orderbookQty >= $qty ") ){
        } else if ($this->DM->isGreaterThanOrEqual($orderbookQty, $qty)) {
            // ALL QTY available in O.B
            // FULL TAKER

            log_message('debug', "-----------------------------------");
            log_message('debug', "FULL TAKER FEES CALCULATIONS");

            $takerFeesPercent    = $this->_getTakerFees($this->user_id);
            $totalFees           = $this->_calculateFeesAmount($totalExchange, $takerFeesPercent);

            log_message('debug', "Taker Fees Percent : " . $takerFeesPercent);
            log_message('debug', "Total Fees : " . $totalFees);
            log_message('debug', "-----------------------------------");
        } else {
            // PARTIAL MAKER & TAKER

            // $maker_qty =  $this->_safe_math(" $qty - $orderbookQty  ");
            $maker_qty =  $this->DM->safe_minus([$qty, $orderbookQty]);
            $taker_qty =  $orderbookQty;

            // $maker_amount = $this->_safe_math(" $maker_qty * $price  ");
            // $taker_amount = $this->_safe_math(" $taker_qty * $price  ");

            $maker_amount = $this->DM->safe_multiplication([$maker_qty, $price]);
            $taker_amount = $this->DM->safe_multiplication([$taker_qty, $price]);


            $makerFees = $this->_calculateFeesAmount($maker_amount, $makerFeesPercent);
            $takerFees = $this->_calculateFeesAmount($taker_amount, $takerFeesPercent);

            log_message('debug', "-----------------------------------");
            log_message('debug', "PARTIAL FEES CALCULATIONS");
            log_message('debug', "MAKER Fees : " . $makerFees);
            log_message('debug', "TAKER Fees : " . $takerFees);

            // $totalFees =  $this->_safe_math(" $makerFees + $takerFees  ");
            $totalFees = $this->DM->safe_add([$makerFees, $takerFees]);
            log_message('debug', "Total Fees : " . $totalFees);
            log_message('debug', "-----------------------------------");
        }

        log_message('debug', "");
        log_message('debug', "");


        return $totalFees;
    }

    /**
     * Common method that needs to be used after successful trade
     * Update SL ordr status & OHLCV current minute record
     */
    public function after_successful_trade($coinPairId, $price, $qty, $dateTimestamp)
    {

        try {
            $this->CI->WsServer_model->update_stop_limit_status($coinPairId);

            // Updating Current minute OHLCV
            $this->CI->WsServer_model->update_current_minute_OHLCV($coinPairId, $price, $qty, $dateTimestamp);
        } catch (\Exception $e) {
        }
    }


    // ORDER Related     

    public function cancel_order($order_id, $auth, $rData)
    {

        $user_id = $this->_get_user_id($auth);
        $ip_address = $rData['ip_address'];

        $data = [
            'isSuccess' => true,
            'message' => '',
        ];
        log_message('debug', '--------DO CANCEL ORDER START--------');

        $orderdata = $this->CI->WsServer_model->get_order($order_id);

        if ($user_id != $orderdata->user_id) {

            log_message('debug', 'Order not related to this user. Auth Key ' . $auth);

            $data['isSuccess'] = false;
            $data['message'] = 'You are not allow to cancel this order.';
        } else {

            $canceltrade = array(
                'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
            );

            $is_updated = $this->CI->WsServer_model->update_order($order_id, $canceltrade);

            if ($is_updated == false) {

                log_message('debug', 'Something wrong while canceling order');

                $data['isSuccess'] = false;
                $data['message'] = 'Could not cancelled the order';
            } else {
                $currency_symbol = '';
                $currency_id = '';
                $coinpair_id = $orderdata->coinpair_id;

                log_message('debug', 'Order Type : ' . $orderdata->bid_type);

                $refund_amount = '';
                if ($orderdata->bid_type == 'SELL') {
                    $currency_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
                    $refund_amount = $orderdata->bid_qty_available;
                } else {
                    $currency_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);
                    $refund_amount = $this->DM->safe_multiplication([$orderdata->bid_qty_available, $orderdata->bid_price]);
                }

                log_message('debug', 'Refund Amount : ' . $refund_amount);

                $balance = $this->CI->WsServer_model->get_user_balance_by_coin_id($currency_id, $orderdata->user_id);
                //User Financial Log
                $tradecanceldata = array(
                    'user_id' => $orderdata->user_id,
                    'balance_id' => @$balance->id,
                    'currency_id' => $currency_id,
                    'transaction_type' => 'TRADE_CANCEL',
                    'transaction_amount' => $refund_amount,
                    'transaction_fees' => 0,
                    'ip' => $ip_address,
                    'date' => date('Y-m-d H:i:s'),
                );

                $this->CI->WsServer_model->insert_balance_log($tradecanceldata);

                log_message('debug', '----- Crediting balance -----');

                $this->CI->WsServer_model->get_credit_balance_new($orderdata->user_id, $currency_id, $refund_amount);
                // Release hold balance

                log_message('debug', '----- Releasing Hold balance -----');
                $this->CI->WsServer_model->get_debit_hold_balance_new($orderdata->user_id, $currency_id, $refund_amount);

                $traderlog = array(
                    'bid_id' => $orderdata->id,
                    'bid_type' => $orderdata->bid_type,
                    'complete_qty' => $orderdata->bid_qty_available,
                    'bid_price' => $orderdata->bid_price,
                    'complete_amount' => $refund_amount,
                    'user_id' => $orderdata->user_id,
                    'coinpair_id' => $orderdata->coinpair_id,
                    'success_time' => date('Y-m-d H:i:s'),
                    'fees_amount' => $orderdata->fees_amount,
                    'available_amount' => $orderdata->amount_available,
                    'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                );

                $this->CI->WsServer_model->insert_order_log($traderlog);

                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $order_id,
                        'user_id' => $user_id,
                    ]
                );
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $orderdata->coinpair_id,
                    ]
                );

                $data['isSuccess'] = true;
                $data['message'] = 'Request cancelled successfully.';
            }
        }

        log_message('debug', '--------DO CENCEL ORDER FINISHED--------');

        return $data;
    }


    /**
     * BINANCE METHODS
     */


    /**
     * Binance BUY LIMIT TRADE Handling here
     */
    public function binance_buy_trade($coinpair_details, $buytrade, $type = 'LIMIT', $order_id = null)
    {
        log_message("debug", "BINANCE BUY $type TRADE");
        $binanceSupportedSymbol =  strtoupper(str_replace('_', '', $coinpair_details->symbol));

        $bestAskBid = $this->CI->PopexBinance->get_best_bid_ask_price($binanceSupportedSymbol);

        $binanceBuyerPrice = $bestAskBid['ask'];

        // Buyers's price should be higher or equal to Binance's seller price 
        // BUYER_PRICE >= BINANCE_SELLER_PRICE

        // $isPriceSatisfied = $this->DM->isGreaterThanOrEqual($buytrade->bid_price, $binanceBuyerPrice);
        // $isPriceSatisfied = false; // It won't send order to binance
        $isPriceSatisfied = true; // It will send order to binance


        if (!$isPriceSatisfied) {
            // Keep this order as Maker order on Popex
            // Do not do anything here
            log_message("debug", "Price Not satisfied");
            return;
        } else {

            // Price satisfied, Do binance trade order here
            log_message("debug", "Price SATISFIED");

            $primary_coin_id    = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_details->id);
            $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_details->id);

            // Link this order with binance order to make this updated on by binance trades update

            // Complete Order
            $binanceOrderDetail = $this->CI->PopexBinance->do_buy_trade($binanceSupportedSymbol, $buytrade->bid_price, $buytrade->bid_qty_available, $type, $order_id);

            if ($binanceOrderDetail == null) {
                log_message("debug", "No response from Binance");
                return;
            }
            // Binance responded
            log_message("debug", "BINANCE RESPONDED");
            log_message("debug", json_encode($binanceOrderDetail));
            log_message('debug', "Status : " . $binanceOrderDetail['status']);

            /**
             * Eg. Response
             * [symbol] => BNBBTC
             * [orderId] => 7652393
             * [clientOrderId] => aAE7BNUhITQj3eg04iG1sY
             * [transactTime] => 1508564815865
             * [price] => 0.00000000
             * [origQty] => 1.00000000
             * [executedQty] => 1.00000000
             * [status] => FILLED
             * [timeInForce] => GTC
             * [type] => MARKET
             * [side] => BUY
             */

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');
            $success_datetimestamp = strtotime($success_datetime);

            if (
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_CANCELED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_REJECTED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_EXPIRED
            ) {
                // Cancelled, Rejected, Expired
                // Do not do anything and keep buy trade order on popex as maker
                return;
            } else if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_NEW) {
                // Create linked record
                log_message('info', "Create Linked record");
                $linked = $this->CI->WsServer_model->createPopexBinanceOrderLink($buytrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);
                log_message('debug', $linked);
            } else if (
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED
            ) {

                // Create linked record
                // clientOrderId
                $this->CI->WsServer_model->createPopexBinanceOrderLink($buytrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);

                $completeQty = $binanceOrderDetail['executedQty'];
                $price = $binanceOrderDetail['price'];
                $totalAmount = $this->DM->safe_multiplication([$completeQty, $price]);

                $availableQty = $this->DM->safe_minus([$buytrade->bid_qty_available, $completeQty]);
                $availableAmount = $this->DM->safe_multiplication([$availableQty,  $price]);

                // BUYER WILL GET PRIMARY COIN
                // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
                // AND PRIMARY COIN WILL BE CREDITED
                $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $totalAmount, $completeQty);

                $tradeNewStatus = '';

                if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED) {
                    $tradeNewStatus = PopulousWSSConstants::BID_COMPLETE_STATUS;
                } else if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED) {
                    $tradeNewStatus = PopulousWSSConstants::BID_PENDING_STATUS;
                }

                // Update buy trade with completed status
                $buyupdate = array(
                    'bid_qty_available' => $availableQty,
                    'amount_available' => $availableAmount,
                    'status' =>   $tradeNewStatus
                );
                log_message("debug", 'Buy Trade update ');
                log_message("debug", json_encode($buyupdate));

                $buytraderlog = array(
                    'bid_id' => $buytrade->id,
                    'bid_type' => $buytrade->bid_type,
                    'complete_qty' => $completeQty,
                    'bid_price' => $price,
                    'complete_amount' => $totalAmount,
                    'user_id' => $buytrade->user_id,
                    'coinpair_id' => $buytrade->coinpair_id,
                    'success_time' => $success_datetime,
                    'fees_amount' => "0", // We don't charge any fees if traded on binance 
                    'available_amount' => $availableAmount,
                    'status' =>  $tradeNewStatus,
                );
                log_message("debug", 'Buy Trade log');
                log_message("debug", json_encode($buytraderlog));

                $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
                $log_id = $this->CI->WsServer_model->insert_order_log($buytraderlog);



                try {
                    // Update SL orders and OHLCV
                    $this->after_successful_trade($buytrade->coinpair_id,  $price, $completeQty, $success_datetimestamp);
                    // EVENT for BUY party
                    $this->wss_server->_event_push(
                        PopulousWSSConstants::EVENT_ORDER_UPDATED,
                        [
                            'order_id' => $buytrade->id,
                            'user_id' => $buytrade->user_id,
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
    }


    public function binance_sell_trade($coinpair_details, $selltrade, $type = "LIMIT", $order_id = null)
    {
        log_message("debug", "BINANCE SELL $type TRADE");

        $binanceSupportedSymbol =  strtoupper(str_replace('_', '', $coinpair_details->symbol));

        $bestAskBid = $this->CI->PopexBinance->get_best_bid_ask_price($binanceSupportedSymbol);

        $binanceBuyerPrice = $bestAskBid['bid'];

        // Seller's price should be lower or equal to Binance's buyer price 
        // BUYER_PRICE >= BINANCE_SELLER_PRICE

        // $isPriceSatisfied = $this->DM->isLessThanOrEqual($selltrade->bid_price, $binanceBuyerPrice);
        // $isPriceSatisfied = false; // It won't send order to binance
        $isPriceSatisfied = true; // It will send order to binance


        if (!$isPriceSatisfied) {
            // Keep this order as Maker order on Popex
            // Do not do anything here
            log_message("debug", "Price Not satisfied");
            return;
        } else {

            // Price satisfied, Do binance trade order here
            log_message("debug", "Price SATISFIED");

            $primary_coin_id    = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_details->id);
            $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_details->id);

            // Link this order with binance order to make this updated on by binance trades update
            // Complete Order
            $binanceOrderDetail = $this->CI->PopexBinance->do_sell_trade($binanceSupportedSymbol, $selltrade->bid_price, $selltrade->bid_qty_available, $type, $order_id);

            if ($binanceOrderDetail == null) {
                log_message("debug", "No response from Binance");
                return;
            }
            // Binance responded
            log_message("debug", "BINANCE RESPONDED");
            log_message("debug", json_encode($binanceOrderDetail));
            log_message('debug', "Status : " . $binanceOrderDetail['status']);

            /**
             * Eg. Response
             * [symbol] => BNBBTC
             * [orderId] => 7652393
             * [clientOrderId] => aAE7BNUhITQj3eg04iG1sY
             * [transactTime] => 1508564815865
             * [price] => 0.00000000
             * [origQty] => 1.00000000
             * [executedQty] => 1.00000000
             * [status] => FILLED
             * [timeInForce] => GTC
             * [type] => MARKET
             * [side] => BUY
             */

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');
            $success_datetimestamp = strtotime($success_datetime);

            if (
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_CANCELED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_REJECTED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_EXPIRED
            ) {
                // Cancelled, Rejected, Expired
                // Do not do anything and keep buy trade order on popex as maker
                return;
            } else if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_NEW) {
                // Create linked record
                $this->CI->WsServer_model->createPopexBinanceOrderLink($selltrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);
                // Update order, when 
            } else if (
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED
            ) {

                // Create linked record
                $this->CI->WsServer_model->createPopexBinanceOrderLink($selltrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);

                $completeQty = $binanceOrderDetail['executedQty'];
                $price = $binanceOrderDetail['price'];
                $totalAmount = $this->DM->safe_multiplication([$completeQty, $price]);

                $availableQty = $this->DM->safe_minus([$selltrade->bid_qty_available, $completeQty]);
                $availableAmount = $this->DM->safe_multiplication([$availableQty,  $price]);

                // BUYER WILL GET PRIMARY COIN
                // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
                // AND PRIMARY COIN WILL BE CREDITED

                $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $completeQty, $totalAmount);

                $tradeNewStatus = '';

                if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED) {
                    $tradeNewStatus = PopulousWSSConstants::BID_COMPLETE_STATUS;
                } else if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED) {
                    $tradeNewStatus = PopulousWSSConstants::BID_PENDING_STATUS;
                }

                // Update buy trade with completed status
                $sellupdate = array(
                    'bid_qty_available' => $availableQty,
                    'amount_available' => $availableAmount,
                    'status' =>   $tradeNewStatus
                );
                log_message("debug", 'Sell Trade update ');
                log_message("debug", json_encode($sellupdate));

                $selltraderlog = array(
                    'bid_id' => $selltrade->id,
                    'bid_type' => $selltrade->bid_type,
                    'complete_qty' => $completeQty,
                    'bid_price' => $price,
                    'complete_amount' => $totalAmount,
                    'user_id' => $selltrade->user_id,
                    'coinpair_id' => $selltrade->coinpair_id,
                    'success_time' => $success_datetime,
                    'fees_amount' => "0", // We don't charge any fees if traded on binance 
                    'available_amount' => $availableAmount,
                    'status' =>  $tradeNewStatus,
                );
                log_message("debug", 'Sell Trade log');
                log_message("debug", json_encode($selltraderlog));

                $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
                $log_id = $this->CI->WsServer_model->insert_order_log($selltraderlog);


                try {
                    // Update SL orders and OHLCV
                    $this->after_successful_trade($selltrade->coinpair_id,  $price, $completeQty, $success_datetimestamp);
                    // EVENT for BUY party
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
    }



    /**
     * Call cancel API to Binance 
     */
    public function binance_cancel_order($order_id, $auth, $rData)
    {

        $user_id = $this->_get_user_id($auth);
        $ip_address = $rData['ip_address'];

        $data = [
            'isSuccess' => true,
            'message' => '',
        ];
        log_message('debug', '--------DO CANCEL BINANCE ORDER START--------');

        $orderdata = $this->CI->WsServer_model->get_order($order_id);

        if ($user_id != $orderdata->user_id) {

            log_message('debug', 'Order not related to this user. Auth Key ' . $auth);

            $data['isSuccess'] = false;
            $data['message'] = 'You are not allow to cancel this order.';
        } else {

            $symbol = $this->CI->WsServer_model->get_coinpair_symbol_of_coinpairId($orderdata->coinpair_id);

            $symbol =  str_replace('_', '', strtoupper($symbol));

            $popexBinanceOrderDetail = $this->CI->WsServer_model->getBinanceOrderByPopexOrderId($orderdata->id);
            $binanceOrderStatusDetail = $this->PopexBinace->get_order_status($symbol, $popexBinanceOrderDetail['binance_order_id']);

            log_message('debug', '------- BINANCE ORDER STATUS DETAILS -------');

            if ($binanceOrderStatusDetail['status']) {
                log_message('debug', json_encode($binanceOrderStatusDetail));
                log_message('debug', 'STATUS : ' . $binanceOrderStatusDetail['status']);
            }

            // Only allow if ORDER IS PENDING 
            if (
                $binanceOrderStatusDetail['status'] == BINANCE_ORDER_STATUS_NEW ||
                $binanceOrderStatusDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED
            ) {


                if ($orderdata->bid_type == 'SELL') {
                    $binanceRes = $this->PopexBinace->cancel_sell_order($symbol, $popexBinanceOrderDetail['binance_order_id']);
                } else if ($orderdata->bid_type == 'BUY') {
                    $binanceRes = $this->PopexBinace->cancel_buy_order($symbol, $popexBinanceOrderDetail['binance_order_id']);
                }

                if ($binanceRes == false) {
                    log_message('debug', 'Binance Respond false');
                    $data['isSuccess'] = false;
                    $data['message'] = 'Could not cancel Order';
                } else {
                    log_message('debug', 'Binance Cancel response');
                    log_message('debug', json_encode($binanceRes));

                    if ($binanceRes['status'] == BINANCE_ORDER_STATUS_CANCELED) {
                        // Successfully cancelled 

                        $canceltrade = array(
                            'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                        );

                        $is_updated = $this->CI->WsServer_model->update_order($order_id, $canceltrade);

                        if ($is_updated == false) {

                            log_message('debug', 'Something wrong while canceling order');

                            $data['isSuccess'] = false;
                            $data['message'] = 'Could not cancel the order';
                        } else {
                            $currency_symbol = '';
                            $currency_id = '';
                            $coinpair_id = $orderdata->coinpair_id;

                            log_message('debug', 'Order Type : ' . $orderdata->bid_type);

                            $refund_amount = '';
                            if ($orderdata->bid_type == 'SELL') {
                                $currency_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
                                $refund_amount = $orderdata->bid_qty_available;
                            } else {
                                $currency_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);
                                $refund_amount = $this->DM->safe_multiplication([$orderdata->bid_qty_available, $orderdata->bid_price]);
                            }

                            log_message('debug', 'Refund Amount : ' . $refund_amount);

                            $balance = $this->CI->WsServer_model->get_user_balance_by_coin_id($currency_id, $orderdata->user_id);
                            //User Financial Log
                            $tradecanceldata = array(
                                'user_id' => $orderdata->user_id,
                                'balance_id' => @$balance->id,
                                'currency_id' => $currency_id,
                                'transaction_type' => 'TRADE_CANCEL',
                                'transaction_amount' => $refund_amount,
                                'transaction_fees' => 0,
                                'ip' => $ip_address,
                                'date' => date('Y-m-d H:i:s'),
                            );

                            $this->CI->WsServer_model->insert_balance_log($tradecanceldata);

                            log_message('debug', '----- Crediting balance -----');

                            $this->CI->WsServer_model->get_credit_balance_new($orderdata->user_id, $currency_id, $refund_amount);
                            // Release hold balance

                            log_message('debug', '----- Releasing Hold balance -----');
                            $this->CI->WsServer_model->get_debit_hold_balance_new($orderdata->user_id, $currency_id, $refund_amount);

                            $traderlog = array(
                                'bid_id' => $orderdata->id,
                                'bid_type' => $orderdata->bid_type,
                                'complete_qty' => $orderdata->bid_qty_available,
                                'bid_price' => $orderdata->bid_price,
                                'complete_amount' => $refund_amount,
                                'user_id' => $orderdata->user_id,
                                'coinpair_id' => $orderdata->coinpair_id,
                                'success_time' => date('Y-m-d H:i:s'),
                                'fees_amount' => $orderdata->fees_amount,
                                'available_amount' => $orderdata->amount_available,
                                'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                            );

                            $this->CI->WsServer_model->insert_order_log($traderlog);

                            $this->wss_server->_event_push(
                                PopulousWSSConstants::EVENT_ORDER_UPDATED,
                                [
                                    'order_id' => $order_id,
                                    'user_id' => $user_id,
                                ]
                            );
                            $this->wss_server->_event_push(
                                PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                                [
                                    'coin_id' => $orderdata->coinpair_id,
                                ]
                            );

                            $data['isSuccess'] = true;
                            $data['message'] = 'Request cancelled successfully.';
                        }
                    } else {
                        // Something is wrong while cancelling order
                        log_message('debug', 'Something wrong while canceling binance order');

                        $data['isSuccess'] = false;
                        $data['message'] = 'Could not cancel the binance order';
                    }
                }
            } else {

                if ($binanceOrderStatusDetail['status'] == BINANCE_ORDER_STATUS_FILLED) {
                    // Must do work here
                    // It's already executed but sometime popex doesn't get filled event 
                    // Need to updae order with filled and do the rest of process of credit debit balances and trade history as well

                    $tradeDetail = (array) $orderdata;

                    $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($tradeDetail['coinpair_id']);
                    $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($tradeDetail['coinpair_id']);

                    $remainingQtyBefore = $tradeDetail['bid_qty_available'];
                    // $remainingQtyAfter = $this->CI->DM->safe_minus([$remainingQtyBefore, $quantity]);

                    $this->_binance_order_interal_update($primary_coin_id, $secondary_coin_id, $tradeDetail['bid_type'], $tradeDetail, $remainingQtyBefore, $tradeDetail['bid_price'], PopulousWSSConstants::BID_COMPLETE_STATUS, time());
                    $data['isSuccess'] = false;
                    $data['message'] = 'Order is already executed';
                } else {
                    $data['isSuccess'] = false;
                    $data['message'] = 'Could not cancel order';
                }
            }
        }

        log_message('debug', '--------DO CANCEL BINANCE ORDER FINISHED--------');

        return $data;
    }


    /**
     * Binance order internal update 
     */
    public function _binance_order_interal_update($primary_coin_id, $secondary_coin_id,  $side, $tradeDetail, $quantity, $price, $newPopexStatus, $success_datetimestamp)
    {

        $success_datetime = date('Y-m-d H:i:s', $success_datetimestamp);

        $trade_amount = $this->DM->safe_multiplication([$price, $quantity]);

        if ($side == 'BUY') {
            // Buy order
            // ORDER UPDATE
            $buytrade = (object) $tradeDetail;

            $buyer_av_bid_amount_after_trade = $this->DM->safe_minus([$buytrade->amount_available, $trade_amount]);
            $buyer_av_qty_after_trade = $this->DM->safe_minus([$buytrade->bid_qty_available, $quantity]);

            log_message('debug', 'buyer_av_bid_amount_after_trade : ' . $buyer_av_bid_amount_after_trade);
            log_message('debug', 'buyer_av_qty_after_trade : ' . $buyer_av_qty_after_trade);


            $buyupdate = array(
                'bid_qty_available' => $buyer_av_qty_after_trade,
                'amount_available' => $buyer_av_bid_amount_after_trade,
                'status' => $newPopexStatus,
            );

            log_message('debug', 'BUY TRADE UPDATE -> ' . json_encode($buyupdate));


            $buytraderlog = array(
                'bid_id' => $buytrade->id,
                'bid_type' => $buytrade->bid_type,
                'complete_qty' => $quantity,
                'bid_price' => $price,
                'complete_amount' => $trade_amount,
                'user_id' => $buytrade->user_id,
                'coinpair_id' => $buytrade->coinpair_id,
                'success_time' => $success_datetime,
                'fees_amount' => 0,
                'available_amount' => $buyer_av_qty_after_trade,
                'status' =>  $newPopexStatus,
            );


            log_message('debug', 'debug', 'BUY TRADER LOG -> ' . json_encode($buytraderlog));

            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);

            // BALANCE UPDATE
            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $quantity, $trade_amount);

            $log_id = $this->CI->WsServer_model->insert_order_log($buytraderlog);

            $this->CI->WsServer_model->update_current_minute_OHLCV($buytrade->coinpair_id, $price, $quantity, $success_datetimestamp);
        } else if ($side == 'SELL') {
            // Sell orders

            $selltrade = (object) $tradeDetail;

            $seller_av_bid_amount_after_trade = $this->DM->safe_minus([$selltrade->amount_available, $trade_amount]);
            $seller_av_qty_after_trade = $this->DM->safe_minus([$selltrade->bid_qty_available, $quantity]);

            log_message('debug', 'seller_av_bid_amount_after_trade : ' . $seller_av_bid_amount_after_trade);
            log_message('debug', 'seller_av_qty_after_trade : ' . $seller_av_qty_after_trade);

            $sellupdate = array(
                'bid_qty_available' => $seller_av_qty_after_trade,
                'amount_available' => $seller_av_bid_amount_after_trade,
                'status' => $newPopexStatus,
            );

            log_message('debug', 'SELL TRADE UPDATE -> ' . json_encode($sellupdate));

            $selltraderlog = array(
                'bid_id' => $selltrade->id,
                'bid_type' => $selltrade->bid_type,
                'complete_qty' => $quantity,
                'bid_price' => $price,
                'complete_amount' => $trade_amount,
                'user_id' => $selltrade->user_id,
                'coinpair_id' => $selltrade->coinpair_id,
                'success_time' => $success_datetime,
                'fees_amount' => 0,
                'available_amount' => $seller_av_bid_amount_after_trade, // $seller_available_bid_amount_after_trade,
                'status' =>  PopulousWSSConstants::BID_PENDING_STATUS,
            );
            log_message('debug', 'SELL TRADER LOG ->' . json_encode($selltraderlog));

            $log_id = $this->CI->WsServer_model->insert_order_log($selltraderlog);

            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
            // BALANCE UPDATE
            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $quantity, $trade_amount);

            // Updating Current minute OHLCV
            $this->CI->WsServer_model->update_current_minute_OHLCV($selltrade->coinpair_id, $price, $quantity, $success_datetimestamp);
        }

        log_message('debug', 'Log ID : ' . $log_id);


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
                    'order_id' => $tradeDetail['id'],
                    'user_id' => $tradeDetail['user_id'],
                ]
            );

            if ($log_id != null) {

                // EVENT for single trade
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_TRADE_CREATED,
                    [
                        'log_id' => $log_id,
                    ]
                );
            }

            $this->wss_server->_event_push(
                PopulousWSSConstants::EVENT_MARKET_SUMMARY,
                []
            );
        } catch (\Exception $e) {
        }
    }
}
