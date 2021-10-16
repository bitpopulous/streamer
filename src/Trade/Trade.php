<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Common\Auth;
use PopulousWSS\Exchanges\Binance;

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

    protected $exchanges = [];

    protected $broadcaster;

    protected $broadcasterRequired = true;

    public function __construct($isBroadcasterRequired = true)
    {
        $this->CI = &get_instance();

        $this->broadcasterRequired = $isBroadcasterRequired;

        // $this->wss_server = new ServerHandler();

        $this->CI->load->model([
            'WsServer_model',
        ]);

        $this->CI->load->library("PopDecimalMath", null, 'decimalmaths');

        if ($this->broadcasterRequired) {
            $this->CI->load->library('Broadcaster');
            $this->broadcaster = $this->CI->broadcaster;
        }


        $this->DM = &$this->CI->decimalmaths;
        $this->DB = $this->CI->db;
        // $this->exchanges['BINANCE'] = new Binance();
        // $this->exchanges['BINANCE']->loadExchangeInfo();

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


    /**
     * 0 = No decimal, Max value would be 99999999
     * 1 = 99999999.9
     * 2 = 99999999.99
     * 3 = 99999999.999
     */
    public function max_value($decimals)
    {

        $_str = '99999999'; // Maximum 99999999 <- 8 decigit before precision

        if ($decimals > 0) {
            $_str .= '.';
            for ($j = 1; $j <= (int) $decimals; $j++) {
                $_str .= '9';
            }
        }

        return $_str;
    }

    /**
     * 0 = No decimal, Min value would be 1
     * 1 = 0.1
     * 2 = 0.001
     * 3 = 0.0001
     */
    public function min_value($decimals)
    {


        $_str = '';
        if ($decimals > 0) {
            $_str = '0.';
            for ($i = 1; $i < $decimals; $i++) {
                $_str .= '0';
            }
            $_str .= '1';
        } else {
            $_str = '1';
        }

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

    public function cancel_order(string $order_id, string $user_id)
    {

        $data = [
            'isSuccess' => true,
            'message' => '',
        ];
        log_message('debug', '--------DO CANCEL ORDER START--------');

        $orderdata = $this->CI->WsServer_model->get_order($order_id);

        if ($orderdata == null) {
            log_message('debug', 'Order not found.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'order_not_found';
            $data['message'] = 'Order not found.';
        } else if ($orderdata->status == PopulousWSSConstants::BID_CANCELLED_STATUS) {
            log_message('debug', 'Order is already cancelled.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'order_is_already_cancelled';
            $data['message'] = 'Order is already cancelled';
        } else if ($user_id != $orderdata->user_id) {
            log_message('debug', 'Not allowed ti cancel this order');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'you_are_not_allowed_to_cancel_this_order';
            $data['message'] = 'You are not allowed to cancel this order.';
        } else if ($orderdata->is_market == false &&  $orderdata->is_stop_limit == false && $orderdata->status != PopulousWSSConstants::BID_PENDING_STATUS) {
            log_message('debug', 'Order is not pending.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'order_is_not_pending';
            $data['message'] = 'Order is not pending';
        } else {

            try {
                $this->DB->trans_start();

                $canceltrade = array(
                    'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                );

                $is_updated = $this->CI->WsServer_model->update_order($order_id, $canceltrade);

                if ($is_updated == false) {

                    log_message('debug', 'Something wrong while canceling order');

                    $data['isSuccess'] = false;
                    $data['msg_code'] = 'could_not_cancelled_the_order';
                    $data['message'] = 'Could not cancelled the order';
                    throw new \Exception("could_not_cancelled_the_order");
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
                        'ip' => '',
                        'date' => date('Y-m-d H:i:s'),
                    );

                    $this->CI->WsServer_model->insert_balance_log($tradecanceldata);

                    if (!$orderdata->is_market) {
                        // We don't hold amount/qty for the market order
                        // No credit back

                        log_message('debug', '----- Crediting balance -----');
                        $this->CI->WsServer_model->get_credit_balance_new($orderdata->user_id, $currency_id, $refund_amount);
                        // Release hold balance

                        log_message('debug', '----- Releasing Hold balance -----');
                        $this->CI->WsServer_model->get_debit_hold_balance_new($orderdata->user_id, $currency_id, $refund_amount);
                    } else {
                        log_message('debug', '--- IT IS A MARKET ORDER : NO HOLD, NO RELEASE ---');
                    }

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

                    $this->event_order_updated($order_id, $user_id);
                    $this->event_coinpair_updated($orderdata->coinpair_id);

                    $data['isSuccess'] = true;
                    $orderdata = $this->CI->WsServer_model->get_order($order_id);
                    $data['order'] = $orderdata;
                    $data['msg_code'] = 'request_cancelled_successfully';
                    $data['message'] = 'Request cancelled successfully.';
                }


                $this->DB->trans_complete();

                $trans_status = $this->DB->trans_status();

                if ($trans_status == FALSE) {
                    $this->DB->trans_rollback();
                } else {
                    $this->DB->trans_commit();
                }
            } catch (\Exception $e) {
                $this->DB->trans_rollback();
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

        if (!$this->exchanges['BINANCE']->isSymbolSupported($binanceSupportedSymbol)) {
            log_message("debug", "Binance Not supported symbol $binanceSupportedSymbol");
            return false;
        }


        // Price satisfied, Do binance trade order here
        log_message("debug", "Price SATISFIED");

        $primary_coin_id    = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_details->id);
        $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_details->id);

        // Link this order with binance order to make this updated on by binance trades update

        $binanceSymbolInfo = $this->exchanges['BINANCE']->getSymbolInfo($binanceSupportedSymbol);

        $baseAssetPrice = $this->_format_number($buytrade->bid_price, $binanceSymbolInfo['quotePrecision']);
        $quoteAssetQty = $this->_format_number($buytrade->bid_qty_available, $binanceSymbolInfo['quotePrecision']);

        // Complete Order
        if ($type == 'LIMIT') {
            $binanceOrderDetail = $this->exchanges['BINANCE']->sendLimitOrder($binanceSupportedSymbol, 'BUY', $baseAssetPrice, $quoteAssetQty,  $order_id);
        } else if ($type == 'MARKET') {
            $binanceOrderDetail = $this->exchanges['BINANCE']->sendMarketOrder($binanceSupportedSymbol, 'BUY', $quoteAssetQty, $order_id);
        }

        if ($binanceOrderDetail == null) {
            log_message("debug", "No response from Binance");
            return false;
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
            return true;
        } else if (
            $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED ||
            $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED
        ) {

            // Create linked record
            // clientOrderId
            $this->CI->WsServer_model->createPopexBinanceOrderLink($buytrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);


            /**
             * For Market type : Price will be 0.0000 , Use fills records which will have every trade detail
             */
            $completeQty = $binanceOrderDetail['executedQty'];
            $tradeNewStatus = '';

            if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED) {
                $tradeNewStatus = PopulousWSSConstants::BID_COMPLETE_STATUS;
            } else if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED) {
                $tradeNewStatus = PopulousWSSConstants::BID_PENDING_STATUS;
            }

            $buyer_receiving_amount = $completeQty;

            if ($type == 'LIMIT') {
                // Limit trade
                $price = $binanceOrderDetail['price'];
                $totalAmount = $this->DM->safe_multiplication([$completeQty, $price]);

                $availableQty = $this->DM->safe_minus([$buytrade->bid_qty_available, $completeQty]);
                $availableAmount = $this->DM->safe_multiplication([$availableQty,  $price]);

                /**
                 * Here buyer always be a TAKER and seller as a MAKER
                 */
                $buyerPercent   = $this->_getTakerFees($buytrade->user_id);
                $buyerTotalFees = $this->_calculateFeesAmount($buyer_receiving_amount, $buyerPercent);

                log_message('debug', 'Buyer Percent : ' . $buyerPercent);
                log_message('debug', 'Buyer Total Fees : ' . $buyerTotalFees);

                $buyer_receiving_amount_after_fees  = $this->DM->safe_minus([$buyer_receiving_amount, $buyerTotalFees]);
                log_message('debug', 'Buyer receiving after fees : ' . $buyer_receiving_amount_after_fees);


                // BUYER WILL GET PRIMARY COIN
                // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
                // AND PRIMARY COIN WILL BE CREDITED
                $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount_after_fees, $totalAmount);


                // Update buy trade with completed status
                $buyupdate = array(
                    'bid_qty_available' => $availableQty,
                    'amount_available' => $availableAmount,
                    'fees_amount' => $this->DM->safe_add([$buytrade->fees_amount, $buyerTotalFees]),
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
                    'fees_amount' => $buyerTotalFees,
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

                    $this->event_order_updated($buytrade->id, $this->user_id);
                    $this->event_trade_created($log_id);
                    $this->event_market_summary();
                } catch (\Exception $e) {
                }
            } else if ($type == 'MARKET') {
                // Market trade

                $fills = (array) $binanceOrderDetail['fills'];

                $availableQtyBefore = $buytrade->bid_qty_available;

                $buyerPercent   = $this->_getTakerFees($buytrade->user_id);
                log_message('debug', 'Buyer Percent : ' . $buyerPercent);

                foreach ($fills as $_fill) {

                    $_fill = (array) $_fill;
                    $_price = $_fill['price'];
                    $_qty = $_fill['qty'];

                    $totalAmount = $this->DM->safe_multiplication([$_qty, $_price]);

                    $availableQty = $this->DM->safe_minus([$availableQtyBefore, $_qty]);
                    $availableAmount = $this->DM->safe_multiplication([$availableQty,  $_price]);

                    $availableQtyBefore = $availableQty; // Keep track of reduced qty 

                    // BUYER WILL GET PRIMARY COIN
                    // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
                    // AND PRIMARY COIN WILL BE CREDITED
                    // $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $_qty, $totalAmount);

                    $buyerTotalFees = $this->_calculateFeesAmount($_qty, $buyerPercent);
                    log_message('debug', 'Buyer Total Fees : ' . $buyerTotalFees);

                    $buyer_receiving_amount_after_fees  = $this->DM->safe_minus([$_qty, $buyerTotalFees]);
                    log_message('debug', 'Buyer receiving after fees : ' . $buyer_receiving_amount_after_fees);


                    // NO HOLD USE : TRUE, Direct deduction & credit
                    // BUYER WILL PAY SECONDARY COIN AMOUNT
                    $this->CI->WsServer_model->get_debit_balance_new($buytrade->user_id, $secondary_coin_id, $totalAmount);

                    // BUYER WILL GET PRIMARY COIN AMOUNT
                    $this->CI->WsServer_model->get_credit_balance_new($buytrade->user_id, $primary_coin_id, $buyer_receiving_amount_after_fees);

                    if ($this->DM->isGreaterThan($buytrade->bid_price, 0)) {
                        $tPrice = $this->DM->safe_add([$buytrade->bid_price, $_price]);
                        $averagePrice = $this->DM->safe_division([$tPrice, 2]);
                    } else {
                        $averagePrice = $_price;
                    }

                    if ($this->DM->isGreaterThan($buytrade->total_amount, 0)) {
                        $tAmount = $this->DM->safe_add([$buytrade->total_amount, $totalAmount]);
                        $averageTotalAmount = $this->DM->safe_division([$tAmount, 2]);
                    } else {
                        $averageTotalAmount = $totalAmount;
                    }

                    // Update buy trade with completed status
                    $buyupdate = array(
                        'bid_qty_available' => $availableQty,
                        'fees_amount' => $this->DM->safe_add([$buytrade->fees_amount, $buyerTotalFees]),
                        'bid_price' => $averagePrice,
                        'total_amount' => $averageTotalAmount,
                        'status' =>   $tradeNewStatus,
                    );
                    log_message("debug", 'Buy Trade update ');
                    log_message("debug", json_encode($buyupdate));

                    $buytraderlog = array(
                        'bid_id' => $buytrade->id,
                        'bid_type' => $buytrade->bid_type,
                        'complete_qty' => $_qty,
                        'bid_price' => $_price,
                        'complete_amount' => $totalAmount,
                        'user_id' => $buytrade->user_id,
                        'coinpair_id' => $buytrade->coinpair_id,
                        'success_time' => $success_datetime,
                        'fees_amount' => $buyerTotalFees,
                        'available_amount' => $availableAmount,
                        'status' =>  $tradeNewStatus,
                    );
                    log_message("debug", 'Buy Trade log');
                    log_message("debug", json_encode($buytraderlog));

                    $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
                    $log_id = $this->CI->WsServer_model->insert_order_log($buytraderlog);

                    try {
                        // Update SL orders and OHLCV
                        $this->after_successful_trade($buytrade->coinpair_id,  $_price, $_qty, $success_datetimestamp);

                        $this->event_order_updated($buytrade->id, $buytrade->user_id);
                        $this->event_trade_created($log_id);
                        $this->event_market_summary();
                    } catch (\Exception $e) {
                    }
                }

                if ($this->DM->isZeroOrNegative($availableQtyBefore)) {
                    log_message('debug', 'MARKET ORDER SHOULD NOT BE PENDING AT ALL');
                }
            }


            return true;
        }
    }


    public function binance_sell_trade($coinpair_details, $selltrade, $type = "LIMIT", $order_id = null)
    {
        log_message("debug", "BINANCE SELL $type TRADE");

        $binanceSupportedSymbol =  strtoupper(str_replace('_', '', $coinpair_details->symbol));
        if (!$this->exchanges['BINANCE']->isSymbolSupported($binanceSupportedSymbol)) {
            log_message("debug", "Binance Not supported symbol $binanceSupportedSymbol");
            return;
        }

        $bestAskBid = $this->exchanges['BINANCE']->getBestBidAskPrice($binanceSupportedSymbol);

        if (empty($bestAskBid)) {
            log_message("debug", "Could not fetch binance best ask bid prices");
            return;
        }

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


            $binanceSymbolInfo = $this->exchanges['BINANCE']->getSymbolInfo($binanceSupportedSymbol);

            if (!$this->exchanges['BINANCE']->isSymbolSupported($binanceSupportedSymbol)) {
                log_message("debug", "Binance Not supported symbol $binanceSupportedSymbol");
                return;
            }

            $baseAssetPrice = $this->_format_number($selltrade->bid_price, $binanceSymbolInfo['quotePrecision']);
            $quoteAssetQty = $this->_format_number($selltrade->bid_qty_available, $binanceSymbolInfo['quotePrecision']);

            // Link this order with binance order to make this updated on by binance trades update
            // Complete Order
            if ($type == 'LIMIT') {
                $binanceOrderDetail = $this->exchanges['BINANCE']->sendLimitOrder($binanceSupportedSymbol, 'SELL', $baseAssetPrice, $quoteAssetQty, $order_id);
            } else if ($type == 'MARKET') {
                $binanceOrderDetail = $this->exchanges['BINANCE']->sendMarketOrder($binanceSupportedSymbol, 'SELL', $quoteAssetQty, $order_id);
            }

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
                $linked = $this->CI->WsServer_model->createPopexBinanceOrderLink($selltrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);
                log_message('debug', $linked);
                return true;
            } else if (
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED ||
                $binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED
            ) {

                // Create linked record
                $this->CI->WsServer_model->createPopexBinanceOrderLink($selltrade->id, $binanceOrderDetail['orderId'], $binanceOrderDetail['clientOrderId'], $binanceOrderDetail['status']);

                $completeQty = $binanceOrderDetail['executedQty'];

                $tradeNewStatus = '';

                if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_FILLED) {
                    $tradeNewStatus = PopulousWSSConstants::BID_COMPLETE_STATUS;
                } else if ($binanceOrderDetail['status'] == BINANCE_ORDER_STATUS_PARTIALLY_FILLED) {
                    $tradeNewStatus = PopulousWSSConstants::BID_PENDING_STATUS;
                }


                if ($type == 'LIMIT') {
                    // Limit trade

                    $price = $binanceOrderDetail['price'];
                    $totalAmount = $this->DM->safe_multiplication([$completeQty, $price]);
                    $seller_receiving_amount = $totalAmount;

                    $availableQty = $this->DM->safe_minus([$selltrade->bid_qty_available, $completeQty]);
                    $availableAmount = $this->DM->safe_multiplication([$availableQty,  $price]);


                    $sellerPercent = $this->_getTakerFees($selltrade->user_id);
                    $sellerTotalFees = $this->_calculateFeesAmount($seller_receiving_amount, $sellerPercent);

                    log_message('debug', 'Seller Percent : ' . $sellerPercent);
                    log_message('debug', 'Seller Total Fees : ' . $sellerTotalFees);

                    $seller_receiving_amount_after_fees  = $this->DM->safe_minus([$seller_receiving_amount, $sellerTotalFees]);
                    log_message('debug', 'Seller receiving after fees : ' . $seller_receiving_amount_after_fees);

                    // BUYER WILL GET PRIMARY COIN
                    // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
                    // AND PRIMARY COIN WILL BE CREDITED

                    $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $completeQty, $seller_receiving_amount_after_fees);

                    // Update buy trade with completed status
                    $sellupdate = array(
                        'bid_qty_available' => $availableQty,
                        'amount_available' => $availableAmount,
                        'fees_amount' => $this->DM->safe_add([$selltrade->fees_amount, $sellerTotalFees]),
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
                        'fees_amount' => $sellerTotalFees,
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

                        $this->event_order_updated($selltrade->id, $selltrade->user_id);
                        $this->event_trade_created($log_id);
                        $this->event_market_summary();
                    } catch (\Exception $e) {
                    }
                } else if ($type == 'MARKET') {
                    // Market trade
                    // For market we actually don't hold the amount , It will be direct deduction and credit after fees

                    $fills = (array) $binanceOrderDetail['fills'];
                    $availableQtyBefore = $selltrade->bid_qty_available;

                    $sellerPercent   = $this->_getTakerFees($selltrade->user_id);
                    log_message('debug', 'Seller Percent : ' . $sellerPercent);

                    foreach ($fills as $_fill) {

                        $_fill = (array) $_fill;
                        $_price = $_fill['price'];
                        $_qty = $_fill['qty'];

                        $totalAmount = $this->DM->safe_multiplication([$_qty, $_price]);

                        $availableQty = $this->DM->safe_minus([$availableQtyBefore, $_qty]);
                        $availableAmount = $this->DM->safe_multiplication([$availableQty,  $_price]);


                        $availableQtyBefore = $availableQty; // Keep track of reduced qty 
                        $seller_receiving_amount = $totalAmount;



                        // BUYER WILL GET PRIMARY COIN
                        // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
                        // AND PRIMARY COIN WILL BE CREDITED
                        // $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $_qty, $totalAmount);

                        $sellerTotalFees = $this->_calculateFeesAmount($seller_receiving_amount, $sellerPercent);
                        log_message('debug', 'Seller Total Fees : ' . $sellerTotalFees);

                        $seller_receiving_amount_after_fees  = $this->DM->safe_minus([$_qty, $sellerTotalFees]);
                        log_message('debug', 'Seller receiving after fees : ' . $seller_receiving_amount_after_fees);

                        $this->CI->WsServer_model->get_debit_balance_new($selltrade->user_id, $primary_coin_id, $_qty);
                        $this->CI->WsServer_model->get_credit_balance_new($selltrade->user_id, $secondary_coin_id, $seller_receiving_amount_after_fees);

                        if ($this->DM->isGreaterThan($selltrade->bid_price, 0)) {
                            $tPrice = $this->DM->safe_add([$selltrade->bid_price, $_price]);
                            $averagePrice = $this->DM->safe_division([$tPrice, 2]);
                        } else {
                            $averagePrice = $_price;
                        }

                        if ($this->DM->isGreaterThan($selltrade->total_amount, 0)) {
                            $tAmount = $this->DM->safe_add([$selltrade->total_amount, $totalAmount]);
                            $averageTotalAmount = $this->DM->safe_division([$tAmount, 2]);
                        } else {
                            $averageTotalAmount = $totalAmount;
                        }



                        // Update buy trade with completed status
                        $sellupdate = array(
                            'bid_qty_available' => $availableQty,
                            'fees_amount' => $this->DM->safe_add([$selltrade->fees_amount, $sellerTotalFees]),
                            'bid_price' => $averagePrice,
                            'total_amount' => $averageTotalAmount,
                            'status' =>   $tradeNewStatus
                        );
                        log_message("debug", 'Sell Trade update ');
                        log_message("debug", json_encode($sellupdate));


                        $selltraderlog = array(
                            'bid_id' => $selltrade->id,
                            'bid_type' => $selltrade->bid_type,
                            'complete_qty' => $_qty,
                            'bid_price' => $_price,
                            'complete_amount' => $totalAmount,
                            'user_id' => $selltrade->user_id,
                            'coinpair_id' => $selltrade->coinpair_id,
                            'success_time' => $success_datetime,
                            'fees_amount' => $sellerTotalFees,
                            'available_amount' => $availableAmount,
                            'status' =>  $tradeNewStatus,
                        );
                        log_message("debug", 'Sell Trade log');
                        log_message("debug", json_encode($selltraderlog));

                        $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
                        $log_id = $this->CI->WsServer_model->insert_order_log($selltraderlog);


                        try {
                            // Update SL orders and OHLCV
                            $this->after_successful_trade($selltrade->coinpair_id,  $_price, $_qty, $success_datetimestamp);
                            $this->event_order_updated($selltrade->id, $selltrade->user_id);
                            $this->event_trade_created($log_id);
                            $this->event_market_summary();
                        } catch (\Exception $e) {
                        }
                    }
                }

                return true;
            }
        }
    }


    public function create_limit_order($side, $qty, $price, $coinpairId, $userId)
    {

        $open_date = date('Y-m-d H:i:s');

        $totalAmount = $this->DM->safe_multiplication([$price, $qty]);

        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => $side,
            'bid_price' => $price,
            'bid_qty' => $qty,
            'bid_qty_available' => $qty,
            'total_amount' => $totalAmount,
            'amount_available' => $totalAmount,
            'coinpair_id' => $coinpairId,
            'user_id' => $userId,
            'is_stop_limit' => false,
            'is_market' => false,
            'open_order' => $open_date,
            'fees_amount' => 0,
            'status' => PopulousWSSConstants::BID_PENDING_STATUS,
        );

        $last_id = $this->CI->WsServer_model->insert_order($tadata);

        if ($last_id) {
            return $last_id;
        } else {
            return false;
        }
    }

    public function create_market_order($side, $qty, $coinpairId, $userId)
    {
        $open_date = date('Y-m-d H:i:s');

        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => $side,
            'bid_price' => 0,
            'bid_qty' => $qty,
            'bid_qty_available' => $qty,
            'total_amount' => 0,
            'amount_available' => 0,
            'coinpair_id' => $coinpairId,
            'user_id' => $userId,
            'is_stop_limit' => false,
            'is_market' => true,
            'open_order' => $open_date,
            'fees_amount' => 0,
            'status' => PopulousWSSConstants::BID_PENDING_STATUS,
        );

        $last_id = $this->CI->WsServer_model->insert_order($tadata);

        if ($last_id) {
            return $last_id;
        } else {
            return false;
        }
    }

    public function create_sl_order($side, $qty, $condition,  $limitPrice, $stopPrice, $coinpairId, $userId)
    {
        $open_date = date('Y-m-d H:i:s');

        $totalAmount = $this->DM->safe_multiplication([$limitPrice, $qty]);

        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => $side,
            'bid_price' => $limitPrice,
            'bid_price_limit' => $limitPrice,
            'bid_price_stop' => $stopPrice,
            'bid_qty' => $qty,
            'bid_qty_available' => $qty,
            'total_amount' => $totalAmount,
            'amount_available' => $totalAmount,
            'coinpair_id' => $coinpairId,
            'user_id' => $userId,
            'open_order' => $open_date,
            'fees_amount' => 0,
            'is_stop_limit' => true,
            'is_market' => false,
            'stop_condition' => $condition,
            'status' => PopulousWSSConstants::BID_QUEUED_STATUS,
        );
        $last_id = $this->CI->WsServer_model->insert_order($tadata);

        if ($last_id) {
            return $last_id;
        } else {
            return false;
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

        if ($orderdata == null) {
            log_message('debug', 'Order not found.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'order_not_found';
            $data['message'] = 'Order not found.';
        } else if ($orderdata->status == PopulousWSSConstants::BID_CANCELLED_STATUS) {
            log_message('debug', 'Order is already cancelled.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'order_is_already_cancelled';
            $data['message'] = 'Order is already cancelled';
        } else if ($user_id != $orderdata->user_id) {
            log_message('debug', 'Order not related to this user. Auth Key ' . $auth);
            $data['isSuccess'] = false;
            $data['msg_code'] = 'you_are_not_allowed_to_cancel_this_order';
            $data['message'] = 'You are not allowed to cancel this order.';
        } else if ($orderdata->status != PopulousWSSConstants::BID_PENDING_STATUS) {
            log_message('debug', 'Order is not pending.');
            $data['isSuccess'] = false;
            $data['msg_code'] = 'order_is_not_pending';
            $data['message'] = 'Order is not pending';
        } else {

            $symbol = $this->CI->WsServer_model->get_coinpair_symbol_of_coinpairId($orderdata->coinpair_id);
            $symbol =  str_replace('_', '', strtoupper($symbol));

            log_message('debug', "Coinpair : " . $symbol);

            $popexBinanceOrderDetail = $this->CI->WsServer_model->getBinanceOrderByPopexOrderId($orderdata->id);

            if ($popexBinanceOrderDetail['status'] == PopulousWSSConstants::EXTERNAL_ORDER_INACTIVE_STATUS) {
                log_message("debug", "Order already unlinked with binance");
                $data['isSuccess'] = true;
                $data['msg_code'] = 'order_is_already_cancelled';
                $data['message'] = 'Order is already cancelled';
            }

            log_message('debug', 'Linked order detail : ' . json_encode($popexBinanceOrderDetail));

            $binanceOrderStatusDetail = $this->exchanges['BINANCE']->getOrderStatus($symbol, $popexBinanceOrderDetail['binance_order_id']);

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

                $binanceRes = $this->exchanges['BINANCE']->cancelOrder($symbol, $popexBinanceOrderDetail['binance_order_id']);

                // if ($orderdata->bid_type == 'SELL') {
                // } else if ($orderdata->bid_type == 'BUY') {
                // }

                if ($binanceRes == false) {
                    log_message('debug', 'Binance Respond false');
                    $data['isSuccess'] = false;
                    $data['msg_code'] = 'could_not_cancel_order';
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

                        $this->CI->WsServer_model->unlinkPopexBinanceOrder($order_id);

                        if ($is_updated == false) {

                            log_message('debug', 'Something wrong while canceling order');

                            $data['isSuccess'] = false;
                            $data['msg_code'] = 'could_not_cancelled_the_order';
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

                            $this->event_order_updated($order_id, $user_id);
                            $this->event_coinpair_updated($orderdata->coinpair_id);

                            $data['isSuccess'] = true;
                            $data['msg_code'] = 'request_cancelled_successfully';
                            $data['message'] = 'Request cancelled successfully.';
                        }
                    } else {
                        // Something is wrong while cancelling order
                        log_message('debug', 'Something wrong while canceling binance order');

                        $data['isSuccess'] = false;
                        $data['msg_code'] = 'could_not_cancel_the_binance_order';
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
                    $data['msg_code'] = 'order_is_already_executed';
                    $data['message'] = 'Order is already executed';
                } else if ($binanceOrderStatusDetail['status'] == BINANCE_ORDER_STATUS_CANCELED) {

                    // These are rare case, we will be only updating order status to cancel and no credit back should be happen here
                    $canceltrade = array(
                        'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                    );

                    $is_updated = $this->CI->WsServer_model->update_order($order_id, $canceltrade);

                    $data['isSuccess'] = true;
                    $data['msg_code'] = 'request_cancelled_successfully';
                    $data['message'] = 'Request cancelled successfully.';
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

            $buyer_av_qty_after_trade = $this->DM->safe_minus([$buytrade->bid_qty_available, $quantity]);

            log_message('debug', 'buyer_av_qty_after_trade : ' . $buyer_av_qty_after_trade);


            $buyerPercent   = $this->_getTakerFees($buytrade->user_id);
            $buyerTotalFees = $this->_calculateFeesAmount($quantity, $buyerPercent);

            log_message('debug', 'Buyer Percent : ' . $buyerPercent);
            log_message('debug', 'Buyer Total Fees : ' . $buyerTotalFees);

            $buyer_receiving_amount_after_fees  = $this->DM->safe_minus([$quantity, $buyerTotalFees]);


            /**
             * Credit Fees to admin
             */
            log_message("debug", "---------------------------------------------");
            log_message('debug', 'Start : Admin Fees Credit ');

            $adminPrimaryCoinBalanceDetail     = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->admin_id);

            $referralCommissionPercentRate = $this->CI->WsServer_model->getReferralCommissionRate();

            $isBuyerReferredUser = $this->CI->WsServer_model->isReferredUser($buytrade->user_id);

            log_message("debug", "Is BUYER referred User : " . $isBuyerReferredUser);

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


            log_message('debug', 'End : Admin Fees Credit ');
            log_message("debug", "---------------------------------------------");


            $buyupdate = array(
                'bid_qty_available' => $buyer_av_qty_after_trade,
                // 'amount_available' => $buyer_av_bid_amount_after_trade,
                'fees_amount' => $this->DM->safe_add([$buytrade->fees_amount, $buyerTotalFees]),
                'status' => $newPopexStatus,
            );


            if ($buytrade->is_market) {
                // BUYER MARKET
                // 1. Update Average total Amount
                // 2. Update Average Price

                if ($this->DM->isGreaterThan($buytrade->bid_price, 0)) {
                    $tPrice = $this->DM->safe_add([$buytrade->bid_price, $price]);
                    $averagePrice = $this->DM->safe_division([$tPrice, 2]);
                } else {
                    $averagePrice = $price;
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
                log_message('debug', 'buyer_av_bid_amount_after_trade : ' . $buyer_av_bid_amount_after_trade);
                $buyupdate['amount_available'] = $buyer_av_bid_amount_after_trade;
            }

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
                'fees_amount' => $buyerTotalFees,
                'available_amount' => $buytrade->is_market ? 0 : $buyer_av_bid_amount_after_trade,
                'status' =>  $newPopexStatus,
            );


            log_message('debug', 'debug', 'BUY TRADER LOG -> ' . json_encode($buytraderlog));

            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);

            if ($buytrade->is_market) {
                // NO HOLD USE : TRUE, Direct deduction & credit
                // BUYER WILL PAY SECONDARY COIN AMOUNT
                $this->CI->WsServer_model->get_debit_balance_new($buytrade->user_id, $secondary_coin_id, $trade_amount);

                // BUYER WILL GET PRIMARY COIN AMOUNT
                $this->CI->WsServer_model->get_credit_balance_new($buytrade->user_id, $primary_coin_id, $buyer_receiving_amount_after_fees);
            } else {

                // BUYER WILL GET PRIMARY COIN
                // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTING
                // AND PRIMARY COIN WILL BE CREDITED
                $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount_after_fees, $trade_amount);
            }

            $log_id = $this->CI->WsServer_model->insert_order_log($buytraderlog);

            // $this->after_successful_trade($buytrade->coinpair_id,  $price, $quantity, $success_datetimestamp);

            $this->CI->WsServer_model->update_current_minute_OHLCV($buytrade->coinpair_id, $price, $quantity, $success_datetimestamp);
        } else if ($side == 'SELL') {
            // Sell orders

            $selltrade = (object) $tradeDetail;

            $seller_av_qty_after_trade = $this->DM->safe_minus([$selltrade->bid_qty_available, $quantity]);
            log_message('debug', 'seller_av_qty_after_trade : ' . $seller_av_qty_after_trade);

            $sellerPercent   = $this->_getTakerFees($selltrade->user_id, $primary_coin_id);
            $sellerTotalFees = $this->_calculateFeesAmount($trade_amount, $sellerPercent);

            log_message('debug', 'Seller Fees Percent : ' . $sellerPercent);
            log_message('debug', 'Seller Total Fees : ' . $sellerTotalFees);


            $seller_receiving_amount_after_fees = $this->DM->safe_minus([$trade_amount, $sellerTotalFees]);



            /**
             * Credit Fees to admin
             */
            log_message("debug", "---------------------------------------------");
            log_message('debug', 'Start : Admin Fees Credit ');

            $adminSecondaryCoinBalanceDetail     = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->admin_id);

            $referralCommissionPercentRate = $this->CI->WsServer_model->getReferralCommissionRate();

            $isSellerReferredUser = $this->CI->WsServer_model->isReferredUser($selltrade->user_id);

            log_message("debug", "Is SELLER referred User : " . $isSellerReferredUser);

            // Check if BUYER user is referred user
            if ($isSellerReferredUser && $this->DM->isZeroOrNegative($referralCommissionPercentRate) == false) {
                // Give 10% of commision to referral user Id
                $sellerReferralUserId = $this->CI->WsServer_model->getReferralUserId($selltrade->user_id);
                log_message("debug", "Referral Buyer User Id : " . $sellerReferralUserId);

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
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_FEES_CREDIT, $adminSecondaryCoinBalanceDetail->id, $this->admin_id, $secondary_coin_id, $adminGetsAfterCommission, 0);
            } else {

                $this->CI->WsServer_model->credit_admin_fees_by_coin_id($secondary_coin_id, $sellerTotalFees);
                // Add Admin Balance Log
                $this->CI->WsServer_model->addBalanceLog(BALANCE_LOG_TYPE_TRADE_FEES_CREDIT, $adminSecondaryCoinBalanceDetail->id, $this->admin_id, $secondary_coin_id, $sellerTotalFees, 0);
            }


            log_message('debug', 'End : Admin Fees Credit ');
            log_message("debug", "---------------------------------------------");




            $sellupdate = array(
                'bid_qty_available' => $seller_av_qty_after_trade,
                'fees_amount' => $this->DM->safe_add([$selltrade->fees_amount, $sellerTotalFees]),
                'status' => $newPopexStatus,
            );


            if ($selltrade->is_market) {
                // BUYER MARKET
                // 1. Update Average total Amount
                // 2. Update Average Price

                if ($this->DM->isGreaterThan($selltrade->bid_price, 0)) {
                    $tPrice = $this->DM->safe_add([$selltrade->bid_price, $price]);
                    $averagePrice = $this->DM->safe_division([$tPrice, 2]);
                } else {
                    $averagePrice = $price;
                }

                if ($this->DM->isGreaterThan($selltrade->total_amount, 0)) {
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
                'fees_amount' => $sellerTotalFees,
                'available_amount' => $selltrade->is_market ? 0 : $seller_av_bid_amount_after_trade,
                'status' =>  PopulousWSSConstants::BID_PENDING_STATUS,
            );
            log_message('debug', 'SELL TRADER LOG ->' . json_encode($selltraderlog));

            $log_id = $this->CI->WsServer_model->insert_order_log($selltraderlog);
            log_message('debug', 'Log ID : ' . $log_id);

            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);


            if ($selltrade->is_market) {
                // NO HOLD USE : TRUE, Direct deduction & credit
                // BUYER WILL PAY SECONDARY COIN AMOUNT
                $this->CI->WsServer_model->get_debit_balance_new($selltrade->user_id, $primary_coin_id, $quantity);

                // BUYER WILL GET PRIMARY COIN AMOUNT
                $this->CI->WsServer_model->get_credit_balance_new($selltrade->user_id, $secondary_coin_id, $seller_receiving_amount_after_fees);
            } else {

                $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $quantity, $seller_receiving_amount_after_fees);
            }


            // Updating Current minute OHLCV
            $this->CI->WsServer_model->update_current_minute_OHLCV($selltrade->coinpair_id, $price, $quantity, $success_datetimestamp);
        }



        /**
         * =================
         * EVENTS
         * =================
         */

        try {

            $this->event_order_updated($tradeDetail['id'], $tradeDetail['user_id']);

            if ($log_id != null) {
                $this->event_trade_created($log_id);
            }

            $this->event_market_summary();
        } catch (\Exception $e) {
        }
    }

    /**
     * This will bring back sent binance liquidity to popex back
     * It will unlink the popex order with binance order Id
     * and will cancel the binance order 
     */
    public function _binance_order_unlink_and_cancel($popexOrderId, $symbol)
    {

        log_message('debug', '-----------------------------------------------------');
        log_message('debug', 'Binance Order Unlink and Cancellation started');
        log_message('debug', '-----------------------------------------------------');

        $result = false;

        $binanceOrderDetail = $this->CI->WsServer_model->getBinanceOrderByPopexOrderId($popexOrderId);

        if ($binanceOrderDetail['status'] == PopulousWSSConstants::EXTERNAL_ORDER_INACTIVE_STATUS) {

            log_message('debug', "Binance order is already Unlinked.. ");

            $result = true;
            return $result;
        }

        log_message('debug', "Binance Order Id " . $binanceOrderDetail['binance_order_id']);

        $isUnlinked = $this->CI->WsServer_model->unlinkPopexBinanceOrder($popexOrderId);
        $binanceOrderDetailAfter = $this->CI->WsServer_model->getBinanceOrderByPopexOrderId($popexOrderId);

        log_message('debug', "Unlinked ? : ");
        log_message('debug', $isUnlinked ? 'Yes' : 'No');

        if ($isUnlinked && $binanceOrderDetailAfter['status'] == PopulousWSSConstants::EXTERNAL_ORDER_INACTIVE_STATUS) {
            // Send cancellation order to binance
            $binanceCancelRes = $this->exchanges['BINANCE']->cancelOrder($symbol, $binanceOrderDetail['binance_order_id']);

            if ($binanceCancelRes !== false) {
                log_message('debug', "Binance cancel Response : " .  json_encode($binanceCancelRes));

                if ($binanceCancelRes['status'] == BINANCE_ORDER_STATUS_CANCELED) {
                    log_message('debug', "Binance Order cancelled successfully.");
                    $result = true;
                } else {

                    $isLinkedAgain = $this->CI->WsServer_model->linkPopexBinanceOrder($popexOrderId);
                    // Here multiple things can be happen, 
                    // 1. Order might already got filled
                    // 2. Order might be already got cancelld.
                    log_message('debug', "Binance Order could not cancelled.");
                    log_message('debug', "Linked Back : ");
                    log_message('debug',  $isLinkedAgain  ? 'YES' : 'NO');

                    $result = false;
                }
            } else {
                // Binance order not cancelled, in this case Order will be opened in binance but popex order will be taken to trade
                $isLinkedAgain = $this->CI->WsServer_model->linkPopexBinanceOrder($popexOrderId);
                log_message('debug', "Linked Back : ");
                log_message('debug',  $isLinkedAgain  ? 'YES' : 'NO');
                log_message('debug', "Binance Cancellation failed.");
                $result = false;
            }
        } else {
            // If cancellation not succeded don't use this order 
            log_message('debug', 'Binance order unlink failed.');
            $result = false;
        }

        log_message('debug', '-----------------------------------------------------');
        log_message('debug', 'FINISHED : Binance Order Unlink and Cancellation');
        log_message('debug', '-----------------------------------------------------');

        return $result;
    }


    /**
     * 
     * ALL EVENTS
     * 
     */

    public function broadcastEvents(array $eventsAndData = [])
    {
        if (!$this->broadcasterRequired) return;
        log_message('debug', "....Broadcasting Events....");
        log_message('debug', json_encode($eventsAndData));
        foreach ($eventsAndData as $eventName => $eventData) {
            $this->broadcaster->send(['event' => $eventName, 'data' => $eventData]);
        }
    }

    public function event_order_updated($_orderId, $_userId)
    {


        $events = [];
        $events["API-EVENT:" . PopulousWSSConstants::EVENT_ORDER_UPDATED] = [
            'order_id' => $_orderId,
            'user_id' => $_userId,
        ];

        $this->broadcastEvents($events);
    }


    public function event_trade_created($_logId)
    {
        // EVENT for single trade
        $events = [];
        $events["API-EVENT:" . PopulousWSSConstants::EVENT_TRADE_CREATED] = [
            'log_id' => $_logId,
        ];

        $this->broadcastEvents($events);
    }

    public function event_market_summary()
    {
        $events = [];
        $events["API-EVENT:" . PopulousWSSConstants::EVENT_MARKET_SUMMARY] = [];

        $this->broadcastEvents($events);
    }

    public function event_coinpair_updated($coinpair_id)
    {

        $events = [];
        $events["API-EVENT:" . PopulousWSSConstants::EVENT_COINPAIR_UPDATED] = ['coin_id' => $coinpair_id,];

        $this->broadcastEvents($events);
    }
}
