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

    private function _merge_binance_orderbook($popexOrderbook, $binanceOrderbook)
    {
        // TOdo : Merge 2 orderbook

        $buyOrders = [];
        $sellOrders = [];
        $popexBuyers = $popexOrderbook['buy_orders']; // [ 'all_users' => '', 'bid_price' => '', 'bid_type' => 'BUY', 'total_price' => '', 'total_qty' => ''  ]
        $popexSellers = $popexOrderbook['sell_orders']; // [ 'all_users' => '', 'bid_price' => '', 'bid_type' => 'BUY', 'total_price' => '', 'total_qty' => ''  ]
        $binanceBuyers = $binanceOrderbook['bids']; //  [ 'price' , 'amount' ,'timestamp' ] eg: [ "23815.02000000" , "0.02404400", 7249895760]
        krsort($binanceBuyers); // high to low


        log_message('debug', "==================MERGING ORDER BOOK START=====================");
        log_message('debug', "Popex Buyers");
        log_message('debug', json_encode($popexBuyers));

        log_message('debug', "Popex Sellers");
        log_message('debug', json_encode($popexSellers));

        foreach ($binanceBuyers as $price => $qty) {
            $totalPrice = $this->DM->safe_multiplication([$price, $qty]);
            $buyOrders[$price] = ['all_users' => '', 'bid_price' => $price, 'bid_type' => 'BUY', 'total_qty' => $qty, 'total_price' => $totalPrice];
        }

        $binanceSellers = $binanceOrderbook['asks'];
        krsort($binanceSellers); // High to low

        log_message('debug', "Binance Sellers");
        log_message('debug', json_encode($binanceSellers));

        log_message('debug', "Binance Buyers");
        log_message('debug', json_encode($binanceBuyers));

        foreach ($binanceSellers as $price => $qty) {
            $totalPrice = $this->DM->safe_multiplication([$price, $qty]);
            $sellOrders[$price] = ['all_users' => '', 'bid_price' => $price, 'bid_type' => 'SELL', 'total_qty' => $qty, 'total_price' => $totalPrice];
        }


        foreach ($popexBuyers as $_pb) {

            if (!isset($buyOrders[$_pb['bid_price']])) {
                $buyOrders[$_pb['bid_price']] = $_pb;
            } else {
                // It's exist, Calculate it

                log_message("debug", "Popex BUY order");
                log_message("debug", json_encode($_pb));

                log_message('debug', "Before merge one buyer");
                log_message('debug', json_encode($buyOrders[$_pb['bid_price']]));

                $buyOrders[$_pb['bid_price']]['total_qty'] = $this->DM->safe_add([$buyOrders[$_pb['bid_price']]['total_qty'], $_pb['total_qty']]);
                $buyOrders[$_pb['bid_price']]['total_price'] = $this->DM->safe_add([$buyOrders[$_pb['bid_price']]['total_price'], $_pb['total_price']]);

                log_message('debug', "After merge one buyer");
                log_message('debug', json_encode($buyOrders[$_pb['bid_price']]));
            }
        }


        foreach ($popexSellers as $_ps) {
            if (!isset($sellOrders[$_ps['bid_price']])) {
                $sellOrders[$_ps['bid_price']] = $_ps;
            } else {
                // It's exist, Calculate it
                log_message("debug", "Popex SELL order");
                log_message("debug", json_encode($_ps));

                log_message('debug', "Before merge one seller");
                log_message('debug', json_encode($sellOrders[$_ps['bid_price']]));

                $sellOrders[$_ps['bid_price']]['total_qty'] = $this->DM->safe_add([$sellOrders[$_ps['bid_price']]['total_qty'], $_ps['total_qty']]);
                $sellOrders[$_ps['bid_price']]['total_price'] = $this->DM->safe_add([$sellOrders[$_ps['bid_price']]['total_price'], $_ps['total_price']]);

                log_message('debug', "After merge one seller");
                log_message('debug', json_encode($sellOrders[$_ps['bid_price']]));
            }
        }


        krsort($buyOrders); // Hight to low
        krsort($sellOrders); // High to low


        log_message('debug', "MERGED BUY ORDERS : ");
        log_message('debug', json_encode($buyOrders));

        log_message('debug', "MERGED SELL ORDERS : ");
        log_message('debug', json_encode($sellOrders));


        $buyOrders = array_values($buyOrders);
        $sellOrders = array_values($sellOrders);

        // $buyOrders = array_slice($buyOrders, 0, 30);
        // $sellOrders = array_slice($sellOrders, 0, 30);


        // $popexSellers = $popexOrderbook['sell_orders'];
        // $binanceSellers = $binanceOrderbook['ask'];  


        return ['buy_orders' => $buyOrders, 'sell_orders' => $sellOrders];
    }

    private function get_order_book($coin_id)
    {
        $orderBook = $this->CI->WsServer_model->get_orders($coin_id, 50, 'array');
        if (isset($this->exchanges['BINANCE'])) {
            $symbol = $this->CI->WsServer_model->get_coin_symbol_by_coin_id($coin_id);
            $symbol = strtoupper(str_replace('_', '', $symbol));
            $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBook($symbol);
            // $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBookRes();

            $orderBook = $this->_merge_binance_orderbook($orderBook,  $binanceOrderbook);
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
