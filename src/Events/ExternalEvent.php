<?php

namespace PopulousWSS\Events;

use PopulousWSS\Channels\ExternalChannel;
use PopulousWSS\ServerHandler;

class ExternalEvent extends ExternalChannel
{
    public function __construct(ServerHandler $server)
    {
        parent::__construct($server);
    }


    public function _merge_binance_orderbook($popexOrderbook, $binanceOrderbook)
    {
        // TOdo : Merge 2 orderbook

        $buyOrders = [];
        $sellOrders = [];
        $popexBuyers = $popexOrderbook['buy_orders']; // [ 'all_users' => '', 'bid_price' => '', 'bid_type' => 'BUY', 'total_price' => '', 'total_qty' => ''  ]
        $popexSellers = $popexOrderbook['sell_orders']; // [ 'all_users' => '', 'bid_price' => '', 'bid_type' => 'BUY', 'total_price' => '', 'total_qty' => ''  ]
        $binanceBuyers = $binanceOrderbook['bid']; //  [ 'price' , 'amount' ,'timestamp' ] eg: [ "23815.02000000" , "0.02404400", 7249895760]

        foreach ($binanceBuyers as $price => $qty) {
            $totalPrice = $this->DM->safe_multiplication([$price, $qty]);
            $buyOrders[$price] = ['all_users' => '', 'bid_price' => $price, 'bid_type' => 'BUY', 'total_qty' => $qty, 'total_price' => $totalPrice];
        }

        $binanceSellers = $binanceOrderbook['ask'];

        foreach ($binanceSellers as $price => $qty) {
            $totalPrice = $this->DM->safe_multiplication([$price, $qty]);
            $sellOrders[$price] = ['all_users' => '', 'bid_price' => $price, 'bid_type' => 'SELL', 'total_qty' => $qty, 'total_price' => $totalPrice];
        }


        foreach ($popexBuyers as $_pb) {

            if (!isset($buyOrders[$_pb['bid_price']])) {
                $buyOrders[$_pb['bid_price']] = $_pb;
            } else {
                // It's exist, Calculate it

                $buyOrders[$_pb['bid_price']]['total_qty'] = $this->DM->safe_add([$buyOrders[$_pb['bid_price']]['total_qty'], $_pb['total_qty']]);
                $buyOrders[$_pb['bid_price']]['total_price'] = $this->DM->safe_add([$buyOrders[$_pb['bid_price']]['total_price'], $_pb['total_price']]);
            }
        }


        foreach ($popexSellers as $_ps) {
            if (!isset($sellOrders[$_ps['bid_price']])) {
                $sellOrders[$_ps['bid_price']] = $_ps;
            } else {
                // It's exist, Calculate it
                $sellOrders[$_ps['bid_price']]['total_qty'] = $this->DM->safe_add([$sellOrders[$_ps['bid_price']]['total_qty'], $_ps['total_qty']]);
                $sellOrders[$_ps['bid_price']]['total_price'] = $this->DM->safe_add([$sellOrders[$_ps['bid_price']]['total_price'], $_ps['total_price']]);
            }
        }

        krsort($buyOrders); // Ascending 
        krsort($sellOrders); // Descending
        $buyOrders = array_slice( array_values($buyOrders), 0, 40 );
        $sellOrders = array_slice(array_values($sellOrders), 0, 40 );


        // $popexSellers = $popexOrderbook['sell_orders'];
        // $binanceSellers = $binanceOrderbook['ask'];  


        return ['buy_orders' => $buyOrders, 'sell_orders' => $sellOrders];
    }

    public function _prepare_binance_orderbook_update($coinpairId, $binance_orderbook)
    {

        // Get current popex orderbook
        $popexOrderbook = $this->CI->WsServer_model->get_orders($coinpairId, 40, 'array');

        return [
            'event' => 'orderbook',
            'data' =>  $this->_merge_binance_orderbook($popexOrderbook, $binance_orderbook)
        ];
    }

    public function _event_binance_orderbook_update($primaryCoinSymbol, $secondaryCoinSymbol, $orderbook)
    {

        $coinSymbol = strtoupper($primaryCoinSymbol) . '_' . strtoupper($secondaryCoinSymbol);

        $coinpairId = $this->CI->WsServer_model->get_coinpair_id_by_symbol($coinSymbol);

        $event = $this->_prepare_binance_orderbook_update($coinpairId, $orderbook);

        $marketGlobalChannel = $this->CI->WsServer_model->get_market_global_channel(strtolower($coinSymbol));

        // Publish event to 
        // {"event":"orderbook","channel":"market-ppt_usdt-global",
        //"data":{"buy_orders":[{"bid_type":"BUY","bid_price":"2.0000","all_users":"5JOI9S","total_qty":"2.00000000","total_price":"4.0000"},{"bid_type":"BUY","bid_price":"1.0000","all_users":"5JOI9S","total_qty":"10.00000000","total_price":"10.0000"}],"sell_orders":[]}}

        $channels = [];
        $channels[$marketGlobalChannel] = [];
        $channels[$marketGlobalChannel][] = $event;

        return $channels;
    }
}
