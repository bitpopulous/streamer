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
        parent::__construct();
        $this->wss_server = $server;
    }

    private function _do_sell_trade($selltrade, $buytrade)
    {

        if ($buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS &&
            $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS) {

            $coin_id = intval($selltrade->coinpair_id);
            
            $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
            $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);

            $trade_qty = $this->_safe_math("LEAST( $selltrade->bid_qty_available, $buytrade->bid_qty_available )");

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay = $trade_qty;

            $seller_receiving_amount = $this->_safe_math(" $trade_qty * $selltrade->bid_price ");
            $buyer_will_pay = $this->_safe_math(" $trade_qty * $buytrade->bid_price ");

            // Fees will be deducted here according to whatever qty being sold

            $sellfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('SELL', $primary_coin_id);

            $fees_percent = 0;
            if ($sellfeesquery) {
                $fees_percent = $sellfeesquery->fees;
            }

            $fees_amount = 0;
            $seller_receiving_amountAfterFees = $seller_receiving_amount;
            if ($fees_percent != 0) {
                $fees_amount = $this->_safe_math("  ( $seller_receiving_amount  * $fees_percent ) / 100 ");
                if ($seller_receiving_amount > 10000) {
                    $fees_amount = $this->_safe_math(" $fees_amount * (100 - $fees_balance_discount ) / 100");
                }
                $seller_receiving_amountAfterFees = $this->_safe_math(" $seller_receiving_amount - $fees_amount ");
            }

            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount, $buyer_will_pay);
            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amountAfterFees);

            // Credit fees to exchange account
            // User 2 : WIRWY5
            $this->CI->WsServer_model->credit_admin_fees_by_coin_id($secondary_coin_id, $fees_amount);

            $buyer_available_bid_amount_after_trade = $this->_safe_math(" $buytrade->amount_available  - ($trade_qty * $buytrade->bid_price ) ");
            $seller_available_bid_amount_after_trade = $this->_safe_math(" $selltrade->amount_available  - ( $trade_qty * $selltrade->bid_price ) ");

            $buyer_available_qty_after_trade = $this->_safe_math(" $buytrade->bid_qty_available - $trade_qty ");
            $seller_available_qty_after_trade = $this->_safe_math(" $selltrade->bid_qty_available - $trade_qty ");

            $is_buyer_qty_fulfilled = $this->_safe_math_condition_check(" ( $buyer_available_qty_after_trade <= 0 )  ");
            $is_seller_qty_fulfilled = $this->_safe_math_condition_check(" ( $seller_available_qty_after_trade <= 0 )  ");

            $buyupdate = array(
                'bid_qty_available' => $buyer_available_qty_after_trade,
                'amount_available' => $buyer_available_bid_amount_after_trade, //Balance added buy account
                'status' => $is_buyer_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $seller_available_qty_after_trade, // ( $trade_qty - $selltrade->bid_qty_available  <= 0 ) ? 0 : $trade_qty - $selltrade->bid_qty_available  , //(($buytrade->bid_qty_available-$selltrade->bid_qty_available)<0)?0:$buytrade->bid_qty_available-$selltrade->bid_qty_available,
                'amount_available' => $seller_available_bid_amount_after_trade, //  ((( $trade_qty - $selltrade->bid_qty_available  )<= 0) ? 0: $trade_qty - $selltrade->bid_qty_available ) * $selltrade->bid_price, //Balance added seller account
                'status' => $is_seller_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');
            $success_datetimestamp = strtotime($success_datetime);

            $selltraderlog = array(
                'bid_id' => $selltrade->id,
                'bid_type' => $selltrade->bid_type,
                'complete_qty' => $trade_qty,
                'bid_price' => $selltrade->bid_price,
                'complete_amount' => $seller_receiving_amount,
                'user_id' => $selltrade->user_id,
                'coinpair_id' => $coin_id,
                'success_time' => $success_datetime,
                'fees_amount' => $fees_amount,
                'available_amount' => $seller_available_bid_amount_after_trade,
                'status' => $this->_safe_math_condition_check(" $seller_available_bid_amount_after_trade  <= 0 ") ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            // Update coin history
            $this->CI->WsServer_model->update_coin_history($coin_id, $trade_qty, $selltrade->bid_price);

            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
            
            $log_id = $this->CI->WsServer_model->insert_order_log( $selltraderlog);

            // UPDATE SL Order
            $this->CI->WsServer_model->update_stop_limit_status($coin_id);

            // Updating Current minute OHLCV
            $this->CI->WsServer_model->update_current_minute_OHLCV( $selltrade->coinpair_id, $selltrade->bid_price, $trade_qty, $success_datetimestamp );
            
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

    public function _limit($coin_id, $amount, $price, $auth): array
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

        $coin_id = intval($coin_id);
        $coin_details = $this->CI->WsServer_model->get_coin_pair($coin_id);

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

        if ($this->_validate_primary_value_decimals($amount, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell amount invalid.';
            return $data;
        }

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);

        $price = $this->_convert_to_decimals($price);
        $amount = $this->_convert_to_decimals($amount);

        $sellfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('SELL', $primary_coin_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));
        /**
         * DASH_USD
         * SELL 100 DASH @ 10 USD
         * Fees : 5%
         * Total fees : ( ( 100 DASH * 5 ) / 100 ) =  5 DASH
         * SELLER will get ( 1000 USD - ( 5 DASH * 10 USD ) )  = 950 USD
         */

        $fees_percent = 0;
        if ($sellfeesquery) {
            $fees_percent = $sellfeesquery->fees;
            $sellfeesval = $this->_safe_math(" ( $amount * $sellfeesquery->fees ) / 100 ");
            if ($fees_balance >= $this->fees_balance_above) {
                $sellfeesval = $this->_safe_math(" $sellfeesval * (100 - $this->fees_balance_discount ) / 100 ");
            }
        } else {
            $sellfeesval = 0;
            $fees_percent = 0;
        }

        $sellwithout_feesval = $this->_safe_math(" $price * $amount ");
        $pricewithfees = $this->_safe_math(" $sellwithout_feesval + $sellfeesval ");

        $balance_to = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);
        $balance_from = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);
        
        if ($this->_safe_math_condition_check(" $balance_from->balance >= $amount ")) {

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'SELL',
                'bid_price' => $price,
                'bid_qty' => $amount,
                'bid_qty_available' => $amount,
                'total_amount' => $sellwithout_feesval,
                'amount_available' => $sellwithout_feesval,
                'coinpair_id' => $coin_id,
                'user_id' => $this->user_id,
                'open_order' => $open_date,
                'fees_amount' => $sellfeesval,
                'status' => BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                $selltrade = $this->CI->WsServer_model->get_order($last_id);

                // SELLER BALANCE P_DN & S_UP
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($selltrade->user_id, $primary_coin_id, $amount);

                // Event for order creator                
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );


                $buyers = $this->CI->WsServer_model->get_buyers($price, $coin_id);
                // var_dump($buyers);
                if ($buyers) {

                    foreach ($buyers as $key => $buytrade) {

                        // Provide updated sell trade here
                        $selltrade = $this->CI->WsServer_model->get_order($last_id);

                        // SELLING TO BUYER
                        $this->_do_sell_trade($selltrade, $buytrade);

                        // Updating SL buy order status and make them available if price changed
                        // $this->CI->WsServer_model->update_stop_limit_status($coin_id);

                    } // End of buytradequery Loop

                }

                // Send event to every client
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $coin_id,
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

    public function _market($coin_id, $amount, $auth): array
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

        $coin_details = $this->CI->WsServer_model->get_coin_pair(intval($coin_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($amount, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell amount invalid.';
            return $data;
        }

        $amount = $this->_convert_to_decimals($amount);

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);


        $balance_prime = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);

        $available_balance = $balance_prime->balance;

        $sell_qty = $amount;

        if ($this->_safe_math_condition_check("$sell_qty > $available_balance")) {
            $data['isSuccess'] = false;
            $data['message'] = 'Insufficient balance.';
            return $data;
        }

        $remaining_qty = $sell_qty;
        $user_id = $this->user_id;

        $sellfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('SELL', $primary_coin_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));
        $fees_percent = 0;
        if ($sellfeesquery) {
            $fees_percent = $sellfeesquery->fees;
        }

        $count_buy_orders = $this->CI->WsServer_model->count_buy_orders_by_coin_id($coin_id);

        if ($count_buy_orders < 1) {
            // No sell orders available, use initial price
            $last_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);
        } else {
            $lowest_buyer_price = $this->CI->WsServer_model->get_lowest_price_in_buyer($coin_id);
            if ($lowest_buyer_price != null) {
                $last_price = $lowest_buyer_price;
            }

        }

        // Updating SL buy order status and make them available if price changed
        // $this->CI->WsServer_model->update_stop_limit_status($coin_id);

        $buyers = $this->CI->WsServer_model->get_buyers($last_price, $coin_id);

        foreach ($buyers as $key => $buytrade) {

            $last_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);

            $max_sell_qty = $this->_safe_math("LEAST( $buytrade->bid_qty_available, $remaining_qty ) ");

            if ($fees_percent != 0) {
                $sellfeesval = $this->_safe_math("  ( $max_sell_qty  * $fees_percent)/100 ");
                if ($fees_balance >= $this->fees_balance_above) {
                    $sellfeesval = $this->_safe_math(" $sellfeesval * (100 - $this->fees_balance_discount ) / 100 ");
                }
            } else {
                $sellfeesval = 0;
            }

            $sellwithout_feesval = $this->_safe_math("$buytrade->bid_price * $max_sell_qty");
            $pricewithfees = $this->_safe_math("$sellwithout_feesval + $sellfeesval");

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'SELL',
                'bid_price' => $buytrade->bid_price,
                'bid_qty' => $max_sell_qty,
                'bid_qty_available' => $max_sell_qty,
                'total_amount' => $sellwithout_feesval,
                'amount_available' => $sellwithout_feesval,
                'coinpair_id' => $coin_id,
                'user_id' => $user_id,
                'open_order' => $open_date,
                'fees_amount' => $sellfeesval,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                $selltrade = $this->CI->WsServer_model->get_order($last_id);

                // SELLER BALANCE P_DN & S_UP
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($selltrade->user_id, $primary_coin_id, $max_sell_qty);

                // SELLING TO BUYER
                $this->_do_sell_trade($selltrade, $buytrade);

                $remaining_qty = $this->_safe_math(" $remaining_qty - $max_sell_qty ");

                if ($this->_safe_math_condition_check(" $remaining_qty <= 0 ")) {
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
                    'coin_id' => $coin_id,
                ]
            );
        }

        if ($this->_safe_math_condition_check("$remaining_qty > 0 ")) {

            $last_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);

            // Create new open order
            $open_date = date('Y-m-d H:i:s');

            if ($fees_percent != 0) {
                $sellfeesval = $this->_safe_math("( $remaining_qty  * $sellfeesquery->fees)/100 ");
                if ($fees_balance >= $this->fees_balance_above) {
                    $sellfeesval = $this->_safe_math(" $sellfeesval * (100 - $this->fees_balance_discount ) / 100 ");
                }
            } else {
                $sellfeesval = 0;
            }

            $price = $this->_safe_math(" $last_price * $remaining_qty ");

            $sellwithout_feesval = $this->_safe_math(" $price * $remaining_qty ");
            $pricewithfees = $this->_safe_math(" $sellwithout_feesval + $sellfeesval ");

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'SELL',
                'bid_price' => $last_price,
                'bid_qty' => $remaining_qty,
                'bid_qty_available' => $remaining_qty,
                'total_amount' => $sellwithout_feesval,
                'amount_available' => $sellwithout_feesval,
                'coinpair_id' => $coin_id,
                'user_id' => $this->user_id,
                'open_order' => $open_date,
                'fees_amount' => $sellfeesval,
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
                        'coin_id' => $coin_id,
                    ]
                );

                $soldAmount = $this->_safe_math(" $sell_qty - $remaining_qty ");
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
                    'coin_id' => $coin_id,
                ]
            );

            $data['isSuccess'] = true;
            $data['message'] = 'All ' . $this->_format_number($amount, $coin_details->primary_decimals) .
                ' bought successfully';
            return $data;
        }
    }

    public function _stop_limit($coin_id, $amount, $stop, $limit, $auth): array
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

        $coin_id = intval($coin_id);
        $coin_details = $this->CI->WsServer_model->get_coin_pair($coin_id);
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

        if ($this->_validate_primary_value_decimals($amount, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Sell amount invalid.';
            return $data;
        }

        $amount = $this->_convert_to_decimals($amount);
        $stop = $this->_convert_to_decimals($stop);
        $limit = $this->_convert_to_decimals($limit);

        $is_take_profit = false;
        $is_stop_loss = false;

        $last_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);

        if ($this->_safe_math_condition_check(" $stop >= $last_price ")) {
            $is_take_profit = true;
            $is_stop_loss = false;
        } else if ($this->_safe_math_condition_check(" $stop <= $last_price ")) {
            $is_take_profit = false;
            $is_stop_loss = true;
        }

        $condition = $is_take_profit ? '>=' : '<=';

        // Create new open order
        $open_date = date('Y-m-d H:i:s');

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);

        $user_id = $this->user_id;

        $balance_prim = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));

        if ($this->_safe_math_condition_check("  $amount > $balance_prim->balance ")) {
            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance.";
            return $data;
        }

        $sellfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('SELL', $primary_coin_id);
        $fees_percent = 0;
        $sellfeesval = 0;

        if ($sellfeesquery) {
            $sellfeesval = $this->_safe_math(" ( $amount * $fees_percent )/100 ");
            if ($fees_balance >= $this->fees_balance_above) {
                $sellfreesval = $this->_safe_math(" $sellfreesval * (100 - $this->fees_balance_discount ) / 100");
            }
        } else {
            $sellfeesval = 0;
        }

        $sellwithout_feesval = $this->_safe_math(" $limit * $amount ");
        $pricewithfees = $this->_safe_math(" $sellwithout_feesval - $sellfeesval ");

        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => 'SELL',
            'bid_price' => $limit,
            'bid_price_stop' => $stop,
            'bid_price_limit' => $limit,
            'is_stop_limit' => 1,
            'stop_condition' => $condition,
            'bid_qty' => $amount,
            'bid_qty_available' => $amount,
            'total_amount' => $pricewithfees,
            'amount_available' => $pricewithfees,
            'coinpair_id' => $coin_id,
            'user_id' => $this->user_id,
            'open_order' => $open_date,
            'fees_amount' => $sellfeesval,
            'status' => PopulousWSSConstants::BID_QUEUED_STATUS,
        );

        $last_id = $this->CI->WsServer_model->insert_order($tadata);

        if ($last_id) {

            // BUYER : HOLD SECONDARY COIN
            $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $primary_coin_id, $amount);

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
