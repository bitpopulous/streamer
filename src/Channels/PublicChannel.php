<?php

namespace PopulousWSS\Channels;

use PopulousWSS\Common\PopulousWSSConstants;
use PopulousWSS\ServerHandler;
use WSSC\Contracts\ConnectionContract;

use PopulousWSS\Exchanges\Binance;

class PublicChannel
{
    public $channels;
    protected $CI;
    public $wss_server;
    public $exchanges = [];

    public function __construct(ServerHandler $server)
    {
        $this->wss_server = $server;

        $this->channels = [];

        $this->CI = &get_instance();
        $this->CI->load->model([
            'WsServer_model',
        ]);

        $this->CI->load->library("PopDecimalMath", null, 'decimalmaths');
        $this->DM = $this->CI->decimalmaths;

        /**
         * External exchange plugins
         */
        $this->exchanges['BINANCE'] = new Binance();
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

    /**
     * @return bool
     */
    public function _publish_message(string $event, string $channel, string $message): bool
    {
        if (isset($this->channels[$channel])) {

            $subscribers = $this->channels[$channel];
            $clients = $this->wss_server->_get_clients();
            foreach ($subscribers as $s) {
                if (isset($clients[$s])) {
                    // Check subscriber connection is still alive before sending
                    $clients[$s]->send($message);
                }
            }
            return true;
        } else {
            return false;
        }
    }


    public function _push_event_to_channels(array $channels)
    {
        foreach ($channels as $channel => $eventData) {
            foreach ($eventData as $e) {
                $this->_send_to_subscribers($e['event'], $channel, (array) $e['data']);
            }
        }
    }

    public function _send_to_subscribers(string $event, string $channel, array $message): bool
    {
        //prepare data
        $d = [
            'event' => $event,
            'channel' => $channel,
            'data' => $message,
        ];
        $messageTxt = json_encode($d);

        return $this->_publish_message($event, $channel, $messageTxt);
    }
}
