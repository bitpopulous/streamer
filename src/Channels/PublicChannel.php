<?php

namespace PopulousWSS\Channels;

use PopulousWSS\Common\PopulousWSSConstants;
use WSSC\Contracts\ConnectionContract;

class PublicChannel
{
    public $channels;
    protected $CI;

    public function __construct()
    {
        $this->channels = [];

        $this->CI = &get_instance();
        $this->CI->load->model([
            'WsServer_model',
            'backend/privatechannels_model',
            'backend/biding_model',	
            'backend/bidinglog_model',	
            'backend/cryptocoin_model',	
            'backend/channels_model',	
            'website/coinhistory_model',	
            'website/web_model',	
            'common_model',
        ]);
    }

    /**
     * @return bool
     */
    public function _subscribe(ConnectionContract $recv, string $channel): bool
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }
        $socketId = $recv->getUniqueSocketId();
        $this->channels[$channel][] = $socketId;

        return $this->_subscribe_succeed($recv, $channel);
    }

    public function _subscribe_succeed(ConnectionContract $recv, string $channel): bool
    {
        $d = [];
        $d['event'] = PopulousWSSConstants::SUBSCRIBE_SUCCESS;
        $d['channel'] = $channel;
        $d['data'] = [];
        $recv->send(json_encode($d));
        return true;
    }
}
