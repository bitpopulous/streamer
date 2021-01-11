<?php

namespace PopulousWSS\Events;

use PopulousWSS\Channels\ExternalChannel;
use PopulousWSS\ServerHandler;
use PopulousWSS\Common\PopulousWSSConstants;

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

        krsort($buyOrders); // High to low 
        krsort($sellOrders); // High to low

        $buyOrders = array_values($buyOrders);
        $sellOrders = array_values($sellOrders);

        // $buyOrders = array_slice(array_values($buyOrders), 0, 30);
        // $sellOrders = array_slice(array_values($sellOrders), 0, 30);

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

    public function _event_binance_order_update(array $binanceOrderDetail)
    {
        // Find order of popex by binance's order Id
        // Update bid_qty_available, amount_available, Update status (optional)


        $tradeDetail = $this->CI->WsServer_model->getPopexOrderByBinanceOrderId($binanceOrderDetail['id']);

        $channels = [];

        if ($tradeDetail != null) {

            $executionType = $binanceOrderDetail['executionType'];

            if ($executionType  == BINANCE_ORDER_STATUS_CANCELED) {
                // $this->CI->trade->binance_cancel_trade($binanceOrderDetail['id']);
                // We will be not supporting this event , since this cancellation feature is being used by PHP API directly 
            } else if ($executionType == BINANCE_ORDER_STATUS_PARTIALLY_FILLED) {
                // Update bid_qty_available, amount_available

                $this->log->info('-----------BINANCE PARTIAL ORDER UPDATE STARTED------------------');

                $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($tradeDetail->coinpair_id);
                $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($tradeDetail->coinpair_id);

                $this->log->info('Primary coin Id ' . $primary_coin_id);
                $this->log->info('Primary coin Id : ' . $primary_coin_id);
                $this->log->info('Secondary coin Id : ' . $secondary_coin_id);

                $quantity = $binanceOrderDetail['quantity'];
                $trade_price = $binanceOrderDetail['price'];
                $trade_amount   = $this->CI->DM->safe_multiplication([$quantity, $trade_price]);

                $side = strtoupper($binanceOrderDetail['side']);

                $this->log->info('Side : ' . $side);
                $this->log->info('quantity : ' . $quantity);
                $this->log->info('trade_price : ' . $trade_price);
                $this->log->info('trade_amount : ' . $trade_amount);

                $success_datetime = date('Y-m-d H:i:s', $binanceOrderDetail['eventTime']);
                $success_datetimestamp = $binanceOrderDetail['eventTime'];

                $log_id = null;


                if ($side == 'BUY') {
                    // Buy order
                    // ORDER UPDATE
                    $buytrade = (object) $tradeDetail;

                    $buyer_av_bid_amount_after_trade = $this->DM->safe_minus([$buytrade->amount_available, $trade_amount]);
                    $buyer_av_qty_after_trade = $this->DM->safe_minus([$buytrade->bid_qty_available, $quantity]);

                    $this->log->info('buyer_av_bid_amount_after_trade : ' . $buyer_av_bid_amount_after_trade);
                    $this->log->info('buyer_av_qty_after_trade : ' . $buyer_av_qty_after_trade);

                    $buyupdate = array(
                        'bid_qty_available' => $buyer_av_qty_after_trade,
                        'amount_available' => $buyer_av_bid_amount_after_trade,
                        'status' =>  PopulousWSSConstants::BID_PENDING_STATUS,
                    );

                    $this->log->info('BUY TRADE UPDATE ->', $buyupdate);


                    $buytraderlog = array(
                        'bid_id' => $buytrade->id,
                        'bid_type' => $buytrade->bid_type,
                        'complete_qty' => $quantity,
                        'bid_price' => $trade_price,
                        'complete_amount' => $trade_amount,
                        'user_id' => $buytrade->user_id,
                        'coinpair_id' => $buytrade->coinpair_id,
                        'success_time' => $success_datetime,
                        'fees_amount' => 0,
                        'available_amount' => $buyer_av_qty_after_trade,
                        'status' =>  PopulousWSSConstants::BID_PENDING_STATUS,
                    );


                    $this->log->info('debug', 'BUY TRADER LOG -> ', $buytraderlog);

                    $this->CI->WsServer_model->update_order($buytrade->id, $buyupdate);

                    // BALANCE UPDATE
                    $this->trade->_buyer_trade_balance_update($buytrade->user_id, $primary_coin_id, $secondary_coin_id, $quantity, $trade_amount);

                    $log_id = $this->CI->WsServer_model->insert_order_log($buytraderlog);

                    $this->CI->WsServer_model->update_current_minute_OHLCV($buytrade->coinpair_id, $trade_price, $quantity, $success_datetimestamp);
                } else if ($side == 'SELL') {
                    // Sell orders

                    $selltrade = (object) $tradeDetail;

                    $seller_av_bid_amount_after_trade = $this->DM->safe_minus([$selltrade->amount_available, $trade_amount]);
                    $seller_av_qty_after_trade = $this->DM->safe_minus([$selltrade->bid_qty_available, $quantity]);

                    $this->log->info('seller_av_bid_amount_after_trade : ' . $seller_av_bid_amount_after_trade);
                    $this->log->info('seller_av_qty_after_trade : ' . $seller_av_qty_after_trade);

                    $sellupdate = array(
                        'bid_qty_available' => $seller_av_qty_after_trade,
                        'amount_available' => $seller_av_bid_amount_after_trade,
                        'status' => PopulousWSSConstants::BID_PENDING_STATUS,
                    );

                    $this->log->info('SELL TRADE UPDATE -> ', $sellupdate);

                    $this->CI->WsServer_model->update_order($selltrade->id, $sellupdate);

                    $selltraderlog = array(
                        'bid_id' => $selltrade->id,
                        'bid_type' => $selltrade->bid_type,
                        'complete_qty' => $quantity,
                        'bid_price' => $trade_price,
                        'complete_amount' => $trade_amount,
                        'user_id' => $selltrade->user_id,
                        'coinpair_id' => $selltrade->coinpair_id,
                        'success_time' => $success_datetime,
                        'fees_amount' => 0,
                        'available_amount' => $seller_av_bid_amount_after_trade, // $seller_available_bid_amount_after_trade,
                        'status' =>  PopulousWSSConstants::BID_PENDING_STATUS,
                    );

                    $this->log->info('SELL TRADER LOG ->', $selltraderlog);

                    $log_id = $this->CI->WsServer_model->insert_order_log($selltraderlog);

                    // Updating Current minute OHLCV
                    $this->CI->WsServer_model->update_current_minute_OHLCV($selltrade->coinpair_id, $trade_price, $quantity, $success_datetimestamp);
                }

                $this->log->info('Log ID : ' . $log_id);


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
                            'order_id' => $tradeDetail->id,
                            'user_id' => $tradeDetail->user_id,
                        ]
                    );

                    if ($log_id != null) {

                        // EVENT for single trade
                        $this->wss_server->_event_push(
                            PopulousWSSConstants::EVENT_TRADE_CREATED,
                            [
                                'log_id' => $log_id,
                            ]
                        );
                    }

                    $this->wss_server->_event_push(
                        PopulousWSSConstants::EVENT_MARKET_SUMMARY,
                        []
                    );
                } catch (\Exception $e) {
                }

                $this->log->info('-----------BINANCE PARTIAL ORDER UPDATE FINISHED------------------');
            } else if ($executionType == BINANCE_ORDER_STATUS_FILLED) {
                // Make sure the order is not completed/filled
                // Update bid_qty_available, amount_available, status = BID_COMPLETE_STATUS


            } else if ($executionType == BINANCE_ORDER_STATUS_REJECTED || $executionType == BINANCE_ORDER_STATUS_EXPIRED) {
                // Do not do anything here yet
            }
        }

        return $channels;
    }


    private function _prepare_crypto_prices()
    {

        return [
            'event' => 'crypto-prices',
            'data' => $this->CI->WsServer_model->all_crypto_prices()
        ];
    }
    public function _event_global_price_update($coinPairSymbol,  array $newPriceData)
    {

        $this->CI->WsServer_model->create_or_update_global_price($newPriceData['symbol'], $newPriceData['price'], $newPriceData['last_updated_ts']);
        $coinPriceDetail = $this->CI->WsServer_model->get_global_price_by_symbol($newPriceData['symbol']);

        $channels = [];

        // TODO : Publish crypto_rates event to public channels

        $cryptoRatesChannel = $this->CI->WsServer_model->get_crypto_rate_channel();

        $channels[$cryptoRatesChannel] = [];
        $channels[$cryptoRatesChannel][] = $this->_prepare_crypto_prices();

        return $channels;

        // log_message('debug', '_event_global_price_update');
    }
}
