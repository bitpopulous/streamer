<?php

namespace PopulousWSS;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use WSSC\Components\ClientConfig;
use WSSC\Components\ServerConfig;
use WSSC\WebSocketClient;
use PopulousWSS\WebSocketServer;
use PopulousWSS\ServerHandler;

/**
 * Create by Populous World Ltd
 *
 * @property ServerConfig config
 * @property WebSocket handler
 */
class WsServer /*extends WssMain implements WebSocketServerContract */
{
    private $ip;
    private $port;
    private $allowed;
    private $log;

    /**
     * WsServer constructor.
     */
    public function __construct()
    {
        $this->ip = getenv('WEBSOCKET_IP');
        $this->port = getenv('WEBSOCKET_PORT');
        $this->allowed = getenv('WEBSOCKET_ORIGIN_ALLOWED');

        $this->log = new Logger('ServerSocket');
        $this->log->pushHandler(new StreamHandler(APPPATH . 'socket_log/socket.log'));
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
