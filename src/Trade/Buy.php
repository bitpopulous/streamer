<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Trade\Trade;

class Buy extends Trade
{
    public function __construct(ServerHandler $server)
    {
        parent::__construct();
        $this->wss_server = $server;
    }
    
    private function _do_buy_trade($buytrade, $selltrade)
    {

        if ($buytrade->status == PopulousWSSConstants::BID_PENDING_STATUS
            && $selltrade->status == PopulousWSSConstants::BID_PENDING_STATUS) {

            $coin_id = intval($buytrade->coinpair_id);
            
            $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
            $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);

            $trade_qty = $this->_safe_math(" LEAST( $selltrade->bid_qty_available, $buytrade->bid_qty_available ) ");

            // BUYER AND SELLER BALANCE UPDATE HERE

            $buyer_receiving_amount = $trade_qty;
            $seller_will_pay = $trade_qty;

            $seller_receiving_amount = $this->_safe_math(" $trade_qty * $selltrade->bid_price ");
            $buyer_will_pay = $this->_safe_math(" $trade_qty * $buytrade->bid_price ");

            // We don't have maker fees
            // We only charging fees whoever clearing the order book

            $buyfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('BUY', $primary_coin_id);

            $fees_percent = 0;
            if ($buyfeesquery) {
                $fees_percent = $buyfeesquery->fees;
            }

            $buyer_receiving_amount_after_fees = $buyer_receiving_amount;

            $this->_read_fees_balance_env();
            $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));

            $fees_amount = 0;
            // Fees will be deducted here according to whatever qty being bought
            if ($fees_percent != 0) {
                $fees_amount = $this->_safe_math(" ( ( $buyer_receiving_amount * $fees_percent ) / 100 ) ");
                if ($fees_balance >= $this->fees_balance_above) {
                    $fees_amount = $this->_safe_math(" $fees_amount * (100 - $this->fees_balance_discount) / 100 ");
                }
                $buyer_receiving_amount_after_fees = $this->_safe_math(" $buyer_receiving_amount - $fees_amount ");
            }

            // BUYER WILL GET PRIMARY COIN
            // THE SECONDARY AMOUNT BUYER HAS HOLD WE WILL BE DEDUCTED
            // AND PRIMARY COIN WILL BE CREDITED
            $this->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $buyer_receiving_amount_after_fees, $buyer_will_pay);

            // SELLER WILL GET SECONDARY COIN
            // THE PRIMARY AMOUNT SELLER HAS HOLD WILL BE DEDUCTED
            // AND SECONDARY COIN WILL BE CREDITED
            $this->_seller_trade_balance_update($selltrade->user_id, $primary_coin_id, $secondary_coin_id, $seller_will_pay, $seller_receiving_amount);

            // Credit fees to exchange account
            $this->CI->WsServer_model->credit_admin_fees_by_coin_id($primary_coin_id, $fees_amount);

            $buyerAvailableBidAmountAfterTrade = $this->_safe_math(" $buytrade->amount_available  - ($trade_qty * $buytrade->bid_price ) ");
            $sellerAvailableBidAmountAfterTrade = $this->_safe_math(" $selltrade->amount_available  - ( $trade_qty * $selltrade->bid_price ) ");

            $buyerAvailableQtyAfterTrade = $this->_safe_math(" $buytrade->bid_qty_available - $trade_qty ");
            $sellerAvailableQtyAfterTrade = $this->_safe_math(" $selltrade->bid_qty_available - $trade_qty ");

            $isBuyerQtyFulfilled = $this->_safe_math_condition_check(" ( $buyerAvailableQtyAfterTrade <= 0 )  ");
            $isSellerQtyFulfilled = $this->_safe_math_condition_check(" ( $sellerAvailableQtyAfterTrade <= 0 )  ");

            $buyupdate = array(
                'bid_qty_available' => $buyerAvailableQtyAfterTrade,
                'amount_available' => $buyerAvailableBidAmountAfterTrade,
                'status' => $isBuyerQtyFulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $sellupdate = array(
                'bid_qty_available' => $sellerAvailableQtyAfterTrade,
                'amount_available' => $sellerAvailableBidAmountAfterTrade,
                'status' => $isSellerQtyFulfilled ? PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );

            // DASH_USD
            $success_datetime = date('Y-m-d H:i:s');

            $buytraderlog = array(
                'bid_id' => $buytrade->id,
                'bid_type' => $buytrade->bid_type,
                'complete_qty' => $trade_qty,
                'bid_price' => $buytrade->bid_price,
                'complete_amount' => $buyer_will_pay,
                'user_id' => $buytrade->user_id,
                'coinpair_id' => $buytrade->coinpair_id,
                'success_time' => $success_datetime,
                'fees_amount' => $fees_amount,
                'available_amount' => $buyerAvailableBidAmountAfterTrade,
                'status' => $this->_safe_math_condition_check("( $buyerAvailableBidAmountAfterTrade <= 0 ) ") ?
                PopulousWSSConstants::BID_COMPLETE_STATUS : PopulousWSSConstants::BID_PENDING_STATUS,
            );
            // Update coin history
            $this->CI->WsServer_model->update_coin_history($coin_id, $trade_qty, $buytrade->bid_price);

            $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);
            $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);
            
            $log_id = $this->CI->WsServer_model->insert_order_log( $buytraderlog);

            // Updating SL sell orders status and make them available if price changed
            $this->CI->WsServer_model->update_stop_limit_status($coin_id);

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

        }
    }

    /**
     * @return bool
     */
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

        $coin_details = $this->CI->WsServer_model->get_coin(intval($coin_id));
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

        if ($this->_validate_primary_value_decimals($amount, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy amount is invalid.';
            return $data;
        }

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);

        $price = $this->_convert_to_decimals($price);
        $amount = $this->_convert_to_decimals($amount);

        $buyfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('BUY', $primary_coin_id);

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));

        /**
         * DASH_USD
         * BUY 100 USD
         * Fees : 5%
         * Total fees : 5 DASH
         * BUYER will get 95 DASH
         */

        if ($buyfeesquery) {

            $buyfeesval = $this->_safe_math(" ( ( $amount * $buyfeesquery->fees)/100 ) ");

            if ($fees_balance >= $this->fees_balance_above) {
                $buyfeesval = $this->_safe_math(" $buyfeesval * (100 - $this->fees_balance_discount) / 100 ");
            }

        } else {
            $buyfeesval = 0;
        }

        $buywithout_feesval = $this->_safe_math(" $price * $amount ");
        $buy_price_with_fees = $this->_safe_math(" $buywithout_feesval + $buyfeesval ");

        $balance_to = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);
        $balance_from = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);

        if ($this->_safe_math_condition_check(" $balance_to->balance >= $buy_price_with_fees ") &&
            $this->_safe_math_condition_check(" $balance_to->balance > 0 ")) {

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'BUY',
                'bid_price' => $price,
                'bid_qty' => $amount,
                'bid_qty_available' => $amount,
                'total_amount' => $buywithout_feesval,
                'amount_available' => $buywithout_feesval,
                'coinpair_id' => $coin_id,
                'user_id' => $this->user_id,
                'is_stop_limit' => false,
                'open_order' => $open_date,
                'fees_amount' => $buyfeesval,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                $buytrade = $this->CI->WsServer_model->get_order($last_id);

                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($buytrade->user_id, $secondary_coin_id, $buy_price_with_fees);

                $sellers = $this->CI->WsServer_model->get_sellers($price, $coin_id);

                if ($sellers) {

                    // BUYER  : P_UP S_DN
                    // SELLER : P_DN S_UP
                    foreach ($sellers as $key => $selltrade) {

                        $buytrade = $this->CI->WsServer_model->get_order($last_id);
                        $this->_do_buy_trade($buytrade, $selltrade);
                    }

                } // Salesquery ends here

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

        $coin_details = $this->CI->WsServer_model->get_coin(intval($coin_id));
        if ($coin_details == null) {
            $data['isSuccess'] = false;
            $data['message'] = 'Invalid pair';
            return $data;
        }

        if ($this->_validate_primary_value_decimals($amount, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy amount is invalid.';
            return $data;
        }

        $amount = $this->_convert_to_decimals($amount);

        // PPT_USDT
        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);
        $primary_coin_symbol = $this->CI->WsServer_model->get_coin_id_of_symbol($primary_coin_id);

        // Check balance
        $balance_prim = $this->CI->WsServer_model->get_user_balance_by_coin_id($primary_coin_id, $this->user_id);
        $balance_sec = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $this->user_id);

        if ($this->_safe_math_condition_check(" $balance_sec->balance  <= 0 ")) {
            $data['isSuccess'] = false;
            $data['message'] = 'Insufficient balance.';
            return $data;
        }

        $user_id = $this->user_id;

        $remaining_qty = $amount;

        $count_sell_orders = $this->CI->WsServer_model->count_sell_orders_by_coin_id($coin_id);

        if ($count_sell_orders < 1) {
            // No sell orders available, use initial price
            $last_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);
        } else {
            $highest_seller_price = $this->CI->WsServer_model->get_highest_price_in_seller($coin_id);
            if ($highest_seller_price != null) {
                $last_price = $highest_seller_price;
            }

        }

        $total_sec_required = $this->_safe_math(" $last_price * $amount ");

        if ($this->_safe_math_condition_check(" $balance_sec->balance  < $total_sec_required ")) {

            $maximumBuy = $this->_safe_math(" $balance_sec->balance / $last_price ");
            $data['isSuccess'] = false;
            $data['message'] = "Maximum $maximumBuy $primary_coin_symbol you can buy @ price $last_price.";
            return $data;
        }

        $sellers = $this->CI->WsServer_model->get_sellers($last_price, $coin_id);

        $buyfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('BUY', $primary_coin_id);
        $fees_percent = 0;
        if ($buyfeesquery) {
            $fees_percent = $buyfeesquery->fees;
        }

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));

        foreach ($sellers as $selltrade) {

            $max_buy_qty = $this->_safe_math(" LEAST( $selltrade->bid_qty_available, $remaining_qty ) ");

            if ($fees_percent != 0) {
                $buyfeesval = $this->_safe_math("( $max_buy_qty  * $fees_percent)/100");
                if ($fees_balance >= $this->fees_balance_above) {
                    $buyfeesval = $this->_safe_math(" $buyfeesval * (100 - $this->fees_balance_discount ) / 100");
                }
            } else {
                $buyfeesval = 0;
            }

            $buywithout_feesval = $this->_safe_math(" $selltrade->bid_price * $max_buy_qty ");
            $buypricingwithfees = $this->_safe_math(" $buywithout_feesval + $buyfeesval ");

            $open_date = date('Y-m-d H:i:s');

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'BUY',
                'bid_price' => $selltrade->bid_price,
                'bid_qty' => $max_buy_qty,
                'bid_qty_available' => $max_buy_qty,
                'total_amount' => $buywithout_feesval,
                'amount_available' => $buywithout_feesval,
                'coinpair_id' => $coin_id,
                'user_id' => $this->user_id,
                'is_stop_limit' => false,
                'open_order' => $open_date,
                'fees_amount' => $buyfeesval,
                'status' => PopulousWSSConstants::BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);
            $this->wss_server->_event_push(
                PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                [
                    'coin_id' => $coin_id,
                ]
            );

            if ($last_id) {

                $buytrade = $this->CI->WsServer_model->get_order($last_id);

                // BUYER : HOLD SECONDARY COIN
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $buypricingwithfees);

                $this->_do_buy_trade($buytrade, $selltrade);

                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $last_id,
                        'user_id' => $this->user_id,
                    ]
                );

                $remaining_qty = $this->_safe_math(" $remaining_qty - $max_buy_qty ");

                if ($this->_safe_math_condition_check(" $remaining_qty <= 0 ")) {
                    // ALL QTY BOUGHT
                    break; // Come out of for loop everything is bought
                }
            }

            // Updating SL sell orders status and make them available if price changed
            // $this->CI->WsServer_model->update_stop_limit_status ( $coin_id );

        }

        if ($this->_safe_math_condition_check(" $remaining_qty > 0 ")) {

            $open_date = date('Y-m-d H:i:s');
            $last_trade_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);

            if ($buyfeesquery) {
                $buyfeesval = $this->_safe_math(" ( $remaining_qty * $buyfeesquery->fees)/100 ");
                if ($fees_balance >= $this->fees_balance_above) {
                    $buyfeeval = $this->safemath(" $buyfeeval * (100 - $this->fees_balance_discount ) / 100 ");
                }
            } else {
                $buyfeesval = 0;
            }

            $buywithout_feesval = $this->_safe_math(" $last_trade_price * $remaining_qty ");
            $buywith_feesval = $this->_safe_math(" $buywithout_feesval + $buyfeesval ");

            $tdata['TRADES'] = (object) $tadata = array(
                'bid_type' => 'BUY',
                'bid_price' => $last_trade_price,
                'bid_qty' => $remaining_qty,
                'bid_qty_available' => $remaining_qty,
                'total_amount' => $buywithout_feesval,
                'amount_available' => $buywithout_feesval,
                'coinpair_id' => $coin_id,
                'user_id' => $this->user_id,
                'open_order' => $open_date,
                'fees_amount' => $buyfeesval,
                'status' => BID_PENDING_STATUS,
            );

            $last_id = $this->CI->WsServer_model->insert_order($tadata);

            if ($last_id) {

                // HOLD PRIMARY
                $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $buywithout_feesval);

                $boughtAmount = $this->_safe_math(" $amount - $remaining_qty ");

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

        $coin_details = $this->CI->WsServer_model->get_coin(intval($coin_id));
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

        if ($this->_validate_primary_value_decimals($amount, $coin_details->primary_decimals) == false) {
            $data['isSuccess'] = false;
            $data['message'] = 'Buy amount invalid.';
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

        // Check balance
        $balance_sec = $this->CI->WsServer_model->get_user_balance_by_coin_id($secondary_coin_id, $user_id);

        $requiredAmount = $this->_safe_math(" $amount * $limit ");
        $availableAmount = $balance_sec->balance;

        if ($this->_safe_math_condition_check(" $availableAmount < $requiredAmount ")) {
            // Low balance
            $amountNeeded = $this->_safe_math(" $requiredAmount - $availableAmount ");
            $data['isSuccess'] = false;
            $data['message'] = "You have insufficient balance, More $amountNeeded needed to create an order.";
            return $data;
        }

        // Enought amount to place order without checking fees

        $buyfeesquery = $this->CI->WsServer_model->get_fees_by_coin_id('BUY', $primary_coin_id);
        $feesPercent = 0;
        $buyfeesval = 0;

        $this->_read_fees_balance_env();
        $fees_balance = floatval($this->CI->WsServer_model->get_user_available_balance($this->user_id, $this->fees_balance_of));

        if ($buyfeesquery) {
            $feesPercent = $buyfeesquery->fees;
            $buyfeesval = $this->_safe_math(" ( ( $amount * $feesPercent )/100 ) ");
            if ($fees_balance >= $this->fees_balance_above) {
                $buyfeesval = $this->_safe_math(" $buyfeesval * (100 - $this->fees_balance_discount ) / 100 ");
            }

        }

        $buywithout_feesval = $this->_safe_math(" $limit * $amount ");
        $buypricingwithfees = $this->_safe_math(" $buywithout_feesval - $buyfeesval ");

        $last_price = $this->CI->WsServer_model->get_last_trade_price($coin_id);

        // Create one function for holding funds

        $tdata['TRADES'] = (object) $tadata = array(
            'bid_type' => 'BUY',
            'bid_price' => $limit,
            'bid_price_limit' => $limit,
            'bid_price_stop' => $stop,
            'bid_qty' => $amount,
            'bid_qty_available' => $amount,
            'total_amount' => $requiredAmount,
            'amount_available' => $requiredAmount,
            'coinpair_id' => $coin_id,
            'user_id' => $user_id,
            'open_order' => $open_date,
            'fees_amount' => $buyfeesval,
            'is_stop_limit' => 1,
            'stop_condition' => $condition,
            'status' => PopulousWSSConstants::BID_QUEUED_STATUS,
        );

        $last_id = $this->CI->WsServer_model->insert_order($tadata);

        /**
         *
         * The stop price is simply the price that triggers a limit order, and the limit price is the specific price of the limit order that was triggered.
         * This means that once your stop price has been reached, your limit order will be immediately placed on the order book.
         *
         */

        if ($last_id) {

            // BUYER : HOLD SECONDARY COIN
            $this->CI->WsServer_model->get_credit_hold_balance_from_balance($this->user_id, $secondary_coin_id, $requiredAmount);

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

    public function cancel_order($order_id, $auth, $rData) {
        
        $user_id = $this->_get_user_id($auth);
        $ip_address = $rData['ip_address'];

        $data = [
            'isSuccess' => true,
            'message' => '',
        ];

        $orderdata = $this->CI->WsServer_model->get_order($order_id);

        if ($user_id != $orderdata->user_id) {
            $data['isSuccess'] = false;
            $data['message'] = 'You are not allow to cancel this order.';
           
        } else {

            $canceltrade = array(
                'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
            );

            $is_updated = $this->CI->WsServer_model->update_order($order_id, $canceltrade);

            if ($is_updated == false) {
                $data['isSuccess'] = false;
                $data['message'] = 'Could not cancelled the order';
            } else {
                $currency_symbol = '';
                $currency_id = '';
                $coin_id = $orderdata->coinpair_id;
    
                $refund_amount = '';
                if ($orderdata->bid_type == 'SELL') {
                    $currency_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
                    $refund_amount = $orderdata->bid_qty_available;
                } else {
                    $currency_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);
                    $refund_amount = $this->_safe_math(" ($orderdata->bid_qty_available * $orderdata->bid_price) ");
                }
    
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
                $this->CI->WsServer_model->get_credit_balance($orderdata->user_id, $currency_id, $refund_amount);
                // Release hold balance
                $this->CI->WsServer_model->get_debit_hold_balance($orderdata->user_id, $currency_id, $refund_amount);
                
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
        return $data;
    }
}
