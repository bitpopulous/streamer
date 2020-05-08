<?php
namespace PopulousWSS\Events;

use PopulousWSS\Channels\PublicChannel;
use PopulousWSS\ServerHandler;

class PublicEvent extends PublicChannel
{
    private $wss_server;

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

    public function _event_coinpair_update(int $coin_id)
    {
        $channels = [];
        $coinpair_symbol = strtolower($this->CI->cryptocoin_model->getCoinSymbolOfCoinpairId($coin_id));

        $market_channel = $this->CI->channels_model->getMarketGlobalChannel($coinpair_symbol);
        $cryptoRatesChannel = $this->CI->channels_model->getCryptoRateChannel();

        $channels[$market_channel] = [
            $this->_prepare_order_book($coin_id),
            $this->_prepare_trade($coin_id),
        ];

        $channels[$cryptoRatesChannel] = [
            $this->_prepare_last_price($coin_id),
        ];

        $this->_push_event_to_channels($channels);
    }

    public function _event_trade_create($log_id)
    {
        $tce = $this->_prepare_trade_create($log_id);

        if ($tce) {

            $coinpair_symbol = $this->CI->cryptocoin_model->getCoinSymbolOfCoinpairId($tce['data']['coinpair_id']);
            $market_channel = $this->CI->channels_model->getMarketGlobalChannel($coinpair_symbol);

            $channels = [];
            $channels[$market_channel] = [];
            $channels[$market_channel][] = $tce;

            $this->_push_event_to_channels($channels);
        }
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

    private function _prepare_order_book($coin_id)
    {
        $data = $this->CI->biding_model->getBuySellOrders($coin_id);

        $buy_orders = $this->CI->convertdata->convertDataArray($data['buy_orders'], ['bid_price:1', 'total_qty:0'], $coin_id);
        $sell_orders = $this->CI->convertdata->convertDataArray($data['sell_orders'], ['bid_price:1', 'total_qty:0'], $coin_id);

        return [
            'event' => 'orderbook',
            'data' => [
                'buy_orders' => $buy_orders,
                'sell_orders' => $sell_orders,
            ],
        ];
    }

    private function _prepare_trade($coin_id)
    {
        $tradings = $this->CI->bidinglog_model->tradeHistoryByPairId($coin_id, 20);

        return [
            'event' => 'trade-history',
            'data' => $this->CI->convertdata->convertDataArray($tradings, ['bid_price:1', 'complete_qty:0'], $coin_id),
        ];
    }

    private function _prepare_last_price($coin_id)
    {
        return [
            'event' => 'price-change',
            'data' => [
                'current_price' => $this->CI->bidinglog_model->getLastSuccessTradePriceLog($coin_id),
                'previous_price' => $this->CI->bidinglog_model->getPreviousSuccessTradePriceLog($coin_id),
            ],
        ];
    }

    private function _prepare_trade_create($log_id)
    {
        $biding_log = $this->CI->bidinglog_model->getLogById($log_id);

        if ($biding_log) {

            unset($biding_log['user_id'], $biding_log['fees_amount'], $biding_log['available_amount']);

            $biding_log['time'] = (int) strtotime(date('Y/m/d H:i', strtotime($biding_log['success_time'])));
            $biding_log['time_ms'] = (int) $biding_log['time'] * 1000;

            unset($biding_log['success_time']);

            return [
                'event' => 'trade-created',
                'data' => $biding_log,
            ];
        }

        return false;
    }

    public function _prepare_exchange_init_data($coin_id)
    {
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
        
        return [
            'coinpairs_24h_summary' => $this->_coinpairs_24h_summary(),
            'market_pairs' => $this->_prepare_market_pairs(),
            'trade_history' => $trades,
            'coin_history' => $coins,
            'buy_orders' => $b_orders,
            'sell_orders' => $s_orders,
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
                'coin_symbol' => $pair->currency_symbol,
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
