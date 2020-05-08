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
            'common_model',
            'website/web_model',
            'backend/coinpair_model',
            'backend/user/balance_model',
        ]);
    }
    
    protected function _read_fees_balance_env()
    {
        $this->fees_balance_of = getenv('FEES_BALANCE_OF');
        $this->fees_balance_above = getenv('FEES_BALANCE_ABOVE');
        $this->fees_balance_discount = getenv('FEES_BALANCE_DISCOUNT');
    }

    protected function _credit_balance($user_id, $currency_id, $amount)
    {
        $this->CI->db->set('balance', 'balance + ' . $amount, false)->where('user_id', $user_id)->where('currency_id', $currency_id)->update('dbt_balance');
        return $this->CI->db->select('*')->from('dbt_balance')->where('user_id', $user_id)->where('currency_id', $currency_id)->get()->row();
    }

    protected function _debit_balance($user_id, $currency_id, $amount)
    {
        $this->CI->db->set('balance', 'balance - ' . $amount, false)->where('user_id', $user_id)->where('currency_id', $currency_id)->update('dbt_balance');
        return $this->CI->db->select('*')->from('dbt_balance')->where('user_id', $user_id)->where('currency_id', $currency_id)->get()->row();
    }

    protected function _debit_hold_balance($user_id, $currency_id, $amount)
    {
        $this->CI->db->set('balance_on_hold', 'balance_on_hold - ' . $amount, false)->where('user_id', $user_id)->where('currency_id', $currency_id)->update('dbt_balance');
        return $this->CI->db->select('*')->from('dbt_balance')->where('user_id', $user_id)->where('currency_id', $currency_id)->get()->row();
    }

    protected function _buyer_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {
        // P_DN
        $this->_debit_hold_balance($user_id, $secondary_coin_id, $secondary_amount);
        // S_UP
        $this->_credit_balance($user_id, $primary_coin_id, $primary_amount);
    }

    protected function _seller_trade_balance_update($user_id, $primary_coin_id, $secondary_coin_id, $primary_amount, $secondary_amount)
    {
        // P_DN
        $this->_debit_hold_balance($user_id, $primary_coin_id, $primary_amount);
        // S_UP
        $this->_credit_balance($user_id, $secondary_coin_id, $secondary_amount);
    }

    protected function _credit_hold_balance_from_balance($user_id, $currency_id, $amount)
    {
        $this->CI->db->set('balance', 'balance - ' . $amount, false)->set('balance_on_hold', 'balance_on_hold + ' . $amount, false)->where('user_id', $user_id)->where('currency_id', $currency_id)->update('dbt_balance');
        return $this->CI->db->select('*')->from('dbt_balance')->where('user_id', $user_id)->where('currency_id', $currency_id)->get()->row();
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
        return $this->CI->common_model->convertToSqlDecimals($number);
    }

    protected function _safe_math_condition_check($q)
    {
        return $this->CI->common_model->doSqlConditionCheck($q);
    }

    protected function _safe_math($q)
    {
        return $this->CI->common_model->doDecimalMath($q);
    }
    
    protected function _validate_primary_value_decimals($number, $decimals)
    {
        $primary_max = $this->CI->common_model->doMaximumNumberForDecimal($decimals);
        $primary_min = $this->CI->common_model->doMinimumNumberForDecimal($decimals);
        return $this->CI->common_model->doSqlConditionCheck("( $number >= $primary_min AND $number <= $primary_max   )");
    }

    protected function _validate_secondary_value_decimals($number, $decimals)
    {
        $secondary_max = $this->CI->common_model->doMaximumNumberForDecimal($decimals);
        $secondary_min = $this->CI->common_model->doMinimumNumberForDecimal($decimals);
        return $this->CI->common_model->doSqlConditionCheck("( $number >= $secondary_min AND $number <= $secondary_max   )");
    }

    protected function _update_coin_history($coin_id, $qty, $price)
    {
        $open_date = date('Y-m-d H:i:s');

        $where = "(success_time >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND coinpair_id = '" . $coin_id . "')";
        $m1_bid_high_price = $this->CI->db->select_max('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();
        $m1_bid_low_price = $this->CI->db->select_min('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        $where = "(success_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND coinpair_id = '" . $coin_id . "')";
        $m15_bid_high_price = $this->CI->db->select_max('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();
        $m15_bid_low_price = $this->CI->db->select_min('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        $where = "(success_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND coinpair_id = '" . $coin_id . "')";
        $m30_bid_high_price = $this->CI->db->select_max('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();
        $m30_bid_low_price = $this->CI->db->select_min('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        $where = "(success_time >= DATE_SUB(NOW(), INTERVAL 45 MINUTE) AND coinpair_id = '" . $coin_id . "')";
        $m45_bid_high_price = $this->CI->db->select_max('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();
        $m45_bid_low_price = $this->CI->db->select_min('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        $where = "(success_time >= DATE_SUB(NOW(), INTERVAL 1 hour) AND coinpair_id = '" . $coin_id . "')";
        $where01 = "(bid_type='BUY' AND coinpair_id = '" . $coin_id . "')";
        $where1 = "coinpair_id = '" . $coin_id . "'";
        $where11 = "success_time >= DATE_SUB(NOW(), INTERVAL 1 hour) AND bid_type='BUY' AND coinpair_id = '" . $coin_id . "'";
        $where2 = "(success_time >= DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 hour), INTERVAL 1 hour)) AND (success_time <= DATE_SUB(NOW(), INTERVAL 1 hour) AND coinpair_id = '" . $coin_id . "')";

        $h1_last_price_avg = $this->CI->db->select_avg('bid_price')->from('dbt_biding_log')->where($where11)->order_by('success_time', 'desc')->get()->row();
        $pre1h_last_price = $this->CI->db->select('bid_price')->from('dbt_biding_log')->where($where2)->order_by('success_time', 'desc')->get()->row();
        $pre1h_last_price_avg = $this->CI->db->select_avg('bid_price')->from('dbt_biding_log')->where($where2)->order_by('success_time', 'desc')->get()->row();
        $total_coin_supply = $this->CI->db->select_sum('complete_qty')->from('dbt_biding_log')->where($where01)->order_by('success_time', 'desc')->get()->row();
        $h1_coin_supply = $this->CI->db->select_sum('complete_qty')->from('dbt_biding_log')->where($where01)->order_by('success_time', 'desc')->get()->row();
        $h1_bid_high_price = $this->CI->db->select_max('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        $h1_bid_low_price = $this->CI->db->select_min('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        $where = "(success_time >= DATE_SUB(NOW(), INTERVAL 24 hour) AND coinpair_id = '" . $coin_id . "')";
        $where01 = "(bid_type='BUY' AND coinpair_id = '" . $coin_id . "')";
        $where1 = "coinpair_id = '" . $coin_id . "'";
        $where11 = "success_time >= DATE_SUB(NOW(), INTERVAL 24 hour) AND bid_type='BUY' AND coinpair_id = '" . $coin_id . "'";
        $where2 = "(success_time >= DATE_SUB(DATE_SUB(NOW(), INTERVAL 24 hour), INTERVAL 24 hour)) AND (success_time <= DATE_SUB(NOW(), INTERVAL 24 hour) AND coinpair_id = '" . $coin_id . "')";

        $h24_last_price_avg = $this->CI->db->select_avg('bid_price')->from('dbt_biding_log')->where($where11)->order_by('success_time', 'desc')->get()->row();
        $pre24h_last_price = $this->CI->db->select('bid_price')->from('dbt_biding_log')->where($where2)->order_by('success_time', 'desc')->get()->row();
        $pre24h_last_price_avg = $this->CI->db->select_avg('bid_price')->from('dbt_biding_log')->where($where2)->order_by('success_time', 'desc')->get()->row();
        $total_coin_supply = $this->CI->db->select_sum('complete_qty')->from('dbt_biding_log')->where($where01)->order_by('success_time', 'desc')->get()->row();
        $h24_coin_supply = $this->CI->db->select_sum('complete_qty')->from('dbt_biding_log')->where($where01)->order_by('success_time', 'desc')->get()->row();
        $h24_bid_high_price = $this->CI->db->select_max('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();
        $h24_bid_low_price = $this->CI->db->select_min('bid_price')->from('dbt_biding_log')->where($where)->order_by('success_time', 'desc')->get()->row();

        if ($h1_bid_high_price->bid_price == '') {
            $high1 = $price;
        } else {
            if ($h1_bid_high_price->bid_price < $price) {
                $high1 = $price;
            } else {
                $high1 = $h1_bid_high_price->bid_price;
            }
        }

        if ($h1_bid_low_price->bid_price == '') {
            $low1 = $price;
        } else {
            if ($h1_bid_low_price->bid_price > $price) {
                $low1 = $price;
            } else {
                $low1 = $h1_bid_low_price->bid_price;
            }
        }

        //Price change value in up/down
        $last_price_query = $this->CI->db->select('*')->from('dbt_coinhistory')->where('coinpair_id', $coin_id)->order_by('date', 'desc')->get()->row();

        if ($price < @$last_price_query->last_price) {
            $price_change_1h = -($high1 - $low1);
        } else {
            $price_change_1h = $high1 - $low1;
        }

        if ($h24_bid_high_price->bid_price == '') {
            $high24 = $price;
        } else {
            if ($h24_bid_high_price->bid_price < $price) {
                $high24 = $price;
            } else {
                $high24 = $h24_bid_high_price->bid_price;
            }
        }

        if ($h24_bid_low_price->bid_price == '') {
            $low24 = $price;
        } else {
            if ($h24_bid_low_price->bid_price > $price) {
                $low24 = $price;
            } else {
                $low24 = $h24_bid_low_price->bid_price;
            }
        }

        if ($price < @$last_price_query->last_price) {
            $price_change_24h = -($high24 - $low24);
        } else {
            $price_change_24h = $high24 - $low24;
        }

        $w = "coinpair_id = '" . $coin_id . "'";
        $lastPrice = $this->CI->db->select('close')->from('dbt_coinhistory')->where($w)->order_by('date', 'desc')->get()->row();
        $lastPrice = (@$lastPrice->close == '') ? $price : $lastPrice->close;

        $coinhistory = array(
            'coinpair_id' => $coin_id,
            'last_price' => $price,
            'total_coin_supply' => @$qty+@$total_coin_supply->complete_qty,

            'price_high_1min' => ($m1_bid_high_price->bid_price == '') ? $price : (($m1_bid_high_price->bid_price < $price) ? $price : $m1_bid_high_price->bid_price),
            'price_low_1min' => ($m1_bid_low_price->bid_price == '') ? $price : (($m1_bid_low_price->bid_price > $price) ? $price : $m1_bid_low_price->bid_price),

            'price_high_15min' => ($m15_bid_high_price->bid_price == '') ? $price : (($m15_bid_high_price->bid_price < $price) ? $price : $m15_bid_high_price->bid_price),
            'price_low_15min' => ($m15_bid_low_price->bid_price == '') ? $price : (($m15_bid_low_price->bid_price > $price) ? $price : $m15_bid_low_price->bid_price),

            'price_high_30min' => ($m30_bid_high_price->bid_price == '') ? $price : (($m30_bid_high_price->bid_price < $price) ? $price : $m30_bid_high_price->bid_price),
            'price_low_30min' => ($m30_bid_low_price->bid_price == '') ? $price : (($m30_bid_low_price->bid_price > $price) ? $price : $m30_bid_low_price->bid_price),

            'price_high_45min' => ($m45_bid_high_price->bid_price == '') ? $price : (($m45_bid_high_price->bid_price < $price) ? $price : $m45_bid_high_price->bid_price),
            'price_low_45min' => ($m45_bid_low_price->bid_price == '') ? $price : (($m45_bid_low_price->bid_price > $price) ? $price : $m45_bid_low_price->bid_price),

            'price_high_1h' => $high1,
            'price_low_1h' => $low1,

            'price_change_1h' => ($price_change_1h == '') ? 0 : $price_change_1h,
            'volume_1h' => ($h1_coin_supply->complete_qty == '') ? 0 : $h1_coin_supply->complete_qty,

            'price_high_24h' => ($h24_bid_high_price->bid_price == '') ? $price : (($h24_bid_high_price->bid_price < $price) ? $price : $h24_bid_high_price->bid_price),
            'price_low_24h' => ($h24_bid_low_price->bid_price == '') ? $price : (($h24_bid_low_price->bid_price > $price) ? $price : $h24_bid_low_price->bid_price),
            'price_change_24h' => ($price_change_24h == '') ? 0 : $price_change_24h,
            'volume_24h' => ($h24_coin_supply->complete_qty == '') ? 0 : $h24_coin_supply->complete_qty,

            'open' => $lastPrice,
            'close' => $price,
            'volumefrom' => @$qty+@$total_coin_supply->complete_qty,
            'volumeto' => ($h24_coin_supply->complete_qty == '') ? 0 : $h24_coin_supply->complete_qty,
            'date' => $open_date,
        );

        return $this->CI->db->insert('dbt_coinhistory', $coinhistory);

    }
    
}