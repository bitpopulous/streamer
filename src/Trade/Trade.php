<?php

namespace PopulousWSS\Trade;

use PopulousWSS\common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use PopulousWSS\Common\Auth;

class Trade
{
    use Auth;
    
    protected $CI;

    protected $maker_discount = 0;
    protected $taker_discount = 0;

    protected $wss_server;
    protected $user_id;

    public function __construct( ServerHandler $server )
    {
        $this->CI = &get_instance();
        $this->wss_server = $server;

        $this->CI->load->model([
            'WsServer_model',
        ]);
    }
    

    protected function _buyer_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {
        // P_DN
        $this->CI->WsServer_model->get_debit_hold_balance($user_id, $secondary_coin_id, $secondary_amount);
        // S_UP
        $this->CI->WsServer_model->get_credit_balance($user_id, $primary_coin_id, $primary_amount);
    }

    protected function _seller_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {
        // P_DN
        $this->CI->WsServer_model->get_debit_hold_balance($user_id, $primary_coin_id, $primary_amount);
        // S_UP
        $this->CI->WsServer_model->get_credit_balance($user_id, $secondary_coin_id, $secondary_amount);
    }

    protected function _format_number($number, $decimals)
    {
        if (!$number) {
            $number = 0;
        }

        return number_format((float) round($number, $decimals, PHP_ROUND_HALF_DOWN), $decimals, '.', '');
    }

    protected function _convert_to_decimals($number)
    {
        return $this->CI->WsServer_model->convert_long_decimals($number);
    }

    protected function _safe_math_condition_check($q)
    {
        return $this->CI->WsServer_model->condition_check($q);
    }

    protected function _safe_math($q)
    {
        return $this->CI->WsServer_model->calculation_math($q);
    }
    
    protected function _validate_primary_value_decimals($number, $decimals)
    {
        $primary_max = $this->CI->WsServer_model->max_value($decimals);
        $primary_min = $this->CI->WsServer_model->min_value($decimals);
        return $this->_validate_decimals( $number, $decimals ) && $this->CI->WsServer_model->condition_check("( $number >= $primary_min AND $number <= $primary_max   )");
    }

    protected function _validate_secondary_value_decimals($number, $decimals)
    {
        $secondary_max = $this->CI->WsServer_model->max_value($decimals);
        $secondary_min = $this->CI->WsServer_model->min_value($decimals);

        return $this->_validate_decimals( $number, $decimals ) && $this->CI->WsServer_model->condition_check("( $number >= $secondary_min AND $number <= $secondary_max   )");
    }

    protected function _validate_decimals( $number, $decimals ){

        $number = (string) $number;

        $l = (int) strlen(substr(strrchr($number, "."), 1));        
        if( $l <= $decimals ) return TRUE;
        
        return FALSE;
    }


    public function cancel_order($order_id, $auth, $rData) {
        
        $user_id = $this->_get_user_id($auth);
        $ip_address = $rData['ip_address'];

        $data = [
            'isSuccess' => true,
            'message' => '',
        ];

        $orderdata = $this->CI->WsServer_model->get_order($order_id);

        if ($user_id != $orderdata->user_id) {
            $data['isSuccess'] = false;
            $data['message'] = 'You are not allow to cancel this order.';
           
        } else {

            $canceltrade = array(
                'status' => PopulousWSSConstants::BID_CANCELLED_STATUS,
            );

            $is_updated = $this->CI->WsServer_model->update_order($order_id, $canceltrade);

            if ($is_updated == false) {
                $data['isSuccess'] = false;
                $data['message'] = 'Could not cancelled the order';
            } else {
                $currency_symbol = '';
                $currency_id = '';
                $coinpair_id = $orderdata->coinpair_id;
    
                $refund_amount = '';
                if ($orderdata->bid_type == 'SELL') {
                    $currency_id = $this->CI->WsServer_model->get_primary_id_by_coin_id($coinpair_id);
                    $refund_amount = $orderdata->bid_qty_available;
                } else {
                    $currency_id = $this->CI->WsServer_model->get_secondary_id_by_coin_id($coinpair_id);
                    $refund_amount = $this->_safe_math(" ($orderdata->bid_qty_available * $orderdata->bid_price) ");
                }
    
                $balance = $this->CI->WsServer_model->get_user_balance_by_coin_id($currency_id, $orderdata->user_id);
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
    
                $this->CI->WsServer_model->insert_balance_log($tradecanceldata);
                $this->CI->WsServer_model->get_credit_balance($orderdata->user_id, $currency_id, $refund_amount);
                // Release hold balance
                $this->CI->WsServer_model->get_debit_hold_balance($orderdata->user_id, $currency_id, $refund_amount);
                
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
    
                $this->CI->WsServer_model->insert_order_log($traderlog);
    
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_ORDER_UPDATED,
                    [
                        'order_id' => $order_id,
                        'user_id' => $user_id,
                    ]
                );
                $this->wss_server->_event_push(
                    PopulousWSSConstants::EVENT_COINPAIR_UPDATED,
                    [
                        'coin_id' => $orderdata->coinpair_id,
                    ]
                );
                
                $data['isSuccess'] = true;
                $data['message'] = 'Request cancelled successfully.';
            }
        }
        return $data;
    }
}