<?php

namespace PopulousWSS\Events;

use PopulousWSS\Channels\PrivateChannel;
use PopulousWSS\ServerHandler;

class PrivateEvent extends PrivateChannel
{

    public function __construct(ServerHandler $server)
    {
        parent::__construct($server);
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

    private function get_order_book($coin_id)
    {
        $orderBook = $this->CI->WsServer_model->get_orders($coin_id, 50, 'array');
        if (isset($this->exchanges['BINANCE'])) {
            $symbol = $this->CI->WsServer_model->get_coin_symbol_by_coin_id($coin_id);
            $symbol = strtoupper(str_replace('_', '', $symbol));
            $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBook($symbol);

            if ($binanceOrderbook == null) {
                $binanceOrderbook = ['bids' => [], 'asks' => []];
            }
            // $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBookRes();

            $orderBook = $this->CI->WsServer_model->merge_orderbook($orderBook['buy_orders'], $orderBook['sell_orders'],  $binanceOrderbook['bids'], $binanceOrderbook['asks']);
        }

        return $orderBook;
    }
    public function _prepare_exchange_init_data($coin_id, $auth): array
    {
        $user_id = $this->_get_user_id($auth);

        $orders = $this->get_order_book($coin_id);

        return [
            'market_pairs' => $this->CI->WsServer_model->get_market_pairs(),
            'trade_history' => $this->CI->WsServer_model->get_trades_history($coin_id, 60),
            'coin_history' => $this->CI->WsServer_model->get_coins_history($coin_id, 20),
            'buy_orders' => $orders['buy_orders'],
            'sell_orders' => $orders['sell_orders'],
            'pending_orders' => $this->CI->WsServer_model->get_pending_orders($coin_id, 6, $user_id),
            'completed_orders' => $this->CI->WsServer_model->get_completed_orders($coin_id, 6, $user_id),
            'user_balance' => $this->CI->WsServer_model->get_user_balances($user_id),
            'crypto_rates' => $this->CI->WsServer_model->all_crypto_prices()
        ];
    }
}
