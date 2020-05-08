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
