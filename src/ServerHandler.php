<?php
namespace PopulousWSS;

use PopulousWSS\Common\PopulousWSSConstants;
use PopulousWSS\Events\PrivateEvent;
use PopulousWSS\Events\PublicEvent;
use PopulousWSS\ServerBaseHandler;
use PopulousWSS\Trade\Buy;
use PopulousWSS\Trade\Sell;
use WSSC\Contracts\ConnectionContract;

class ServerHandler extends ServerBaseHandler
{
    protected $public_event;
    protected $private_event;

    protected $buy;
    protected $sell;

    public function __construct()
    {
        parent::__construct();
        $this->public_event = new PublicEvent($this);
        $this->private_event = new PrivateEvent($this);
        $this->buy = new Buy($this);
        $this->sell = new Sell($this);
    }
    
    public function _event_handler(ConnectionContract $recv, string $channel, string $event, array $data)
    {
        if ($this->_is_subscribe_required($event)) {

            if ($this->_is_private_channel($channel)) {
                return $this->private_event->_subscribe($recv, $channel, $data['auth']);
            } else {
                return $this->public_event->_subscribe($recv, $channel);
            }

        } else {

            $_ip = explode(':', $recv->getPeerName())[0];
            $data['ip_address'] = $_ip;
            $this->_reply_msg($recv, $event, $channel, $data);
        }
    }
    
    public function _event_push(int $event_type, array $data) {

        switch($event_type) {
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

            default:
                
            break;
        }
        
    }

    private function _reply_msg(ConnectionContract $recv, string $event, string $channel, array $rData)
    {
        if ($event == 'orderbook-init') {

            $market = $rData['market'];
            $pair_id = $this->_get_pair_id_from_symbol($market);

            $data = $this->CI->biding_model->getBuySellOrders($pair_id, 40);
            $buy_orders = $this->CI->convertdata->convertDataArray(
                $data['buy_orders'],
                ['bid_price:1', 'total_qty:0'],
                $pair_id
            );
            $sell_orders = $this->CI->convertdata->convertDataArray(
                $data['sell_orders'],
                ['bid_price:1', 'total_qty:0'],
                $pair_id
            );
            
            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => [
                    'buy_orders' => $buy_orders,
                    'sell_orders' => $sell_orders,
                ],
            ];
            $recv->send(json_encode($data_send));
            
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

                $recv->send(json_encode($data_send));

            } else if ($trade_type == 'market') {

                $amount = $rData['amount'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->buy->_market($coin_id, $amount, $auth),
                ];

                $recv->send(json_encode($data_send));
                
            } else if ($trade_type == 'stop_limit') {

                $amount = $rData['amount'];
                $limit = $rData['limit'];
                $stop = $rData['stop'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->buy->_stop_limit($coin_id, $amount, $stop, $limit, $auth),
                ];

                $recv->send(json_encode($data_send));

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

                $recv->send(json_encode($data_send));

            } else if ($trade_type == 'market') {

                $amount = $rData['amount'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->sell->_market($coin_id, $amount, $auth),
                ];

                $recv->send(json_encode($data_send));
                
            } else if ($trade_type == 'stop_limit') {

                $amount = $rData['amount'];
                $limit = $rData['limit'];
                $stop = $rData['stop'];

                $data_send = [
                    'event' => $event,
                    'channel' => $channel,
                    'data' => $this->sell->_stop_limit($coin_id, $amount, $stop, $limit, $auth),
                ];

                $recv->send(json_encode($data_send));

            }
            return;

        } else if ($event == 'exchange-cancel-order') {

            $order_id = $rData['order_id'];
            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $ip_address = $rData['ip_address'];
    
            $data = [
                'isSuccess' => true,
                'message' => '',
            ];
    
            $orderdata = $this->CI->web_model->single($order_id);
    
            if ($user_id != $orderdata->user_id) {
                $data['isSuccess'] = false;
                $data['message'] = 'You are not allow to cancel this order.';
                
            } else {
    
                $canceltrade = array(
                    'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                );
    
                $is_status_changed_to_cancel = $this->CI->db->where('id', $order_id)->update("dbt_biding", $canceltrade);
    
                if ($is_status_changed_to_cancel == false) {
                    $data['isSuccess'] = false;
                    $data['message'] = 'Could not cancelled the order';
                } else {
                    $currency_symbol = '';
                    $currency_id = '';
                    $coninpairId = $orderdata->coinpair_id;
        
                    $refund_amount = '';
                    if ($orderdata->bid_type == 'SELL') {
                        $currency_id = $this->CI->coinpair_model->getPrimaryCoinId($coninpairId);
                        $refund_amount = $orderdata->bid_qty_available;
                    } else {
                        $currency_id = $this->CI->coinpair_model->getSecondaryCoinId($orderdata->coinpair_id);
                        $refund_amount = $this->CI->common_model->doSqlArithMetic(" ($orderdata->bid_qty_available * $orderdata->bid_price) ");
                    }
        
                    $balance = $this->CI->web_model->checkBalanceById($currency_id, $orderdata->user_id);
                    $new_balance = $this->CI->common_model->doSqlArithMetic(" ( $balance->balance + $refund_amount )");
        
                    //User Financial Log
                    $tradecanceldata = array(
                        'user_id' => $orderdata->user_id,
                        'balance_id' => @$balance->id,
                        'currency_id' => $currency_id,
                        'transaction_type' => 'TRADE_CANCEL',
                        'transaction_amount' => $refund_amount,
                        'transaction_fees' => 0,
                        'ip' => $ip_address,
                        'date' => date('Y-m-d H:i:s'),
                    );
        
                    $this->CI->payment_model->balancelog($tradecanceldata);
        
                    $this->CI->db->set('balance', $new_balance)->where('user_id', $orderdata->user_id)->where('currency_id', $currency_id)->update("dbt_balance");
        
                    // Release hold balance
                    $this->CI->web_model->holdBalanceDebitById($orderdata->user_id, $currency_id, $refund_amount);
        
                    $traderlog = array(
                        'bid_id' => $orderdata->id,
                        'bid_type' => $orderdata->bid_type,
                        'complete_qty' => $orderdata->bid_qty_available,
                        'bid_price' => $orderdata->bid_price,
                        'complete_amount' => $refund_amount,
                        'user_id' => $orderdata->user_id,
                        'coinpair_id' => $orderdata->coinpair_id,
                        'success_time' => date('Y-m-d H:i:s'),
                        'fees_amount' => $orderdata->fees_amount,
                        'available_amount' => $orderdata->amount_available,
                        'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
                    );
        
                    $this->CI->db->insert('dbt_biding_log', $traderlog);
        
                    $this->_event_push(
                        PopulousWSSConstants::EVENT_ORDER_UPDATED,
                        [
                            'order_id' => $order_id,
                            'user_id' => $user_id,
                        ]
                    );
                    $this->_event_push(
                        PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                        [
                            'coin_id' => $orderdata->coinpair_id,
                        ]
                    );
                    
                    $data['isSuccess'] = true;
                    $data['message'] = 'Request cancelled successfully.';
                }
            }

            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $data,
            ];

            $recv->send(json_encode($data_send));

        } else if ($event == 'exchange-init') {

            $market = $rData['market'];
            $coin_id = $this->_get_pair_id_from_symbol($market);
            $ua = $rData['ua'];
            
            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->private_event->_prepare_exchange_init_data($coin_id, $ua),
            ];
            $recv->send(json_encode($data_send));

        } else if ($event == 'exchange-init-guest') {
            
            $market = $rData['market'];
            $coin_id = $this->_get_pair_id_from_symbol($market);
            
            $data_send = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->public_event->_prepare_exchange_init_data($coin_id),
            ];
            $recv->send(json_encode($data_send));
            
        } else if ($event == 'api-setting-init') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->apisocket->getKeys($user_id)
            ];
            $recv->send(json_encode($arr_return));

        } else if ($event == 'api-setting-create') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $api_name = trim($rData['api_name']);
            $is_ga_required = $rData['is_ga_required'];
            $ga_token = $rData['ga_token'];

            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->apisocket->createKey($user_id, $api_name, $ga_token)
            ];
            $recv->send(json_encode($arr_return));

        } else if ($event == 'api-setting-update') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $api_id = $rData['id'];
            $ip_addr = $rData['ip_address'];
            $ga_token = $rData['ga_token'];

            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->apisocket->updateKey($user_id, $api_id, $ga_token, $ip_addr, $rData),
            ];

            $recv->send(json_encode($arr_return));

        } else if ($event == 'api-setting-delete') {

            $user_id = $this->private_event->_get_user_id($rData['ua']);
            $api_id = $rData['api_id'];
            $ip_addr = $rData['ip_address'];
            $ga_token = $rData['ga_token'];

            $arr_return = [
                'event' => $event,
                'channel' => $channel,
                'data' => $this->CI->apisocket->deleteKey($user_id, $api_id, $ga_token, $ip_addr)
            ];
            $recv->send(json_encode($arr_return));
            
        }
    }
}
