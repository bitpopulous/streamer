<?php

namespace PopulousWSS\Trade;

use PopulousWSS\Common\Auth;

class Trade extends Auth
{
    protected $CI;

    protected $fees_balance_of;
    protected $fees_balance_above;
    protected $fees_balance_discount;

    protected $wss_server;
    protected $user_id;

    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model([
            'WsServer_model',
        ]);
    }
    
    protected function _read_fees_balance_env()
    {
        $this->fees_balance_of = getenv('FEES_BALANCE_OF');
        $this->fees_balance_above = getenv('FEES_BALANCE_ABOVE');
        $this->fees_balance_discount = getenv('FEES_BALANCE_DISCOUNT');
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
        return $this->CI->WsServer_model->condition_check("( $number >= $primary_min AND $number <= $primary_max   )");
    }

    protected function _validate_secondary_value_decimals($number, $decimals)
    {
        $secondary_max = $this->CI->WsServer_model->max_value($decimals);
        $secondary_min = $this->CI->WsServer_model->min_value($decimals);
        return $this->CI->WsServer_model->condition_check("( $number >= $secondary_min AND $number <= $secondary_max   )");
    }
}