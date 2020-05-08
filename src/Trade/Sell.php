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

    private function _get_buyers($sell_price, $coin_id)
    {

        // UPDATE SL Order
        $this->CI->web_model->updateStopLimitStatus($coin_id);

        $where = "(bid_price >= '" . $sell_price . "' AND status = " . PopulousWSSConstants::BID_PENDING_STATUS . " AND bid_type = 'BUY' AND coinpair_id = $coin_id )";
        return $this->CI->db->select('*')
            ->from('dbt_biding')
            ->where($where)
            ->order_by('bid_price', 'desc')
            ->order_by('open_order', 'asc')
            ->get()
            ->result();

    }

    private function _do_sell_trade($selltrade, $buytrade)
    {

        if ($buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS &&
            $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS) {

            $coin_id = intval($selltrade->coinpair_id);
            $coin_pair_details = $this->CI->coinpair_model->getById($coin_id);

            // PPT_USDT
            $market_id = $coin_pair_details->market_id;
            $primary_coin_id = $coin_pair_details->coin_id; // PPT

            $market_details = $this->CI->common_model->getMarketDetailById($market_id);
            $secondary_coin_id = $market_details->cryptocoin_id; // USDT

            $trade_qty = $this->_safe_math("LEAST( $selltrade->bid_qty_available, $buytrade->bid_qty_available )");

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay = $trade_qty;

            $seller_receiving_amount = $this->_safe_math(" $trade_qty * $selltrade->bid_price ");
            $buyer_will_pay = $this->_safe_math(" $trade_qty * $buytrade->bid_price ");

            // Fees will be deducted here according to whatever qty being sold

            $sellfeesquery = $this->CI->web_model->checkFeesByCoinId('SELL', $primary_coin_id);

            $fees_percent = 0;
            if ($sellfeesquery) {
                $fees_percent = $sellfeesquery->fees;
            }

            $fees_amount = 0;
            $seller_receiving_amountAfterFees = $seller_receiving_amount;
            if ($fees_percent != 0) {
                $fees_amount = $this->_safe_math(" ( ( $seller_receiving_amount  * $fees_percent ) / 100 ");
                if ($seller_receiving_amount > 10000) {
                    $fees_amount = $this->_safe_math(" $fees_amount * (100 - $fees_balance_discount ) / 100");
                }
                $seller_receiving_amountAfterFees = $this->_safe_math(" $seller_receiving_amount - $fees_amount ");
            }

            // BUYER WILL GET PRIMARY COIN
            // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
            // AND PRIMARY COIN WILL BE CREDITED
            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount, $buyer_will_pay);

            // SELLER WILL GET SECONDARY COIN
            // THE PRIMARY AMOUNT SELLER HAS HOLD WILL BE DEDUCTED
            // AND SECONDARY COIN WILL BE CREDITED
            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amountAfterFees);

            // Credit fees to exchange account
            // User 2 : WIRWY5
            $this->CI->web_model->creditAdminFeesById($secondary_coin_id, $fees_amount);

            $buyer_available_bid_amount_after_trade = $this->_safe_math(" $buytrade->amount_available  - ($trade_qty * $buytrade->bid_price ) ");
            $seller_available_bid_amount_after_trade = $this->_safe_math(" $selltrade->amount_available  - ( $trade_qty * $selltrade->bid_price ) ");

            $buyer_available_qty_after_trade = $this->_safe_math(" $buytrade->bid_qty_available - $trade_qty ");
            $seller_available_qty_after_trade = $this->_safe_math(" $selltrade->bid_qty_available - $trade_qty ");

            $is_buyer_qty_fulfilled = $this->_safe_math_condition_check(" ( $buyer_available_qty_after_trade <= 0 )  ");
            $isSellerQtyFulfilled = $this->_safe_math_condition_check(" ( $seller_available_qty_after_trade <= 0 )  ");

            $buyupdate = array(
                'bid_qty_available' => $buyer_available_qty_after_trade,
                'amount_available' => $buyer_available_bid_amount_after_trade, //Balance added buy account
                'status' => $is_buyer_qty_fulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $seller_available_qty_after_trade, // ( $trade_qty - $selltrade->bid_qty_available  <= 0 ) ? 0 : $trade_qty - $selltrade->bid_qty_available  , //(($buytrade->bid_qty_available-$selltrade->bid_qty_available)<0)?0:$buytrade->bid_qty_available-$selltrade->bid_qty_available,
                'amount_available' => $seller_available_bid_amount_after_trade, //  ((( $trade_qty - $selltrade->bid_qty_available  )<= 0) ? 0: $trade_qty - $selltrade->bid_qty_available ) * $selltrade->bid_price, //Balance added seller account
                'status' => $isSellerQtyFulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');

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
            $this->_update_coin_history($coin_id, $trade_qty, $selltrade->bid_price);

            $this->CI->db->where('id', $selltrade->id)->update("dbt_biding", $sellupdate);
            $this->CI->db->where('id', $buytrade->id)->update("dbt_biding", $buyupdate);

            $res = $this->CI->db->insert('dbt_biding_log', $selltraderlog);
            $log_id = $this->CI->db->insert_id();

            if (!$res) {
                $r = $this->CI->db->error();
            }

            // UPDATE SL Order
            $this->CI->web_model->updateStopLimitStatus($coin_id);

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

            } catch (Exception $e) {

            }

            return true;

            //Affilition Bonus

        }

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
        $coin_pair_details = $this->CI->coinpair_model->getById($coin_id);

        if ($coin_pair_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        /**
         *
         * AMOUNT : PRIMARY
         * PRICE : SECONDARY
         */

        $check_input_values = $this->CI->coinpair_model->checkValues($amount, $price, $coin_id);

        if ($check_input_values == 1) {
            $data['isSuccess'] = false;
            $data['message'] = 'Amount is invalid. Please make sure the value is correct';
            return $data;
        } else if ($check_input_values == 2) {
            $data['isSuccess'] = false;
            $data['message'] = 'Price is invalid. Please make sure the value is correct';
            return $data;
        } else if ($check_input_values == 3) {
            $data['isSuccess'] = false;
            $data['message'] = 'Amount & Price are invalid. Please make sure the value is correct';
            return $data;
        }

        // PPT_USDT
        $market_id = $coin_pair_details->market_id;
        $primary_coin_id = $coin_pair_details->coin_id; // PPT

        $market_details = $this->CI->common_model->getMarketDetailById($market_id);
        $secondary_coin_id = $market_details->cryptocoin_id; // USDT

        $price = $this->_convert_to_decimals($price);
        $amount = $this->_convert_to_decimals($amount);

        $sellfeesquery = $this->CI->web_model->checkFeesByCoinId('SELL', $primary_coin_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->balance_model->getUserAvailableBalance($this->user_id, $this->fees_balance_of));
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

        $balance_to = $this->CI->web_model->checkBalanceById($secondary_coin_id, $this->user_id);
        $balance_from = $this->CI->web_model->checkBalanceById($primary_coin_id, $this->user_id);

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

            $last_id = $this->CI->web_model->tradeCreate($tadata);

            if ($last_id) {

                $selltrade = $this->CI->web_model->single($last_id);

                // SELLER BALANCE P_DN & S_UP
                $this->_credit_hold_balance_from_balance($selltrade->user_id, $primary_coin_id, $amount);

                // Event for order creator                
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );


                $buyers = $this->_get_buyers($price, $coin_id);
                // var_dump($buyers);
                if ($buyers) {

                    foreach ($buyers as $key => $buytrade) {

                        // Provide updated sell trade here
                        $selltrade = $this->CI->web_model->single($last_id);

                        // SELLING TO BUYER
                        $this->_do_sell_trade($selltrade, $buytrade);

                        // Updating SL buy order status and make them available if price changed
                        // $this->CI->web_model->updateStopLimitStatus($coin_id);

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

        $coin_pair_details = $this->CI->coinpair_model->getById(intval($coin_id));
        if ($coin_pair_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        $check_input_values = $this->CI->coinpair_model->checkValues($qty, 1, $coin_id);

        if ($check_input_values == 1) {
            $data['isSuccess'] = false;
            $data['message'] = 'Amount is invalid. Please make sure the value is correct';
            return $data;
        } else if ($check_input_values == 2) {
            $data['isSuccess'] = false;
            $data['message'] = 'Price is invalid. Please make sure the value is correct';
            return $data;
        } else if ($check_input_values == 3) {
            $data['isSuccess'] = false;
            $data['message'] = 'Amount & Price are invalid. Please make sure the value is correct';
            return $data;
        }

        $amount = $this->_convert_to_decimals($amount);

        // PPT_USDT
        $market_id = $coin_pair_details->market_id;
        $primary_coin_id = $coin_pair_details->coin_id; // PPT

        $market_details = $this->CI->common_model->getMarketDetailById($market_id);
        $secondary_coin_id = $market_details->cryptocoin_id; // USDT

        $balance_prime = $this->CI->web_model->checkBalanceById($primary_coin_id, $this->user_id);

        $available_balance = $balance_prime->balance;

        $sell_qty = $amount;

        if ($this->_safe_math_condition_check("$sell_qty > $available_balance")) {
            $this->setFailureReasonCode('INSUFFICIENT_BALANCE');
            $this->setStatusAndMessage('NOT_OK', 'Insufficient balance.');
            return false;
        }

        $remaining_qty = $sell_qty;
        $user_id = $this->user_id;

        $sellfeesquery = $this->CI->web_model->checkFeesByCoinId('SELL', $primary_coin_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->balance_model->getUserAvailableBalance($this->user_id, $this->fees_balance_of));
        $fees_percent = 0;
        if ($sellfeesquery) {
            $fees_percent = $sellfeesquery->fees;
        }

        $count_buy_orders = $this->CI->web_model->countBuyOrders($coin_id);

        if ($count_buy_orders < 1) {
            // No sell orders available, use initial price
            $last_price = $this->CI->web_model->lastTradePriceById($coin_id);
        } else {
            $lowest_buyer_price = $this->CI->web_model->getLowestBuyerPrice($coin_id);
            if ($lowest_buyer_price != null) {
                $last_price = $lowest_buyer_price;
            }

        }

        // Updating SL buy order status and make them available if price changed
        // $this->CI->web_model->updateStopLimitStatus($coin_id);

        $buyers = $this->_get_buyers($last_price, $coin_id);

        foreach ($buyers as $key => $buytrade) {

            $last_price = $this->CI->web_model->lastTradePriceById($coin_id);

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

            $last_id = $this->CI->web_model->tradeCreate($tadata);

            if ($last_id) {

                $selltrade = $this->CI->web_model->single($last_id);

                // SELLER BALANCE P_DN & S_UP
                $this->_credit_hold_balance_from_balance($selltrade->user_id, $primary_coin_id, $max_sell_qty);

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

            $last_price = $this->CI->web_model->lastTradePriceById($coin_id);

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

            $last_id = $this->CI->web_model->tradeCreate($tadata);

            if ($last_id) {
                // HOLD PRIMARY
                $this->_credit_hold_balance_from_balance($this->user_id, $primary_coin_id, $remaining_qty);
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
                $this->_format_number($remaining_qty, $coin_pair_details->primary_decimals);
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
            $data['message'] = 'All ' . $this->_format_number($amount, $coin_pair_details->primary_decimals) .
                ' bought successfully';
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
        $coin_pair_details = $this->CI->coinpair_model->getById($coin_id);
        if ($coin_pair_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($stop, $coin_pair_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy Stop price invalid.';
            return $data;
        }

        if ($this->_validate_secondary_value_decimals($limit, $coin_pair_details->secondary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy Limit price invalid.';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($amount, $coin_pair_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy amount invalid.';
            return $data;
        }

        $amount = $this->_convert_to_decimals($amount);
        $stop = $this->_convert_to_decimals($stop);
        $limit = $this->_convert_to_decimals($limit);

        $is_take_profit = false;
        $is_stop_loss = false;

        $last_price = $this->CI->web_model->lastTradePriceById($coin_id);

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

        $coin_id = intval($coin_id);
        $coin_pair_details = $this->CI->coinpair_model->getById($coin_id);

        // PPT_USDT
        $market_id = $coin_pair_details->market_id;
        $primary_coin_id = $coin_pair_details->coin_id; // PPT

        $market_details = $this->CI->common_model->getMarketDetailById($market_id);
        $secondary_coin_id = $market_details->cryptocoin_id; // USDT

        $user_id = $this->user_id;

        $balance_prim = $this->CI->web_model->checkBalanceById($primary_coin_id, $this->user_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->balance_model->getUserAvailableBalance($this->user_id, $this->fees_balance_of));

        if ($this->_safe_math_condition_check("  $amount > $balance_prim->balance ")) {
            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance.";
            return $data;
        }

        $sellfeesquery = $this->CI->web_model->checkFeesByCoinId('SELL', $primary_coin_id);
        $fees_percent = 0;
        $sellfeesval = 0;

        if ($sellfeesquery) {
            $sellfeesval = $this->_safe_math(" ( ( $amount * $fees_percent )/100 ) ");
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

        $last_id = $this->CI->web_model->tradeCreate($tadata);

        if ($last_id) {

            // BUYER : HOLD SECONDARY COIN
            $this->_credit_hold_balance_from_balance($this->user_id, $primary_coin_id, $amount);

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
