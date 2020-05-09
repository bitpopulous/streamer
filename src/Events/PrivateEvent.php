<?php

namespace PopulousWSS\Events;

use PopulousWSS\Channels\PrivateChannel;
use PopulousWSS\ServerHandler;

class PrivateEvent extends PrivateChannel
{
    protected $wss_server;

    public function __construct(ServerHandler $server)
    {
        parent::__construct();

        $this->wss_server = $server;
    }

    /**
     * @return bool
     */
    public function _publish_message(string $event, string $channel, string $message): bool
    {
        if (isset($this->channels[$channel])) {

            $subscribers = $this->channels[$channel];
            $clients = $this->wss_server->_get_clients();
            foreach ($subscribers as $s) {
                if (isset($clients[$s])) {
                    $clients[$s]->send($message);
                }
            }
            return true;
        } else {
            // $this->log->debug("No subscribers found for channel : $channel ");
            return false;
        }
    }

    public function _event_order_update(int $order_id, string $user_id)
    {
        $user_channels = $this->CI->privatechannels_model->getUserChannelsArr($user_id);
        $user_channels = array_unique($user_channels);

        $coin_id = $this->CI->biding_model->getCoinpairIdFromBidId($order_id);

        $channels = [];
        foreach ($user_channels as $channel) {
            $channels[$channel] = [];
            $channels[$channel][] = $this->_prepare_order_update($coin_id, $order_id);
            $channels[$channel][] = $this->_prepare_user_balances_update($coin_id, $user_id);
        }

        $this->_push_event_to_channels($channels);
    }

    public function _push_event_to_channels(array $channels)
    {
        foreach ($channels as $channel => $eventData) {
            foreach ($eventData as $e) {
                $this->_send_to_subscribers($e['event'], $channel, (array) $e['data']);
            }
        }
    }

    public function _send_to_subscribers(string $event, string $channel, array $message): bool
    {
        //prepare data
        $d = [
            'event' => $event,
            'channel' => $channel,
            'data' => $message,
        ];
        $messageTxt = json_encode($d);

        return $this->_publish_message($event, $channel, $messageTxt);
    }

    private function _prepare_order_update($coin_id, $order_id): array
    {
        $updated_order = $this->CI->biding_model->getBidDetail($order_id);
        $converted_fields = ['bid_price:1', 'bid_price_limit:1', 'bid_price_stop:1', 'bid_qty:0', 'fees_amount:0', 'bid_qty_available:0'];

        return [
            'event' => 'order-update',
            'data' => $this->CI->convertdata->convertDataObject($updated_order, $converted_fields, $coin_id),
        ];
    }

    private function _prepare_user_balances_update($coin_id, $user_id): array
    {

        $primary_coin_id = $this->CI->coinpair_model->getPrimaryCoinId($coin_id);
        $secondary_coin_id = $this->CI->coinpair_model->getSecondaryCoinId($coin_id);

        return [
            'event' => 'balance-update',
            'data' => [
                $this->CI->balance_model->checkUserBalanceByCoinIdArr($user_id, $primary_coin_id),
                $this->CI->balance_model->checkUserBalanceByCoinIdArr($user_id, $secondary_coin_id),
            ],
        ];
    }

    public function _prepare_exchange_init_data($coin_id, $auth): array
    {
        $user_id = $this->_get_user_id($auth);
        
        /**********************
         * Get Trade History  *
         **********************/
        $_trades = $this->CI->bidinglog_model->tradeHistoryByPairId($coin_id, 60);
        $trades = $this->CI->convertdata->convertDataArray($_trades, ['bid_price:1', 'complete_qty:0'], $coin_id);
        
        /**********************
         * Get Coin History   *
         **********************/
        $coins = $this->CI->db->select('*')
            ->from('dbt_coinhistory')
            ->where('coinpair_id', $coin_id)
            ->order_by('date', 'desc')
            ->limit(20)
            ->get()
            ->row();
        if ($coins == null) {
            $coins = [];
        }
        
        /**********************
         * Get Order History  *
         **********************/
        $data = $this->CI->biding_model->getBuySellOrders($coin_id, 40);
        $b_orders = $this->CI->convertdata->convertDataArray($data['buy_orders'], ['bid_price:1', 'total_qty:0'], $coin_id);
        $s_orders = $this->CI->convertdata->convertDataArray($data['sell_orders'], ['bid_price:1', 'total_qty:0'], $coin_id);

        /***********************
         * Get Pending Orders  *
         ***********************/
        $orders = $this->CI->web_model->openTradeForCoinpairWithLimitInSocket($coin_id, 6, $user_id);
        $p_orders = $this->CI->convertdata->convertDataArray(
            $orders,
            ['bid_price:1', 'bid_price_stop:1', 'bid_price_limit:1', 'bid_qty:0', 'fees_amount:0'],
            $coin_id
        );
        
        /*************************
         * Get Completed Orders  *
         *************************/
        $orders = $this->CI->web_model->completeTradeForCoinpairWithLimitInSocket($coin_id, 6, $user_id);
        $c_orders = $this->CI->convertdata->convertDataArray(
            $orders,
            ['bid_price:1', 'bid_price_stop:1', 'bid_price_limit:1', 'bid_qty:0', 'fees_amount:0'],
            $coin_id
        );
        
        return [
            'coinpairs_24h_summary' => $this->_coinpairs_24h_summary(),
            'market_pairs' => $this->_prepare_market_pairs(),
            'trade_history' => $trades,
            'coin_history' => $coins,
            'buy_orders' => $b_orders,
            'sell_orders' => $s_orders,
            'pending_orders' => $p_orders,
            'completed_orders' => $c_orders,
            'user_balance' => $this->CI->web_model->checkUserAllBalanceWithCoinDetailsInSocket($user_id),
        ];
    }

    private function _coinpairs_24h_summary() {

        $data = [];

        $coin_ids = $this->CI->coinpair_model->getActiveListOfIds(); // [ 2, 14  ];

        foreach ($coin_ids as $id) {
            $data[$id] = [];

            $currentUTC = $this->CI->coinhistory_model->getUtcCurrentDateTime();
            $previousUTCStart = $this->CI->coinhistory_model->deductSecondsFromDate($currentUTC, (24 * 3600));

            $data[$id]['p'] = $this->CI->coinhistory_model->hourHistory($id, 24, $previousUTCStart);

            if ($data[$id]['p']['volume'] == 0) {
                // If volume is 0 for previous 24 , there were no trade has been made
                // Use previous last trade bid price
                $data[$id]['p'] = $this->CI->coinhistory_model->getLastTradeSince($id, $previousUTCStart);
            }

            $data[$id]['c'] = $this->CI->coinhistory_model->hourHistory($id, 24);

            /**
             * ========================
             * LAST 24 HOURS PRICE
             * ========================
             */
            $last_price_24_hour = $data[$id]['c']['close'];
            $previous_price_24_hour = $data[$id]['p']['close'];

            $data[$id]['last_price_24_hour'] = $last_price_24_hour;
            $data[$id]['previous_price_24_hour'] = $previous_price_24_hour;

            $last_price_24_change_percent = 0;
            if ($previous_price_24_hour && $previous_price_24_hour) {
                $last_price_24_change_percent = ((int) $this->CI->common_model->doSqlArithMetic("  ( ($last_price_24_hour * 100 ) / $previous_price_24_hour )"));
            }

            $data[$id]['last_price_24_change_flow'] = 'UP';
            $data[$id]['last_price_24_change_percent'] = 0;
            if ($last_price_24_hour && $previous_price_24_hour) {
                $last_price_24_change_percent = $last_price_24_change_percent == 0 ? 0 : $last_price_24_change_percent - 100;

                $data[$id]['last_price_24_change_flow'] = $this->CI->common_model->doSqlConditionCheck("( $last_price_24_hour > $previous_price_24_hour )") ? 'UP' : 'DN';
                $data[$id]['last_price_24_change_percent'] = $last_price_24_change_percent;
            }

            /**
             * =======================
             * LAST TRADED PRICES
             * =======================
             *
             */

            $last_price = $this->CI->coinhistory_model->getLastPrice($id);
            $previous_price = $this->CI->coinhistory_model->getPreviousPrice($id);

            $last_volume = $data[$id]['c']['volume'];
            $previous_volume = $data[$id]['p']['volume'];

            $data[$id]['last_price'] = $last_price;
            $data[$id]['previous_price'] = $previous_price;

            $data[$id]['last_price_change'] = $this->CI->common_model->doSqlArithMetic("( $last_price - $previous_price )");
            $data[$id]['last_price_change_flow'] = $this->CI->common_model->doSqlConditionCheck("( $last_price > $previous_price )") ? 'UP' : 'DN';

            $last_price_change_percent = ((int) $this->CI->common_model->doSqlArithMetic(" ( ($last_price * 100 ) / $previous_price )"));
            $last_price_change_percent = $last_price_change_percent == 0 ? 0 : $last_price_change_percent - 100;
            $data[$id]['last_price_change_percent'] = $last_price_change_percent;

            /**
             * =======================
             * LAST 24 HOURS VOLUMES
             * =======================
             *
             */

            $data[$id]['volume_change'] = $this->CI->common_model->doSqlArithMetic("( $last_volume - $previous_volume )");
            $data[$id]['volume_change_flow'] = $this->CI->common_model->doSqlConditionCheck("( $last_volume > $previous_volume )") ? 'UP' : 'DN';
            $volume_change_percent = ((int) $this->CI->common_model->doSqlArithMetic("(  ( $last_volume * 100 ) / $previous_volume )"));
            $volume_change_percent = $volume_change_percent == 0 ? 0 : $volume_change_percent - 100;

            $data[$id]['volume_change_percent'] = $volume_change_percent;
        }
        return $data;
    }
    
    private function _prepare_market_pairs()
    {
        $all_coin_pairs = $this->CI->web_model->coinPairs();
        $all_coin_pairs_summary = [];

        foreach ($all_coin_pairs as $pair) {
            $sql = "
                SELECT * FROM `dbt_coinhistory`
                INNER JOIN
                    (SELECT `coinpair_id`, MAX(`id`) AS maxid FROM `dbt_coinhistory`
                     WHERE dbt_coinhistory.`coinpair_id` = '$pair->id' GROUP BY `coinpair_id`) as topid
                ON dbt_coinhistory.`coinpair_id` = topid.`coinpair_id` AND dbt_coinhistory.`id` = topid.`maxid`
            ";
            $summary_query = $this->CI->db->query($sql);

            $sql_trade = "
                SELECT * FROM `dbt_biding_log`
                WHERE `coinpair_id` = '$pair->id'
                ORDER BY log_id DESC LIMIT 3
            ";
            $last_three_trades_query = $this->CI->db->query($sql_trade);

            $coin_details = [
                // 'coin_symbol' => $pair->currency_symbol,
                'symbol' => $pair->symbol,
                'coinpair_id' => $pair->id,
                'name' => $pair->name,
                'full_name' => $pair->full_name,
                'status' => $pair->status,
                'last_price' => 0,
                'percent_change' => 0,
                'price_flow' => 0, // 0 = stable, 1 = decreased, 2 = increased
            ];

            if ($last_three_trades_query->num_rows() > 0) {

                $last_three_trades_queryArr = $last_three_trades_query->result_array();

                if (!isset($last_three_trades_queryArr[1]) && $last_three_trades_queryArr[0]['bid_type'] == 'SELL') {
                    $coin_details['price_flow'] = 1;
                    $coin_details['percent_change'] = 0;
                } else {

                    $p1 = floatval($last_three_trades_queryArr[0]['bid_price']);
                    $p2 = floatval($last_three_trades_queryArr[1]['bid_price']);

                    if ($p1 == $p2) {
                        $coin_details['price_flow'] = $last_three_trades_queryArr[0]['bid_type'] == 'SELL' ? 1 : 2;
                        $coin_details['percent_change'] = 0;
                    } else if ($p1 > $p2) {
                        $coin_details['price_flow'] = 2;
                        if ($p1 == 0) {
                            $coin_details['percent_change'] = 100;
                        } else {
                            $coin_details['percent_change'] = 100 - abs((100 * $p2) / $p1);
                        }

                    } else if ($p1 < $p2) {
                        $coin_details['price_flow'] = 1;
                        if ($p1 == 0) {
                            $coin_details['percent_change'] = 100;
                        } else {
                            $coin_details['percent_change'] = 100 - abs((100 * $p2) / $p1);
                        }

                    }
                }
            }

            if ($summary_query->num_rows() > 0) {
                $all_coin_pairs_summary[] = array_merge($summary_query->row_array(), $coin_details);
            } else {
                $all_coin_pairs_summary[] = $coin_details;
            }
        }

        return $all_coin_pairs_summary;
    }
}
