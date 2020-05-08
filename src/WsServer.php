<?php

namespace PopulousWSS;

use WSSC\Components\ClientConfig;
use WSSC\Components\ServerConfig;
use WSSC\WebSocketClient;
use WSSC\WebSocketServer;
use PopulousWSS\ServerHandler;

/**
 * Create by PopulusWorld Ltd
 *
 * @property ServerConfig config
 * @property WebSocket handler
 */
class WsServer /*extends WssMain implements WebSocketServerContract */
{
    private $ip;
    private $port;
    private $allowed;

    /**
     * WsServer constructor.
     */
    public function __construct()
    {
        $this->ip = getenv('WEBSOCKET_IP');
        $this->port = getenv('WEBSOCKET_PORT');
        $this->allowed = getenv('WEBSOCKET_ORIGIN_ALLOWED');
    }

    /**
     * Runs WSS Server
     *
     * @throws WebSocketException
     * @throws ConnectionException
     */
    public function runWsServer()
    {
        ini_set('default_socket_timeout', 1000000000);
        $config = new ServerConfig();
        $config->setHost($this->ip);
        $config->setPort($this->port);

        // set origin if exist;
        if ($this->allowed != null) {
            $allowedArr[] = explode(',', $this->allowed);
            foreach ($allowedArr as &$one) {
                $one = trim($one);
            }
            $config->setOrigins( $one );
        }

        $config->setClientsPerFork(2500);
        $config->setStreamSelectTimeout(2 * 3600);
        $wsServer = new WebSocketServer(new ServerHandler(), $config);
        echo "Server is listening on port $this->port.\n";
        $wsServer->run();
    }
}
