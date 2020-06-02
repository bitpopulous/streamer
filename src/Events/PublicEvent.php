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
        ];

        $this->_push_event_to_channels($channels);
    }

    public function _event_trade_create($log_id)
    {
        $tc_event = $this->_prepare_trade_create($log_id);

        if ($tc_event ) {

            $coin_symbol            = $this->CI->WsServer_model->get_coin_symbol_by_coin_id($tc_event['data']['coinpair_id']);
            $market_channel         = $this->CI->WsServer_model->get_market_global_channel($coin_symbol);

            $channels = [];
            $channels[$market_channel] = [];
            $channels[$market_channel][] = $tc_event;


            $this->_push_event_to_channels($channels);
        }
    }

    public function _event_24h_summary_update(){

        $summary = $this->_prepare_24_hour_summary();
        
        if( $summary ){
            
            $market_summary_channel = $this->CI->WsServer_model->get_market_summary_channel();
            $channels = [];
    
            $channels[$market_summary_channel] = [];
            $channels[$market_summary_channel][] = $summary;

            $this->_push_event_to_channels($channels);
        }
    }

    private function _prepare_order_book($coin_id)
    {
        return [
            'event' => 'orderbook',
            'data' => $this->CI->WsServer_model->get_orders($coin_id)
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

    public function get_24_hour_summary(){
        return $this->CI->WsServer_model->get_all_active_coinpairs_24h_summary();
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

    public function _prepare_exchange_init_data($coin_id)
    {
        $orders = $this->CI->WsServer_model->get_orders($coin_id, 40);
        
        return [
            'market_pairs' => $this->CI->WsServer_model->get_market_pairs(),
            'trade_history' => $this->CI->WsServer_model->get_trades_history($coin_id, 60),
            'coin_history' => $this->CI->WsServer_model->get_coins_history($coin_id, 20),
            'buy_orders' => $orders['buy_orders'],
            'sell_orders' => $orders['sell_orders'],
        ];
    }
}
