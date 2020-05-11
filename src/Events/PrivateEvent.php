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
        $user_channels = $this->CI->WsServer_model->get_user_channels($user_id);
        $coin_id = $this->CI->WsServer_model->get_coin_id_by_order_id($order_id);

        $user_channels = array_unique($user_channels);
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
        return [
            'event' => 'order-update',
            'data' => $this->CI->WsServer_model->get_order($order_id)
        ];
    }

    private function _prepare_user_balances_update($coin_id, $user_id): array
    {

        $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coin_id);
        $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coin_id);

        return [
            'event' => 'balance-update',
            'data' => [
                $this->CI->WsServer_model->user_balances_by_coin_id($user_id, $primary_coin_id),
                $this->CI->WsServer_model->user_balances_by_coin_id($user_id, $secondary_coin_id),
            ],
        ];
    }

    public function _prepare_exchange_init_data($coin_id, $auth): array
    {
        $user_id = $this->_get_user_id($auth);
        
        $orders = $this->CI->WsServer_model->get_orders($coin_id, 40);
        
        return [
            'coinpairs_24h_summary' => $this->_coinpairs_24h_summary(),
            'market_pairs' => $this->CI->WsServer_model->get_market_pairs(),
            'trade_history' => $this->CI->WsServer_model->get_trades_history($coin_id, 60),
            'coin_history' => $this->CI->WsServer_model->get_coins_history($coin_id, 20),
            'buy_orders' => $orders['buy_orders'],
            'sell_orders' => $orders['sell_orders'],
            'pending_orders' => $this->CI->WsServer_model->get_pending_orders($coin_id, 6, $user_id),
            'completed_orders' => $this->CI->WsServer_model->get_completed_orders($coin_id, 6, $user_id),
            'user_balance' => $this->CI->WsServer_model->get_user_balances($user_id),
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
}
