<?php

namespace PopulousWSS\Events;

use PopulousWSS\Channels\PublicChannel;
use PopulousWSS\ServerHandler;

class PublicEvent extends PublicChannel
{
    public function __construct(ServerHandler $server)
    {
        parent::__construct($server);
    }


    public function _event_coinpair_update(int $coin_id)
    {
        $channels = [];
        $coin_symbol = strtolower($this->CI->WsServer_model->get_coin_symbol_by_coin_id($coin_id));

        $market_channel = $this->CI->WsServer_model->get_market_global_channel($coin_symbol);
        $crypto_rate_channel = $this->CI->WsServer_model->get_crypto_rate_channel();

        $channels[$market_channel] = [
            $this->_prepare_order_book($coin_id),
            $this->_prepare_trade($coin_id),
        ];

        $channels[$crypto_rate_channel] = [
            $this->_prepare_last_price($coin_id),
            $this->_prepare_crypto_prices(), // All crypto prices 
        ];

        $this->_push_event_to_channels($channels);
    }


    public function _event_trade_create($log_id)
    {
        $tc_event = $this->_prepare_trade_create($log_id);

        if ($tc_event) {

            $coin_symbol            = $this->CI->WsServer_model->get_coin_symbol_by_coin_id($tc_event['data']['coinpair_id']);
            $market_channel         = $this->CI->WsServer_model->get_market_global_channel($coin_symbol);

            $channels = [];
            $channels[$market_channel] = [];
            $channels[$market_channel][] = $tc_event;


            $this->_push_event_to_channels($channels);
        }
    }

    public function _event_24h_summary_update()
    {

        $summary = $this->_prepare_24_hour_summary();

        if ($summary) {

            $market_summary_channel = $this->CI->WsServer_model->get_market_summary_channel();
            $channels = [];

            $channels[$market_summary_channel] = [];
            $channels[$market_summary_channel][] = $summary;

            $this->_push_event_to_channels($channels);
        }
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
        $orderBook = $this->CI->WsServer_model->get_orders($coin_id);
        if (isset($this->exchanges['BINANCE'])) {
            $symbol = $this->CI->WsServer_model->get_coin_symbol_by_coin_id($coin_id);
            $symbol = strtoupper(str_replace('_', '', $symbol));
            $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBook($symbol);
            // $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBookRes();

            $orderBook = $this->_merge_binance_orderbook($orderBook,  $binanceOrderbook);
        }

        return $orderBook;
    }

    private function _prepare_order_book($coin_id)
    {

        return [
            'event' => 'orderbook',
            'data' => $this->get_order_book($coin_id)
        ];
    }

    private function _prepare_trade($coin_id)
    {
        return [
            'event' => 'trade-history',
            'data' => $this->CI->WsServer_model->get_trades_history($coin_id, 20),
        ];
    }

    private function _prepare_last_price($coin_id)
    {
        return [
            'event' => 'price-change',
            'data' => [
                'current_price' => $this->CI->WsServer_model->get_last_price_from_coin_id($coin_id),
                'previous_price' => $this->CI->WsServer_model->get_previous_price_from_coin_id($coin_id),
            ],
        ];
    }

    private function _prepare_crypto_prices()
    {

        return [
            'event' => 'crypto-prices',
            'data' => $this->get_crypto_rates()
        ];
    }

    private function _prepare_trade_create($log_id)
    {
        $biding_log = $this->CI->WsServer_model->get_biding_log($log_id);

        if ($biding_log) {

            unset($biding_log['user_id'], $biding_log['fees_amount'], $biding_log['available_amount']);

            $biding_log['time'] = (int) strtotime(date('Y/m/d H:i:s', strtotime($biding_log['success_time'])));
            $biding_log['time_ms'] = (int) $biding_log['time'] * 1000;

            unset($biding_log['success_time']);

            return [
                'event' => 'trade-created',
                'data' => $biding_log,
            ];
        }

        return false;
    }

    public function get_24_hour_summary()
    {
        return $this->CI->WsServer_model->get_coinpairs_24h_summary();
        // return $this->CI->WsServer_model->get_all_active_coinpairs_24h_summary();
    }

    public function _prepare_24_hour_summary()
    {
        $summary = $this->get_24_hour_summary();

        if ($summary) {

            return [
                'event' => 'market-update-24h-summary',
                'data' => $summary,
            ];
        }

        return false;
    }

    public function get_crypto_rates()
    {
        return $this->CI->WsServer_model->all_crypto_prices();
    }

    public function _prepare_exchange_init_data($coin_id)
    {
        $orders = $this->get_order_book($coin_id, 40);

        return [
            'market_pairs' => $this->CI->WsServer_model->get_market_pairs(),
            'trade_history' => $this->CI->WsServer_model->get_trades_history($coin_id, 60),
            'coin_history' => $this->CI->WsServer_model->get_coins_history($coin_id, 20),
            'crypto_rates' => $this->get_crypto_rates(),
            'buy_orders' => $orders['buy_orders'],
            'sell_orders' => $orders['sell_orders'],
        ];
    }


    public function _prepare_market_init_data()
    {

        return [
            'summary_24h' => $this->get_24_hour_summary(),
            'crypto_rates' => $this->get_crypto_rates()
        ];
    }
}
