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


    public function _prepare_binance_orderbook_update($coinpairId, $binanceOrderbook)
    {

        // Get current popex orderbook
        // Disabled POPEX ORDERBOOK MERGING
        // $orderBook = $this->CI->WsServer_model->get_orders($coinpairId, 40, 'array'); 

        $orderBook = ['buy_orders' => [], 'sell_orders' => []];

        if (isset($this->exchanges['BINANCE'])) {
            $symbol = $this->CI->WsServer_model->get_coin_symbol_by_coin_id($coinpairId);
            $symbol = strtoupper(str_replace('_', '', $symbol));
            // $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBook($symbol);
            // $binanceOrderbook = $this->exchanges['BINANCE']->getOrderBookRes();

            $popex_buyOrders    = $this->CI->WsServer_model->get_popex_orders_price_userId($coinpairId, 40, BID_PENDING_STATUS, 'BUY');
            $popex_sellOrders   = $this->CI->WsServer_model->get_popex_orders_price_userId($coinpairId, 40, BID_PENDING_STATUS, 'SELL');

            $orderBook = $this->CI->WsServer_model->merge_orderbook($popex_buyOrders, $popex_sellOrders,  $binanceOrderbook['bid'], $binanceOrderbook['ask']);
        }


        return [
            'event' => 'orderbook',
            'data' =>  $orderBook
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

        if ($marketGlobalChannel != null) {
            $channels[$marketGlobalChannel] = [];
            $channels[$marketGlobalChannel][] = $event;
        }

        return $channels;
    }

    public function _event_binance_order_update(array $binanceOrderDetail)
    {
        // Find order of popex by binance's order Id
        // Update bid_qty_available, amount_available, Update status (optional)


        $tradeDetail = $this->CI->WsServer_model->getPopexOrderByClientOrderId($binanceOrderDetail['clientId']);

        $channels = [];

        if ($tradeDetail != null) {

            $executionType = $binanceOrderDetail['executionType'];
            $orderStatus = $binanceOrderDetail['orderStatus'];

            log_message('debug', 'Execution Type ' . $executionType);
            log_message('debug', 'Order status ' . $orderStatus);

            if ($orderStatus  == BINANCE_ORDER_STATUS_CANCELED) {
                // $this->CI->trade->binance_cancel_trade($binanceOrderDetail['id']);
                // We will be not supporting this event , since this cancellation feature is being used by PHP API directly 
            } else if ($orderStatus == BINANCE_ORDER_STATUS_PARTIALLY_FILLED || $orderStatus == BINANCE_ORDER_STATUS_FILLED) {
                // Update bid_qty_available, amount_available

                log_message('debug', '-----------BINANCE ORDER UPDATE STARTED------------------');

                $primary_coin_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($tradeDetail['coinpair_id']);
                $secondary_coin_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($tradeDetail['coinpair_id']);


                log_message('debug', 'Primary coin Id ' . $primary_coin_id);
                log_message('debug', 'Secondary coin Id ' . $secondary_coin_id);


                $quantity = $binanceOrderDetail['quantity'];
                $trade_price = $binanceOrderDetail['price'];
                $trade_amount   = $this->CI->DM->safe_multiplication([$quantity, $trade_price]);

                $side = strtoupper($binanceOrderDetail['side']);

                log_message('debug', 'Side : ' . $side);
                log_message('debug', 'quantity : ' . $quantity);
                log_message('debug', 'trade_price : ' . $trade_price);
                log_message('debug', 'trade_amount : ' . $trade_amount);

                $success_datetimestamp = intval($binanceOrderDetail['eventTime']);

                $log_id = null;

                $newPopexStatus = null;

                if ($orderStatus == BINANCE_ORDER_STATUS_FILLED) {

                    /**
                     * Incase of binance event not received by popex correctly
                     * Need to calculate exact remaining bid qty 
                     * 
                     * 20 QTY IN ORDER 
                     * 10 FILLED, 10 AVAILABLE  on Popex
                     * 7 FILLED MISSING QTY : Message FAILED
                     * 3 FILLED : Message Received
                     * 10 AVAILABLE + 3 =   '20.00000000' - 13  = 7
                     * q: '20.00000000',
                     */

                    $remainingQtyBefore = $tradeDetail['bid_qty_available'];
                    $remainingQtyAfter = $this->CI->DM->safe_minus([$remainingQtyBefore, $quantity]);

                    if ($this->CI->DM->isZero($remainingQtyAfter)) {
                        // It's fine , Not need to do anything special
                    } else {
                        // It's not fine 
                        // Some qty update has been missed 
                        // Feel the gap of missing qty by adding missing qty into quantity received from binance order detail

                        $quantity = $this->CI->DM->safe_add([$quantity, $remainingQtyAfter]);
                        $trade_amount   = $this->CI->DM->safe_multiplication([$quantity, $trade_price]);
                    }

                    $newPopexStatus = PopulousWSSConstants::BID_COMPLETE_STATUS;
                } else {
                    // PARTIAL ORDER UPDATE
                    $newPopexStatus = PopulousWSSConstants::BID_PENDING_STATUS;
                }

                $this->wss_server->trade->_binance_order_interal_update($primary_coin_id, $secondary_coin_id, $side, $tradeDetail, $quantity, $trade_price, $newPopexStatus, $success_datetimestamp);

                log_message('debug', '-----------BINANCE ORDER UPDATE FINISHED------------------');
            } else if ($orderStatus == BINANCE_ORDER_STATUS_REJECTED || $orderStatus == BINANCE_ORDER_STATUS_EXPIRED) {
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

        $this->CI->WsServer_model->create_or_update_global_price($newPriceData['symbol'], $newPriceData['price'], hexdec($newPriceData['last_updated_ts']['_hex']));
        $coinPriceDetail = $this->CI->WsServer_model->get_global_price_by_symbol($newPriceData['symbol']);

        $channels = [];

        // TODO : Publish crypto_rates event to public channels

        $cryptoRatesChannel = $this->CI->WsServer_model->get_crypto_rate_channel();

        if ($cryptoRatesChannel != null) {
            $channels[$cryptoRatesChannel] = [];
            $channels[$cryptoRatesChannel][] = $this->_prepare_crypto_prices();
        }


        return $channels;

        // log_message('debug', '_event_global_price_update');
    }
}
