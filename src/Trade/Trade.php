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

    public function __construct( ServerHandler $server )
    {
        $this->CI = &get_instance();
        $this->wss_server = $server;

        $this->CI->load->model([
            'WsServer_model',
        ]);

        $this->CI->load->library("PopDecimalMath",null,'decimalmaths');

        $this->DM =& $this->CI->decimalmaths;
        $this->DB = $this->CI->db;
        $this->admin_id = getenv('ADMIN_USER_ID');
    }
    


    protected function _referral_user_balance_update($user_id, $coin_id, $amount )
    {

        log_message( "debug", "---------------------------------------------" );
        log_message( "debug", "START : Referral User Balance Update" );
        log_message( "debug", "Referral User Id : ". $user_id );

        $this->CI->WsServer_model->get_credit_balance_new($user_id, $coin_id, $amount);

        log_message( "debug", "END : Referral User Balance Update" );
        log_message( "debug", "---------------------------------------------") ;
    }

    protected function _buyer_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {

        log_message( "debug", "---------------------------------------------" );
        log_message( "debug", "START : Buyer Balance Update" );
        // P_DN
        $this->CI->WsServer_model->get_debit_hold_balance_new($user_id, $secondary_coin_id, $secondary_amount);
        // S_UP
        $this->CI->WsServer_model->get_credit_balance_new($user_id, $primary_coin_id, $primary_amount);

        log_message( "debug", "END : Buyer Balance Update" );
        log_message( "debug", "---------------------------------------------") ;
    }

    protected function _seller_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {

        log_message( "debug", "---------------------------------------------") ;
        log_message( "debug", "START : Seller Balance Update" );

        // P_DN
        $this->CI->WsServer_model->get_debit_hold_balance_new($user_id, $primary_coin_id, $primary_amount);
        // S_UP
        $this->CI->WsServer_model->get_credit_balance_new($user_id, $secondary_coin_id, $secondary_amount);
        
        log_message( "debug", "END : Seller Balance Update" );
        log_message( "debug", "---------------------------------------------") ;
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

        $a = $this->_validate_decimals( $number, $decimals )  ;
        $b = $this->DM->isZeroOrNegative($number) == false ; 
        $c = $this->_validate_decimal_range($number, $decimals) ;

        return  $a && $b && $c;
    }

    protected function _validate_secondary_value_decimals($number, $decimals)
    {

        $a = $this->_validate_decimals( $number, $decimals )  ;
        $b = $this->DM->isZeroOrNegative($number) == false ; 
        $c = $this->_validate_decimal_range($number, $decimals) ;

        return  $a && $b && $c;

    }

    protected function _validate_decimal_range($number, $decimals){
        
        $_max = $this->max_value($decimals);
        $_min = $this->min_value($decimals);

        $isGreater = $this->DM->isGreaterThanOrEqual( $number , $_min  );
        $isLess = $this->DM->isLessThanOrEqual( $number , $_max  );

        return $isGreater && $isLess;
    }

    protected function _validate_decimals( $number, $decimals ){

        $number = (string) $number;
        $decimals = intval($decimals);

        $l = (int) strlen(substr(strrchr($number, "."), 1));        
        if( $l <= $decimals ) return TRUE;
        
        return FALSE;
    }

    public function max_value( $decimals ){

        $_str = '';

        for ($i = 1; $i <= 8; $i++) {$_str .= '9';}
        $_str .= '.';
        for ($j = 1; $j <= (int) $decimals; $j++) {$_str .= '9';}

        return $_str;


    }

    public function min_value( $decimals ){
        
        $_str = '0.';

        for( $i = 1; $i < $decimals ; $i++ ){
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
    public function _getMakerFees( $userId ){

        /*
        Note : As per discussion with JASON we will be using stack maker taker default
        
        $makerFeesPercentRes = $this->CI->WsServer_model->get_fees_by_coin_id('MAKER', $coinId);

        $standardFeesPercent = $makerFeesPercentRes != null ? floatval( $makerFeesPercentRes->fees ) : 0;

        if( $standardFeesPercent == 0 ) return 0;

        */

        /**
         * Getting Stack maker percentage, if eligible
         */
        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages(  $userId  );

        if( $mt['maker'] == 0 ) return 0;
        return $mt['maker'];

    }


    /**
     * Return Taker fees according to fees stack
     */
    public function _getTakerFees( $userId ){

        /*
        Note : As per discussion with JASON we will be using stack maker taker default

        $takerFeesPercentRes = $this->CI->WsServer_model->get_fees_by_coin_id('TAKER', $coinId);

        $standardFeesPercent = $takerFeesPercentRes != null ? floatval( $takerFeesPercentRes->fees ) : 0;

        if( $standardFeesPercent == 0 ) return 0;
        */

        /**
         * Getting Stack taker percentage, if eligible
         */
        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages(  $userId  );

        if( $mt['taker'] == 0 ) return 0;
        return $mt['taker'];

    }

    
    /**
     * Returns calculated fees in amount
     */
    public function _calculateFeesAmount( $totalAmount, $feesPercent ){
        
        $totalFees = 0;

        if( $feesPercent != 0 ){
            // $totalFees = $this->_safe_math("  ( $totalAmount * $feesPercent)/100  ");
            
            $a1 = $this->DM->safe_multiplication( [  $totalAmount, $feesPercent ] );            
            $totalFees = $this->DM->safe_division( [ $a1, 100 ] ) ;
        }else{
            $totalFees = 0;
        }

        return $totalFees;        

    }

    /**
     * Returns total fees require
     */

    public function _calculateTotalFeesAmount( $price, $qty, $coinpairId, $tradeType  ){

        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages( $this->user_id );

        $makerDiscountPercent = $mt['maker'];
        $takerDiscountPercent = $mt['taker'];

        if( $tradeType == 'BUY' ){
            $orderbookQty = $this->CI->WsServer_model->get_available_qty_in_sell_orders_within_price($price, $coinpairId);
        }else if( $tradeType == 'SELL' ){
            $orderbookQty = $this->CI->WsServer_model->get_available_qty_in_buy_orders_within_price($price, $coinpairId);
        }

        log_message('debug', "" );
        log_message('debug', "" );
        log_message('debug', "___________Calculate Total Fees Amount___________" );

        
        $totalFees = 0;
        $totalExchange = $this->DM->safe_multiplication([ $price, $qty ]);


        $makerFeesPercent =  $this->_getMakerFees( $this->user_id );
        $takerFeesPercent =  $this->_getTakerFees( $this->user_id );

        // if( $this->_safe_math_condition_check(" $orderbookQty = 0 ") ){
        if( $this->DM->isZero($orderbookQty)   ){
            // NO QTY available in O.B
            // FULL MAKER
            log_message('debug', "-----------------------------------" );
            log_message('debug', "FULL MAKER FEES CALCULATIONS" );
                        
            $makerFeesPercent    = $this->_getMakerFees( $this->user_id );
            $totalFees           = $this->_calculateFeesAmount( $totalExchange, $makerFeesPercent );
            
            log_message('debug', "Maker Fees Percent : ". $makerFeesPercent);
            log_message('debug', "Total Fees : ". $totalFees);
            log_message('debug', "-----------------------------------" );

        // }else if ( $this->_safe_math_condition_check(" $orderbookQty >= $qty ") ){
        }else if ( $this->DM->isGreaterThanOrEqual($orderbookQty, $qty)  ){
            // ALL QTY available in O.B
            // FULL TAKER

            log_message('debug', "-----------------------------------" );
            log_message('debug', "FULL TAKER FEES CALCULATIONS" );

            $takerFeesPercent    = $this->_getTakerFees( $this->user_id );
            $totalFees           = $this->_calculateFeesAmount( $totalExchange, $takerFeesPercent );

            log_message('debug', "Taker Fees Percent : ". $takerFeesPercent);
            log_message('debug', "Total Fees : ". $totalFees);
            log_message('debug', "-----------------------------------" );


        }else{
            // PARTIAL MAKER & TAKER

            // $maker_qty =  $this->_safe_math(" $qty - $orderbookQty  ");
            $maker_qty =  $this->DM->safe_minus( [ $qty , $orderbookQty ]);
            $taker_qty =  $orderbookQty;
            
            // $maker_amount = $this->_safe_math(" $maker_qty * $price  ");
            // $taker_amount = $this->_safe_math(" $taker_qty * $price  ");

            $maker_amount = $this->DM->safe_multiplication([ $maker_qty , $price  ]);
            $taker_amount = $this->DM->safe_multiplication([ $taker_qty , $price  ]);


            $makerFees = $this->_calculateFeesAmount( $maker_amount, $makerFeesPercent );
            $takerFees = $this->_calculateFeesAmount( $taker_amount, $takerFeesPercent );

            log_message('debug', "-----------------------------------" );
            log_message('debug', "PARTIAL FEES CALCULATIONS" );
            log_message('debug', "MAKER Fees : ". $makerFees );
            log_message('debug', "TAKER Fees : ". $takerFees );

            // $totalFees =  $this->_safe_math(" $makerFees + $takerFees  ");
            $totalFees = $this->DM->safe_add([ $makerFees, $takerFees ]);
            log_message('debug', "Total Fees : ". $totalFees );
            log_message('debug', "-----------------------------------" );


        }

        log_message('debug', "" );
        log_message('debug', "" );
        

        return $totalFees;
        
    }

    


    // ORDER Related     

    public function cancel_order($order_id, $auth, $rData) {
        
        $user_id = $this->_get_user_id($auth);
        $ip_address = $rData['ip_address'];

        $data = [
            'isSuccess' => true,
            'message' => '',
        ];
        log_message('debug', '--------DO CANCEL ORDER START--------');

        $orderdata = $this->CI->WsServer_model->get_order($order_id);

        if ($user_id != $orderdata->user_id) {
           
            log_message('debug', 'Order not related to this user. Auth Key '.$auth);
           
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
                
                log_message('debug', 'Order Type : '.$orderdata->bid_type);

                $refund_amount = '';
                if ($orderdata->bid_type == 'SELL') {
                    $currency_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
                    $refund_amount = $orderdata->bid_qty_available;
                } else {
                    $currency_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);                    
                    $refund_amount = $this->DM->safe_multiplication ([ $orderdata->bid_qty_available , $orderdata->bid_price]);
                }

                log_message('debug', 'Refund Amount : '.$refund_amount );
    
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

                log_message('debug', '----- Crediting balance -----' );

                $this->CI->WsServer_model->get_credit_balance_new($orderdata->user_id, $currency_id, $refund_amount);
                // Release hold balance
                
                log_message('debug', '----- Releasing Hold balance -----' );
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
}