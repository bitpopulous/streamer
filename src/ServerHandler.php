<?php

namespace PopulousWSS;

use PopulousWSS\Common\PopulousWSSConstants;
use PopulousWSS\Events\ExternalEvent;
use PopulousWSS\Events\PrivateEvent;
use PopulousWSS\Events\PublicEvent;
use PopulousWSS\ServerBaseHandler;
use PopulousWSS\Trade\Trade;
use PopulousWSS\Trade\Buy;
use PopulousWSS\Trade\Sell;
use WSSC\Contracts\ConnectionContract;

class ServerHandler extends ServerBaseHandler
{
    protected $public_event;
    protected $private_event;
    protected $external_event;

    protected $buy;
    protected $sell;
    protected $trade;

    public function __construct()
    {
        parent::__construct();
        $this->public_event = new PublicEvent($this);
        $this->private_event = new PrivateEvent($this);
        $this->external_event = new ExternalEvent($this);
        $this->buy = new Buy($this);
        $this->sell = new Sell($this);
        $this->trade = new Trade($this);
    }

    public function _event_handler(ConnectionContract $recv, string $channel, string $event, array $data)
    {
        if ($this->_is_subscribe_required($event)) {

            if ($this->_is_private_channel($channel)) {
                return $this->private_event->_subscribe($recv, $channel, $data['auth']);
            } else if ($this->_is_external_channel($channel)) {
                return $this->external_event->_subscribe($recv, $channel);
            } else {
                return $this->public_event->_subscribe($recv, $channel);
            }
        } else {

            $_ip = explode(':', $recv->getPeerName())[0];
            $data['ip_address'] = $_ip;
            $this->_reply_msg($recv, $event, $channel, $data);
        }
    }

    public function _event_push(int $event_type, array $data)
    {

        switch ($event_type) {
            case PopulousWSSConstants::EVENT_ORDER_UPDATED:
                $user_id = $data['user_id'];
                $order_id = $data['order_id'];

                $this->private_event->_event_order_update($order_id, $user_id);
                break;

            case PopulousWSSConstants::EVENT_COINPAIR_UPDATED:
                $coin_id = $data['coin_id'];
                $this->public_event->_event_coinpair_update($coin_id);
                break;

            case PopulousWSSConstants::EVENT_TRADE_CREATED:
                $log_id = $data['log_id'];
                $this->public_event->_event_trade_create($log_id);
                break;

            case PopulousWSSConstants::EVENT_MARKET_SUMMARY:
                $this->public_event->_event_24h_summary_update();
                break;
            default:

                break;
        }
    }

    private function send_safe(ConnectionContract $recv, string $data)
    {
        foreach ($this->clients as $client) {
            if ($client->getPeerName() == null) {
                $this->log->debug(json_encode($client));
                $this->onClose($client);
            }
        }
        $recv->send($data);
    }

    private function _reply_msg(ConnectionContract $recv, string $event, string $channel, array $rData)
    {
        $this->log->debug("Reply Message , Event : " . $event . " Channel : " . $channel);

        if ($event == 'orderbook-init') {

            $market = $rData['market'];
            $coin_id = $this->CI->WsServer_model->get_coin_id_from_symbol($market);
            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->WsServer_model->get_orders($coin_id, 40),
            ];

            $this->send_safe($recv, json_encode($data_send));
        } else if ($event == 'exchange-buy') {

            $coin_id = $rData['pair_id'];
            $trade_type = $rData['trade_type'];
            $auth = $rData['ua'];

            if ($trade_type == 'limit') {

                $price = $rData['price'];
                $amount = $rData['amount'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->buy->_limit($coin_id, $amount, $price, $auth),
                ];

                $this->send_safe($recv, json_encode($data_send));
            } else if ($trade_type == 'market') {

                $amount = $rData['amount'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->buy->_market($coin_id, $amount, $auth),
                ];

                $this->send_safe($recv, json_encode($data_send));
            } else if ($trade_type == 'stop_limit') {

                $amount = $rData['amount'];
                $limit = $rData['limit'];
                $stop = $rData['stop'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->buy->_stop_limit($coin_id, $amount, $stop, $limit, $auth),
                ];

                $this->send_safe($recv, json_encode($data_send));
            }
            return;
        } else if ($event == 'exchange-sell') {
            $coin_id = $rData['pair_id'];
            $trade_type = $rData['trade_type'];
            $auth = $rData['ua'];

            if ($trade_type == 'limit') {

                $price = $rData['price'];
                $amount = $rData['amount'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->sell->_limit($coin_id, $amount, $price, $auth),
                ];

                $this->send_safe($recv, json_encode($data_send));
            } else if ($trade_type == 'market') {

                $amount = $rData['amount'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->sell->_market($coin_id, $amount, $auth),
                ];

                $this->send_safe($recv, json_encode($data_send));
            } else if ($trade_type == 'stop_limit') {

                $amount = $rData['amount'];
                $limit = $rData['limit'];
                $stop = $rData['stop'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->sell->_stop_limit($coin_id, $amount, $stop, $limit, $auth),
                ];

                $this->send_safe($recv, json_encode($data_send));
            }
            return;
        } else if ($event == 'exchange-cancel-order') {

            $order_id = $rData['order_id'];
            $auth = $rData['ua'];

            // Checking if the order id is sent on external exchanges here, 
            // If it is external order, need to send cancel event to the external exchange

            $isBinance = $this->CI->WsServer_model->isBinanceOrder($order_id);

            log_message('debug', 'Order Id ' . $order_id);
            log_message('debug', 'Is binance Order ' . $isBinance);

            if ($isBinance) {

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->trade->binance_cancel_order($order_id, $auth, $rData)
                ];
                $this->send_safe($recv, json_encode($data_send));
            } else {

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->trade->cancel_order($order_id, $auth, $rData),
                ];
                $this->send_safe($recv, json_encode($data_send));
            }
        } else if ($event == 'exchange-init') {

            $market = $rData['market'];
            $coin_id = $this->CI->WsServer_model->get_coin_id_from_symbol($market);
            $ua = $rData['ua'];
            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->private_event->_prepare_exchange_init_data($coin_id, $ua),
            ];
            $this->send_safe($recv, json_encode($data_send));
        } else if ($event == 'exchange-init-guest') {

            $market = $rData['market'];
            $coin_id = $this->CI->WsServer_model->get_coin_id_from_symbol($market);

            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->public_event->_prepare_exchange_init_data($coin_id),
            ];
            $this->send_safe($recv, json_encode($data_send));
        } else if ($event == 'market-init') {

            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->public_event->_prepare_market_init_data(),
            ];
            $this->send_safe($recv, json_encode($data_send));
        } else if ($event == 'crypto-rates') {

            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->public_event->get_24_hour_summary(),
            ];
            $this->send_safe($recv, json_encode($data_send));
        } else if ($event == 'fetch-crypto-rates') {

            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->public_event->get_crypto_rates(),
            ];
            $this->send_safe($recv, json_encode($data_send));
        } else if ($event == 'api-setting-init') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->WsServer_model->get_api_keys($user_id)
            ];

            $this->send_safe($recv, json_encode($arr_return));
        } else if ($event == 'api-setting-create') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $api_name = trim($rData['api_name']);
            $is_ga_required = $rData['is_ga_required'];
            $ga_token = $rData['ga_token'];

            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->WsServer_model->create_api_key($user_id, $api_name, $ga_token)
            ];
            $this->send_safe($recv, json_encode($arr_return));
        } else if ($event == 'api-setting-update') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $api_id = $rData['id'];
            $ip_addr = $rData['ip_address'];
            $ga_token = $rData['ga_token'];

            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->WsServer_model->update_api_key($user_id, $api_id, $ga_token, $ip_addr, $rData),
            ];

            $this->send_safe($recv, json_encode($arr_return));
        } else if ($event == 'api-setting-delete') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $api_id = $rData['api_id'];
            $ip_addr = $rData['ip_address'];
            $ga_token = $rData['ga_token'];

            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->WsServer_model->delete_api_key($user_id, $api_id, $ga_token, $ip_addr)
            ];
            $this->send_safe($recv, json_encode($arr_return));
        } else if ($event == 'depthupdate') {

            $this->log->debug("Depth Update......");

            if ($this->_is_external_channel($channel)) {
                // Check is external channel 

                $channelExp = explode('-', $channel);
                if (!empty($channelExp)) {
                    // Check mendatory fields 

                    // $this->log->debug($rData);

                    $coinSymbol = $channelExp[2];
                    $coinSymbolExp = explode('_',  $coinSymbol);
                    $this->log->debug("Coin Symbol : " . $coinSymbol);

                    if (!empty($coinSymbolExp)) {
                        // Check if symbol correctly given
                        $exchange = $channelExp[3];
                        if ($exchange) {
                            // Check if Exchange field is available
                            $exchange = strtoupper($exchange);
                            $this->log->debug("Exchange : " . $exchange);
                            if ($exchange == 'BINANCE') {
                                $allChannels = $this->external_event->_event_binance_orderbook_update($coinSymbolExp[0], $coinSymbolExp[1], $rData);

                                $this->public_event->_push_event_to_channels($allChannels);

                                // foreach ($allChannels as $channelName => $oneChannel) {
                                //     foreach ($oneChannel as $oneEvent) {
                                //         $data_send = [
                                //             'event' => $oneEvent['event'],
                                //             'channel' => $channelName,
                                //             'data' => $oneEvent['data'],
                                //         ];
                                //     }
                                // }

                                // $this->public_event->_event_binance_orderbook_update($coinSymbolExp[0], $coinSymbolExp[1], $rData);
                            }
                        }
                    }
                } else {
                    $this->log->debug("It is not external Channel......");
                }
            } else {
                $this->log->debug("It is not external Channel......");
            }
        } else if ($event == 'orderUpdate') {
            $this->log->debug("Order Updated......");

            //channel : external-order-update-binance
            if ($this->_is_external_channel($channel)) {
                // Check is external channel 

                $channelExp = explode('-', $channel);
                if (!empty($channelExp)) {
                    // Check mendatory fields 

                    // $this->log->debug($rData);

                    // Check if symbol correctly given
                    $exchange = $channelExp[3];
                    if ($exchange) {
                        // Check if Exchange field is available
                        $exchange = strtoupper($exchange);
                        $this->log->debug("Exchange : " . $exchange);
                        if ($exchange == 'BINANCE') {
                            $this->log->debug("Binance Order Detail : " . $rData);

                            $allChannels = $this->external_event->_event_binance_order_update($rData);

                            $this->public_event->_push_event_to_channels($allChannels);

                            // foreach ($allChannels as $channelName => $oneChannel) {
                            //     foreach ($oneChannel as $oneEvent) {
                            //         $data_send = [
                            //             'event' => $oneEvent['event'],
                            //             'channel' => $channelName,
                            //             'data' => $oneEvent['data'],
                            //         ];
                            //     }
                            // }

                            // $this->public_event->_event_binance_orderbook_update($coinSymbolExp[0], $coinSymbolExp[1], $rData);
                        }
                    }
                } else {
                    $this->log->debug("It is not external Channel......");
                }
            } else {
                $this->log->debug("It is not external Channel......");
            }
        }
    }
}
