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

        if ($buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS &&
            $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS) {

            $coinpair_id = intval($selltrade->coinpair_id);
            
            $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
            $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

            $ps_decimals = $this->CI->WsServer_model->_get_decimals_of_coin($coinpair_id);

            if( $ps_decimals['fetch'] == false ) return FALSE;

            $primary_coin_decimal   = $ps_decimals['primary_decimals'];
            $secondary_coin_decimal = $ps_decimals['secondary_decimals'];

            /*

            $calcQuery = "SELECT t.*, (SELECT( CAST(  t.trade_qty * t.trade_price  as DECIMAL( 24, $secondary_coin_decimal ) )  ) ) as trade_amount  
                          from ( SELECT LEAST( $selltrade->bid_qty_available, $buytrade->bid_qty_available ) as trade_qty, GREATEST(  $selltrade->bid_price, $buytrade->bid_price  ) as trade_price ) as t";


            $calcResult     = $this->CI->WsServer_model->dbQuery( $calcQuery );

            if( $calcResult == null ){
                return false;
            }

            $calcResult = $calcResult->row();

            $trade_qty      = $calcResult->trade_qty;// $this->_safe_math(" LEAST( $selltrade->bid_qty_available, $buytrade->bid_qty_available ) ");
            $trade_price    = $calcResult->trade_price;// $this->_safe_math(" LEAST( $selltrade->bid_price, $buytrade->bid_price ) ");
            $trade_amount   = $calcResult->trade_amount;// $this->_safe_math(" $trade_qty * $trade_price ");

            */


            $trade_qty      = $this->DM->smallest( $selltrade->bid_qty_available, $buytrade->bid_qty_available );
            $trade_price    = $this->DM->biggest( $selltrade->bid_price, $buytrade->bid_price );
            $trade_amount   = $this->DM->safe_multiplication( [  $trade_qty, $trade_price ] );

            /**
             * 
             * SELLET will PAY $trade_qty & GET $trade_amount
             * BUYER will PAY $trade_amount & GET $trade_qty
             */

            // BUYER AND SELLER BALANCE UPDATE HERE

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay        = $trade_qty;
            
            $seller_receiving_amount = $trade_amount; //$this->_safe_math(" $trade_qty * $trade_price ");
            $buyer_will_pay          = $trade_amount; //$this->_safe_math(" $trade_qty * $trade_price ");


            /**
             * FEES Deduction
             */

            /**
             * Here buyer always be a TAKER and seller as a MAKER
             */
            $buyerPercent   = $this->_getMakerFees( $buytrade->user_id, $primary_coin_id );
            $buyerTotalFees = $this->_calculateFeesAmount( $buyer_receiving_amount, $buyerPercent );

            $sellerPercent   = $this->_getTakerFees( $this->user_id, $primary_coin_id );
            $sellerTotalFees = $this->_calculateFeesAmount( $seller_receiving_amount, $sellerPercent );


            // $buyer_receiving_amount_after_fees  = $this->_safe_math(" $buyer_receiving_amount - $buyerTotalFees");
            // $seller_receiving_amount_after_fees = $this->_safe_math(" $seller_receiving_amount - $sellerTotalFees");

            $buyer_receiving_amount_after_fees  = $this->DM->safe_minus([ $buyer_receiving_amount, $buyerTotalFees ]);
            $seller_receiving_amount_after_fees = $this->DM->safe_minus([ $seller_receiving_amount , $sellerTotalFees ]);

            /**
             * Credit Fees to admin
             */
            $this->CI->WsServer_model->credit_admin_fees_by_coin_id($primary_coin_id, $buyerTotalFees);
            $this->CI->WsServer_model->credit_admin_fees_by_coin_id($secondary_coin_id, $sellerTotalFees);


            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount, $buyer_will_pay);

            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amount);


            /*
            $calcQuery = " SELECT 
                ( $buytrade->amount_available  - $trade_amount ) as buyer_av_bid_amount_after_trade,
                ( $selltrade->amount_available  - $trade_amount ) as seller_av_bid_amount_after_trade,
                ( $buytrade->bid_qty_available - $trade_qty ) as buyer_av_qty_after_trade,
                ( $selltrade->bid_qty_available - $trade_qty ) as seller_av_qty_after_trade,
                ( ($buytrade->bid_qty_available - $trade_qty) <= 0 ) as is_buyer_qty_fulfilled,
                ( ( $selltrade->bid_qty_available - $trade_qty ) <= 0  ) as is_seller_qty_fulfilled
            ";

            $calcResult     = $this->CI->WsServer_model->dbQuery( $calcQuery )->row();

            */


            $buyer_av_bid_amount_after_trade = $this->DM->safe_minus([ $buytrade->amount_available  , $trade_amount ]);
            $seller_av_bid_amount_after_trade = $this->DM->safe_minus([ $selltrade->amount_available  , $trade_amount ]);            
            $buyer_av_qty_after_trade = $this->DM->safe_minus([ $buytrade->bid_qty_available , $trade_qty ]);
            $seller_av_qty_after_trade = $this->DM->safe_minus([ $selltrade->bid_qty_available , $trade_qty ]);

            $buyer_qty_fulfilled = $this->DM->safe_minus( [  $buytrade->bid_qty_available , $trade_qty ] );
            $seller_qty_fulfilled = $this->DM->safe_minus( [  $selltrade->bid_qty_available , $trade_qty ] );

            $is_buyer_qty_fulfilled = $this->DM->isZero($buyer_qty_fulfilled);
            $is_seller_qty_fulfilled = $this->DM->isZero($seller_qty_fulfilled);


            $buyupdate = array(
                'bid_qty_available' => $buyer_av_qty_after_trade,
                'amount_available' => $buyer_av_bid_amount_after_trade, //Balance added buy account
                'status' =>  $is_buyer_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $seller_av_bid_amount_after_trade, // ( $trade_qty - $selltrade->bid_qty_available  <= 0 ) ? 0 : $trade_qty - $selltrade->bid_qty_available  , //(($buytrade->bid_qty_available-$selltrade->bid_qty_available)<0)?0:$buytrade->bid_qty_available-$selltrade->bid_qty_available,
                'amount_available' => $seller_av_bid_amount_after_trade, //  ((( $trade_qty - $selltrade->bid_qty_available  )<= 0) ? 0: $trade_qty - $selltrade->bid_qty_available ) * $selltrade->bid_price, //Balance added seller account
                'status' => $is_seller_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

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
                'available_amount' => $seller_av_bid_amount_after_trade, // $seller_available_bid_amount_after_trade,
                'status' => $is_seller_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS ,
            );

            // Update coin history
            // $this->CI->WsServer_model->update_coin_history($coinpair_id, $trade_qty, $trade_price);

            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
            
            $log_id = $this->CI->WsServer_model->insert_order_log( $selltraderlog);

            // UPDATE SL Order
            $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);

            // Updating Current minute OHLCV
            $this->CI->WsServer_model->update_current_minute_OHLCV( $coinpair_id, $trade_price, $trade_qty, $success_datetimestamp );
            
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
                    PopulousWSSConstants::EVENT_MARKET_SUMMARY,[]
                );
            } catch (Exception $e) {

            }

            return true;
        }
        return false;
    }


    private function _getMakerFees( $userId,  $coinId ){

        $makerFeesPercentRes = $this->CI->WsServer_model->get_fees_by_coin_id('MAKER', $coinId);

        $standardFeesPercent = $makerFeesPercentRes != null ? floatval( $makerFeesPercentRes->fees ) : 0;

        if( $standardFeesPercent == 0 ) return 0;

        /**
         * Getting Stack maker percentage, if eligible
         */
        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages(  $userId  );

        if( $mt['maker'] == 0 ) return $standardFeesPercent;
        return $mt['maker'];

    }

    private function _getTakerFees( $userId,  $coinId ){

        $takerFeesPercentRes = $this->CI->WsServer_model->get_fees_by_coin_id('TAKER', $coinId);

        $standardFeesPercent = $takerFeesPercentRes != null ? floatval( $takerFeesPercentRes->fees ) : 0;

        if( $standardFeesPercent == 0 ) return 0;

        /**
         * Getting Stack taker percentage, if eligible
         */
        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages(  $userId  );

        if( $mt['taker'] == 0 ) return $standardFeesPercent;
        return $mt['taker'];

    }

    /**
     * Returns calculated fees in amount
     */
    private function _calculateFeesAmount( $totalAmount, $feesPercent ){
        
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

    private function _calculateTotalFeesAmount( $price, $qty, $coinpairId, $primaryCoinId  ){
        
        // $totalAmount = $this->_safe_math(" $price * $qty ");
        $totalAmount = $this->DM->safe_multiplication([  $price , $qty ]);

        $mt = $this->CI->WsServer_model->get_maker_taker_discount_percentages( $this->user_id );

        $makerDiscountPercent = $mt['maker'];
        $takerDiscountPercent = $mt['taker'];

        $available_orderbook_buy_Qty = $this->CI->WsServer_model->get_available_qty_in_buy_orders_within_price($price, $coinpairId);
        
        $totalFees = 0;

        // if( $this->_safe_math_condition_check(" $available_orderbook_buy_Qty = 0 ") ){
        if( $this->DM->isZero($available_orderbook_buy_Qty) ){
            // NO QTY available in O.B
            // FULL MAKER
            
            $feesPercent    = $this->_getMakerFees( $this->user_id, $primaryCoinId );
            $totalFees      = $this->_calculateFeesAmount( $totalAmount, $feesPercent );

        // }else if ( $this->_safe_math_condition_check(" $available_orderbook_buy_Qty >= $qty ") ){
        }else if ( $this->DM->isGreaterThanOrEqual ( $available_orderbook_buy_Qty , $qty ) ){
            // ALL QTY available in O.B
            // FULL TAKER

            $feesPercent    = $this->_getTakerFees( $this->user_id, $primaryCoinId );
            $totalFees      = $this->_calculateFeesAmount( $totalAmount, $feesPercent );

        }else{
            // PARTIAL MAKER & TAKER
            // $maker_qty =  $this->_safe_math(" $qty - $available_orderbook_buy_Qty  ");
            $maker_qty =  $this->DM->safe_minus ([ $qty , $available_orderbook_buy_Qty ] );
            $taker_qty =  $available_orderbook_buy_Qty;

            // $maker_amount = $this->_safe_math(" $maker_qty * $price  ");
            // $taker_amount = $this->_safe_math(" $taker_qty * $price  ");
            $maker_amount = $this->DM->safe_multiplication( [ $maker_qty , $price ] );
            $taker_amount = $this->DM->safe_multiplication( [ $taker_qty , $price  ]);

            $makerFeesPercent =  $this->_getMakerFees( $this->user_id, $coinId );
            $takerFeesPercent =  $this->_getTakerFees( $this->user_id, $coinId );

            $makerFees = $this->_calculateFeesAmount( $maker_amount, $makerFeesPercent );
            $takerFees = $this->_calculateFeesAmount( $taker_amount, $takerFeesPercent );

            // $totalFees = $this->_safe_math(" $makerFees + $takerFees  ");


            $totalFees = $this->DM->safe_multiplication([  $makerFees , $makerFees ] );

        }

        return $totalFees;
        
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
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

        // $price = $this->_convert_to_decimals($price);
        // $qty = $this->_convert_to_decimals($qty);

        // $totalAmount = $this->_safe_math(" $price * $qty ");
        $totalAmount = $this->DM->safe_multiplication([  $price , $qty ]);


        $totalFees = $this->_calculateTotalFeesAmount( $price, $qty, $coinpair_id, $primary_coin_id );
        
        $balance_primary = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);
        
        // if ($this->_safe_math_condition_check(" $balance_primary->balance >= $qty ")) {
        if ($this->DM->isGreaterThanOrEqual(  $balance_primary->balance , $qty )) {

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'SELL',
                'bid_price' => $price,
                'bid_qty' => $qty,
                'bid_qty_available' => $qty,
                'total_amount' => $totalAmount,
                'amount_available' => $totalAmount,
                'coinpair_id' => $coinpair_id,
                'user_id' => $this->user_id,
                'open_order' => $open_date,
                'fees_amount' => $totalFees,
                'status' => BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                $selltrade = $this->CI->WsServer_model->get_order($last_id);

                // SELLER BALANCE P_DN & S_UP
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($selltrade->user_id, $primary_coin_id, $qty);

                // Event for order creator                
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );


                $buyers = $this->CI->WsServer_model->get_buyers($price, $coinpair_id);
                // var_dump($buyers);
                if ($buyers) {

                    foreach ($buyers as $key => $buytrade) {

                        // Provide updated sell trade here
                        $selltrade = $this->CI->WsServer_model->get_order($last_id);

                        // SELLING TO BUYER
                        $this->_do_sell_trade($selltrade, $buytrade);

                        // Updating SL buy order status and make them available if price changed
                        // $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);

                    } // End of buytradequery Loop

                }

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
                $data['message'] = 'Sell order successfully placed.';

                return $data;

            } else {

                $data['isSuccess'] = false;
                $data['message'] = 'Trade could not submitted.';
                return $data;
            }
        } else {

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
        if ($this->DM->isZeroOrNegative( $available_prim_balance )) {

            $data['isSuccess'] = false;
            $data['message'] = 'Insufficient balance.';
            return $data;
        }

        $user_id = $this->user_id;

        $count_buy_orders = $this->CI->WsServer_model->count_buy_orders_by_coin_id($coinpair_id);

        if ($count_buy_orders < 1) {
            // No sell orders available, use initial price
            $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);
        } else {
            $lowest_buyer_price = $this->CI->WsServer_model->get_lowest_price_in_buyer($coinpair_id);
            if ($lowest_buyer_price != null) {
                $last_price = $lowest_buyer_price;
            }

        }
    
        /**
         * 
         * Calculate fees
         */
        $totalFees = $this->_calculateTotalFeesAmount( $last_price , $qty, $coinpair_id, $primary_coin_id );
        
        // if ($this->_safe_math_condition_check("$qty > $available_prim_balance")) {
        if ($this->DM->isGreaterThan( $qty, $available_prim_balance )) {
            $data['isSuccess'] = false;
            $data['message'] = "Insufficient balance ";
            return $data;
        }

        // Updating SL buy order status and make them available if price changed
        // $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);

        $buyers = $this->CI->WsServer_model->get_buyers($last_price, $coinpair_id);
        $remaining_qty = $qty;

        foreach ($buyers as $key => $buytrade) {

            // $max_sell_qty = $this->_safe_math("LEAST( $buytrade->bid_qty_available, $remaining_qty ) ");
            $max_sell_qty = $this->DM->smallest( $buytrade->bid_qty_available, $remaining_qty );


            // $totalAmount = $this->_safe_math(" $buytrade->bid_price * $max_sell_qty ");
            $totalAmount = $this->DM->safe_multiplication( [ $buytrade->bid_price , $max_sell_qty ]);

            /**
             * 
             * Calculate fees
             */
            $totalFees = $this->_calculateTotalFeesAmount( $buytrade->bid_price , $max_sell_qty, $coinpair_id, $secondary_coin_id );
            

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'SELL',
                'bid_price' => $buytrade->bid_price,
                'bid_qty' => $max_sell_qty,
                'bid_qty_available' => $max_sell_qty,
                'total_amount' => $totalAmount,
                'amount_available' => $totalAmount,
                'coinpair_id' => $coinpair_id,
                'user_id' => $user_id,
                'open_order' => $open_date,
                'fees_amount' => $totalFees,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                $selltrade = $this->CI->WsServer_model->get_order($last_id);

                // SELLER BALANCE P_DN & S_UP
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($selltrade->user_id, $primary_coin_id, $max_sell_qty);

                // SELLING TO BUYER
                $this->_do_sell_trade($selltrade, $buytrade);

                // $remaining_qty = $this->_safe_math(" $remaining_qty - $max_sell_qty ");
                $remaining_qty = $this->DM->safe_minus( [ $remaining_qty , $max_sell_qty ] );

                // if ($this->_safe_math_condition_check(" $remaining_qty <= 0 ")) {
                if ($this->DM->isZeroOrNegative( $remaining_qty)) {
                
                    // ALL QTY SOLD
                    break; // Come out of for loop everything is old
                }
            }

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
        }

        // if ($this->_safe_math_condition_check("$remaining_qty > 0 ")) {
        if ($this->DM->isGreaterThan( $remaining_qty , 0 )) {

            
            // Create new open order
            $open_date = date('Y-m-d H:i:s');
            $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);


            // $totalAmount = $this->_safe_math(" $last_price * $remaining_qty ");
            $totalAmount = $this->DM->safe_multiplication([ $last_price , $remaining_qty ] );
            
            /**
             * 
             * Calculate fees
             */
            $totalFees = $this->_calculateTotalFeesAmount( $last_price , $remaining_qty, $coinpair_id, $primary_coin_id );


            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'SELL',
                'bid_price' => $last_price,
                'bid_qty' => $remaining_qty,
                'bid_qty_available' => $remaining_qty,
                'total_amount' => $totalAmount,
                'amount_available' => $totalAmount,
                'coinpair_id' => $coinpair_id,
                'user_id' => $this->user_id,
                'open_order' => $open_date,
                'fees_amount' => $totalFees,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {
                // HOLD PRIMARY
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $primary_coin_id, $remaining_qty);
                // Event for order creator
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );

                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $coinpair_id,
                    ]
                );

                // $soldAmount = $this->_safe_math(" $qty - $remaining_qty ");
                $soldAmount = $this->DM->safe_minus( [ $qty, $remaining_qty ]);


                $data['isSuccess'] = true;
                $data['message'] = $soldAmount . ' Sold. ' . $remaining_qty . '  created open order at price ' . $last_price;
                return $data;

            } else {
                $data['isSuccess'] = false;
                $data['message'] = 'Could not create open sell order for ' .
                $this->_format_number($remaining_qty, $coin_details->primary_decimals);
                return $data;
            }

        } else {

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
            $data['message'] = 'All ' . $this->_format_number($qty, $coin_details->primary_decimals) .
                ' bought successfully';
            return $data;
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
        if ( $this->DM->isGreaterThanOrEqual( $stop , $last_price ) ) {

            $is_take_profit = true;
            $is_stop_loss = false;
        // } else if ($this->_safe_math_condition_check(" $stop <= $last_price ")) {
        } else if ($this->DM->isLessThanOrEqual( $stop , $last_price )) {

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
        if ($this->DM->isGreaterThan( $qty , $balance_prim->balance )) {

            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance.";
            return $data;
        }

        // $totalAmount = $this->_safe_math(" $limit * $qty ");
        $totalAmount = $this->DM->safe_multiplication([ $limit , $qty  ]);

        $totalFees = $this->_calculateTotalFeesAmount( $limit, $qty, $coinpair_id, $primary_coin_id );


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

            // BUYER : HOLD SECONDARY COIN
            $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $primary_coin_id, $qty);

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
