<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Trade\Trade;

class Buy extends Trade
{
    public function __construct(ServerHandler $server)
    {
        parent::__construct( $server );
        $this->wss_server = $server;
    }
    
    private function _do_buy_trade($buytrade, $selltrade)
    {

        if ($buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS
            && $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS) {

            $coinpair_id = intval($buytrade->coinpair_id);
            
            $primary_coin_id    = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
            $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

            $ps_decimals = $this->CI->WsServer_model->_get_decimals_of_coin($coinpair_id);

            if( $ps_decimals['fetch'] == false ) return FALSE;

            $primary_coin_decimal   = $ps_decimals['primary_decimals'];
            $secondary_coin_decimal = $ps_decimals['secondary_decimals'];

            // $calcQuery = "SELECT t.*, (SELECT( CAST(  t.trade_qty * t.trade_price  as DECIMAL( 24, $secondary_coin_decimal ) )  ) ) as trade_amount  
            //               from ( SELECT LEAST( $selltrade->bid_qty_available, $buytrade->bid_qty_available ) as trade_qty, LEAST(  $selltrade->bid_price, $buytrade->bid_price  ) as trade_price ) as t";

                        

            // $calcResult     = $this->CI->WsServer_model->dbQuery( $calcQuery );

            // if( $calcResult == null ){
            //     return false;
            // }

            // $calcResult = $calcResult->row();
            
            // $trade_qty      = $calcResult->trade_qty;
            // $trade_price    = $calcResult->trade_price;
            // $trade_amount   = $calcResult->trade_amount;

            $trade_qty      = $this->DM->smallest( $selltrade->bid_qty_available, $buytrade->bid_qty_available );
            $trade_price    = $this->DM->smallest( $selltrade->bid_price, $buytrade->bid_price );
           
            $trade_amount   = $this->DM->safe_multiplication( [  $trade_qty, $trade_price ] );


            /**
             * 
             * BUYER will PAY $trade_amount & GET $trade_qty
             * SELLET will PAY $trade_qty & GET $trade_amount
             */

            // BUYER AND SELLER BALANCE UPDATE HERE

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay        = $trade_qty;

            $seller_receiving_amount = $trade_amount; //$this->_safe_math(" $trade_qty * $trade_price ");
            $buyer_will_pay          = $trade_amount ;//$this->_safe_math(" $trade_qty * $trade_price ");

            /**
             * Here buyer always be a TAKER and seller as a MAKER
             */
            $buyerPercent   = $this->_getTakerFees( $buytrade->user_id );
            $buyerTotalFees = $this->_calculateFeesAmount( $buyer_receiving_amount, $buyerPercent );

            $sellerPercent   = $this->_getMakerFees( $selltrade->user_id );
            $sellerTotalFees = $this->_calculateFeesAmount( $seller_receiving_amount, $sellerPercent );

            // $buyer_receiving_amount_after_fees  = $this->_safe_math(" $buyer_receiving_amount - $buyerTotalFees");
            // $seller_receiving_amount_after_fees = $this->_safe_math(" $seller_receiving_amount - $sellerTotalFees");

            $buyer_receiving_amount_after_fees  = $this->DM->safe_minus( [  $buyer_receiving_amount, $buyerTotalFees ] );
            $seller_receiving_amount_after_fees = $this->DM->safe_minus( [  $seller_receiving_amount, $sellerTotalFees ] );



            /**
             * Credit Fees to admin
             */
            $this->CI->WsServer_model->credit_admin_fees_by_coin_id($primary_coin_id, $buyerTotalFees);
            $this->CI->WsServer_model->credit_admin_fees_by_coin_id($secondary_coin_id, $sellerTotalFees);


            // BUYER WILL GET PRIMARY COIN
            // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
            // AND PRIMARY COIN WILL BE CREDITED
            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount_after_fees, $buyer_will_pay); 

            // SELLER WILL GET SECONDARY COIN
            // THE PRIMARY AMOUNT SELLER HAS HOLD WILL BE DEDUCTED
            // AND SECONDARY COIN WILL BE CREDITED
            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amount_after_fees);

            // Credit fees to exchange account

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

            $buyer_av_bid_amount_after_trade = $this->DM->safe_minus( [  $buytrade->amount_available , $trade_amount  ] );
            $seller_av_bid_amount_after_trade = $this->DM->safe_minus( [  $selltrade->amount_available , $trade_amount  ] );
            $buyer_av_qty_after_trade = $this->DM->safe_minus( [  $buytrade->bid_qty_available , $trade_qty  ] );
            $seller_av_qty_after_trade = $this->DM->safe_minus( [  $selltrade->bid_qty_available , $trade_qty ] );
            
            $buyer_qty_fulfilled = $this->DM->safe_minus( [  $buytrade->bid_qty_available , $trade_qty ] );
            $seller_qty_fulfilled = $this->DM->safe_minus( [  $selltrade->bid_qty_available , $trade_qty ] );

            $is_buyer_qty_fulfilled = $this->DM->isZero($buyer_qty_fulfilled);
            $is_seller_qty_fulfilled = $this->DM->isZero($seller_qty_fulfilled);


            $buyupdate = array(
                'bid_qty_available' => $buyer_av_qty_after_trade ,
                'amount_available' => $buyer_av_bid_amount_after_trade,
                'status' => $is_buyer_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $seller_av_qty_after_trade,
                'amount_available' => $seller_av_bid_amount_after_trade,
                'status' => $is_seller_qty_fulfilled  ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

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
                'available_amount' => $buyer_av_qty_after_trade,
                'status' => $is_buyer_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
            
            $log_id = $this->CI->WsServer_model->insert_order_log( $buytraderlog);

            // Updating SL sell orders status and make them available if price changed
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

        $primary_coin_id    = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
        $secondary_coin_id  = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);

        // $price  = $this->_convert_to_decimals($price);
        // $qty    = $this->_convert_to_decimals($qty);

        // $totalAmount =  $this->_safe_math(" $price * $qty ");
        $totalAmount = $this->DM->safe_multiplication( [ $price , $qty ]) ;

        $totalFees   = $this->_calculateTotalFeesAmount( $price, $qty, $coinpair_id, 'BUY' );
        
        $balance_secondary     = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);

        // if ( $this->_safe_math_condition_check(" $balance_secondary->balance >= $totalAmount ") ) {
        if ( $this->DM->isGreaterThanOrEqual($balance_secondary->balance, $totalAmount) ) {

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'BUY',
                'bid_price' => $price,
                'bid_qty' => $qty,
                'bid_qty_available' => $qty,
                'total_amount' => $totalAmount,
                'amount_available' => $totalAmount,
                'coinpair_id' => $coinpair_id,
                'user_id' => $this->user_id,
                'is_stop_limit' => false,
                'open_order' => $open_date,
                'fees_amount' => $totalFees,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                // Event for order creator
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );
                // Transation start
                $this->DB->trans_start();
                try {
                    $buytrade = $this->CI->WsServer_model->get_order($last_id);

                    $this->CI->WsServer_model->get_credit_hold_balance_from_balance($buytrade->user_id, $secondary_coin_id, $totalAmount);

                    $sellers = $this->CI->WsServer_model->get_sellers($price, $coinpair_id);

                    if ($sellers) {

                        // BUYER  : P_UP S_DN
                        // SELLER : P_DN S_UP
                        foreach ($sellers as $key => $selltrade) {

                            $buytrade = $this->CI->WsServer_model->get_order($last_id);
                            $this->_do_buy_trade($buytrade, $selltrade);
                        }

                    } // Salesquery ends here

                    // Transation end
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
        if ($this->DM->isZeroOrNegative($balance_sec->balance) ) {

            $data['isSuccess'] = false;
            $data['message'] = 'Insufficient balance.';
            return $data;
        }

        $user_id = $this->user_id;
    
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
        $totalAmount = $this->DM->safe_multiplication([ $last_price , $qty  ]);
    
        // if ($this->_safe_math_condition_check(" $balance_sec->balance  < $totalAmount ")) {
        if ($this->DM->isLessThan($balance_sec->balance , $totalAmount)) {

            // $maximumBuy = $this->_safe_math(" $balance_sec->balance / $last_price ");
            $maximumBuy = $this->DM->safe_division([ $balance_sec->balance , $last_price ]);

            $data['isSuccess'] = false;
            $data['message'] = "Maximum $maximumBuy $primary_coin_symbol you can buy @ price $last_price.";
            return $data;
        }

        $sellers = $this->CI->WsServer_model->get_sellers($last_price, $coinpair_id);
        $remaining_qty = $qty;

        foreach ($sellers as $selltrade) {

            // $max_buy_qty = $this->_safe_math(" LEAST( $selltrade->bid_qty_available, $remaining_qty ) ");
            $max_buy_qty = $this->DM->smallest( $selltrade->bid_qty_available, $remaining_qty );

            // $totalAmount = $this->_safe_math(" $selltrade->bid_price * $max_buy_qty ");
            $totalAmount = $this->DM->safe_multiplication ([ $selltrade->bid_price , $max_buy_qty ]);
    
            /**
             * 
             * Calculate fees
             */
            $totalFees = $this->_calculateTotalFeesAmount( $selltrade->bid_price , $max_buy_qty, $coinpair_id, 'BUY' );


            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'BUY',
                'bid_price' => $selltrade->bid_price,
                'bid_qty' => $max_buy_qty,
                'bid_qty_available' => $max_buy_qty,
                'total_amount' => $totalAmount,
                'amount_available' => $totalAmount,
                'coinpair_id' => $coinpair_id,
                'user_id' => $this->user_id,
                'is_stop_limit' => false,
                'open_order' => $open_date,
                'fees_amount' => $totalFees,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);
            $this->wss_server->_event_push(
                PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                [
                    'coin_id' => $coinpair_id,
                ]
            );

            if ($last_id) {
                // Transation start
                $this->DB->trans_start();
                try {
                    $buytrade = $this->CI->WsServer_model->get_order($last_id);

                    // BUYER : HOLD SECONDARY COIN
                    $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $totalAmount);

                    $this->_do_buy_trade($buytrade, $selltrade);

                    // Transation end
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
                $remaining_qty = $this->DM->safe_minus([ $remaining_qty , $max_buy_qty ]);

                // if ($this->_safe_math_condition_check(" $remaining_qty <= 0 ")) {
                if ($this->DM->isZeroOrNegative($remaining_qty)) {
                    // ALL QTY BOUGHT
                    break; // Come out of for loop everything is bought
                }
            }

            // Updating SL sell orders status and make them available if price changed
            $this->CI->WsServer_model->update_stop_limit_status ( $coinpair_id );

        }// Sellers loop ends

        // Create new order if qty remained
        if ($this->DM->isGreaterThan( $remaining_qty, 0 ) ) {

            $open_date = date('Y-m-d H:i:s');
            $last_trade_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);

            // $totalAmount = $this->_safe_math(" $last_trade_price * $remaining_qty ");
            $totalAmount = $this->DM->safe_multiplication( [ $last_trade_price , $remaining_qty ]);
    
            /**
             * 
             * Calculate fees
             */
            $totalFees = $this->_calculateTotalFeesAmount( $last_trade_price , $remaining_qty, $coinpair_id, 'BUY' );
            


            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'BUY',
                'bid_price' => $last_trade_price,
                'bid_qty' => $remaining_qty,
                'bid_qty_available' => $remaining_qty,
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
                // Transation start
                $this->DB->trans_start();
                try {
                    // HOLD PRIMARY
                    $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $totalAmount);

                    // $boughtAmount = $this->_safe_math(" $qty - $remaining_qty ");
                    $boughtAmount = $this->DM->safe_minus([ $qty , $remaining_qty ]);

                    // Transation end
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

                $data['isSuccess'] = true;
                $data['message'] = $this->_format_number($boughtAmount, $coin_details->primary_decimals) . ' Bought. ' .
                $this->_format_number($remaining_qty, $coin_details->primary_decimals) . '  created open order at price ' .
                $this->_format_number($last_trade_price, $coin_details->secondary_decimals);
                return $data;
            } else {
                $data['isSuccess'] = false;
                $data['message'] = 'Could not create open buy order for ' .
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
        if ($this->DM->isGreaterThanOrEqual( $stop, $last_price )) {


            $is_take_profit = true;
            $is_stop_loss = false;
        // } else if ($this->_safe_math_condition_check(" $stop <= $last_price ")) {
        } else if ($this->DM->isLessThanOrEqual( $stop, $last_price )) {
            
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
        $totalAmount = $this->DM->safe_multiplication( [  $qty , $limit ]);


        $availableAmount = $balance_sec->balance;

        // if ($this->_safe_math_condition_check(" $availableAmount < $totalAmount ")) {
        if ($this->DM->isLessThan($availableAmount, $totalAmount ) ) {           
            // Low balance
            $qtyNeeded = $this->_safe_math(" $totalAmount - $availableAmount ");
            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance, More $qtyNeeded needed to create an order.";
            return $data;
        }

        // Enought amount to place order without checking fees
        
        $totalFees   = $this->_calculateTotalFeesAmount( $limit, $qty, $coinpair_id, 'BUY' );

        $last_price = $this->CI->WsServer_model->get_last_trade_price($coinpair_id);

        // Create one function for holding funds

        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => 'BUY',
            'bid_price' => $limit,
            'bid_price_limit' => $limit,
            'bid_price_stop' => $stop,
            'bid_qty' => $qty,
            'bid_qty_available' => $qty,
            'total_amount' => $totalAmount,
            'amount_available' => $totalAmount,
            'coinpair_id' => $coinpair_id,
            'user_id' => $user_id,
            'open_order' => $open_date,
            'fees_amount' => $totalFees,
            'is_stop_limit' => 1,
            'stop_condition' => $condition,
            'status' => PopulousWSSConstants::BID_QUEUED_STATUS,
        );

        $last_id = $this->CI->WsServer_model->insert_order($tadata);

        // Transation start
        $this->DB->trans_start();
        try {
            // Updating SL sell orders status and make them available if price changed
            $this->CI->WsServer_model->update_stop_limit_status($coinpair_id);

            // Transation end
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
        } catch (Exception $e) {
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
            // Transation start
            $this->DB->trans_start();
            try {
                // BUYER : HOLD SECONDARY COIN
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $totalAmount);

                // Transation end
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
