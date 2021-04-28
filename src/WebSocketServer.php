<?php

namespace PopulousWSS;

use Monolog\Handler\StreamHandler;
use PopulousWSS\Common\PopulousLogger;
use PopulousWSS\Components\Connection;
use PopulousWSS\Components\OriginComponent;
use WSSC\Components\ServerConfig;
use WSSC\Components\WssMain;
use WSSC\Contracts\CommonsContract;
use WSSC\Contracts\WebSocket;
use WSSC\Contracts\WebSocketServerContract;
use WSSC\Exceptions\ConnectionException;
use WSSC\Exceptions\WebSocketException;

/**
 * Create by Populous World Ltd
 *
 * @property ServerConfig config
 * @property WebSocket handler
 */
class WebSocketServer extends WssMain implements WebSocketServerContract
{
    protected $config;

    private $clients = [];
    // set any template You need ex.: GET /subscription/messenger/token
    private $pathParams = [];
    private $handshakes = [];
    private $headersUpgrade = [];
    private $totalClients = 0;
    private $maxClients = 1;
    private $handler;
    private $cureentConn;

    // Keep connection resource of External exchanges
    private $externalExchanges = [];

    // for the very 1st time must be true
    private $stepRecursion = true;

    private const MAX_BYTES_READ = 8192;
    private const HEADER_BYTES_READ = 1024;

    private $log;

    private $clientIps = [];
    private $clientIpWithTime = [];
    private $maxClientsReqs = 20;
    private $maxClientsReqsBlockTime = 60;

    /**
     * WebSocketServer constructor.
     *
     * @param WebSocket $handler
     * @param ServerConfig $config
     */
    public function __construct(
        WebSocket $handler,
        ServerConfig $config
    ) {
        ini_set('default_socket_timeout', 5); // this should be >= 5 sec, otherwise there will be broken pipe - tested

        $this->handler = $handler;
        $this->config = $config;
        $this->setIsPcntlLoaded(extension_loaded('pcntl'));
        $this->maxClientsReqs = (getenv('SAME_IP_CONNECT_LIMIT')) ? getenv('SAME_IP_CONNECT_LIMIT') : $this->maxClientsReqs;
        $this->maxClientsReqsBlockTime = (getenv('SAME_IP_CONNECT_LIMIT_TIME')) ? getenv('SAME_IP_CONNECT_LIMIT_TIME') : $this->maxClientsReqsBlockTime;

        $this->log = new PopulousLogger('ServerSocket');
        $this->log->pushHandler(new StreamHandler(APPPATH . 'socket_log/socket.log'));

        $isProduction  = getenv('POPULOUS_LOGGER') == '0';
        if ($isProduction) {
            $this->log->setProduction();
        }
    }

    /**
     * Runs main process - Anscestor with server socket on TCP
     *
     * @throws WebSocketException
     * @throws ConnectionException
     */
    public function run()
    {
        $errno = null;
        $errorMessage = '';

        $server = stream_socket_server(
            "tcp://{$this->config->getHost()}:{$this->config->getPort()}",
            $errno,
            $errorMessage
        );

        if ($server === false) {
            throw new WebSocketException(
                'Could not bind to socket: ' . $errno . ' - ' . $errorMessage . PHP_EOL,
                CommonsContract::SERVER_COULD_NOT_BIND_TO_SOCKET
            );
        }

        @cli_set_process_title($this->config->getProcessName());
        $this->eventLoop($server);
    }

    public function attachExternalExchange(Connection $connection, $name = null)
    {
        if ($name == null) {
            $this->externalExchanges['external_' + count($this->externalExchanges) + 1] = $connection;
        } else {
            $name = strtoupper($name);
            if (!isset($this->externalExchanges[$name])) {
                $this->externalExchanges[$name] = $connection;
            } else {
            }
        }
    }

    /**
     * Recursive event loop that input intu recusion by remainder = 0 - thus when N users,
     * and when forks equals true which prevents it from infinite recursive iterations
     *
     * @param resource $server server connection
     * @param bool $fork flag to fork or run event loop
     * @throws WebSocketException
     * @throws ConnectionException
     */
    private function eventLoop($server, bool $fork = false)
    {
        if ($fork === true && $this->isPcntlLoaded()) {
            $pid = pcntl_fork();

            if ($pid) { // run eventLoop in parent        
                @cli_set_process_title($this->config->getProcessName());
                $this->eventLoop($server);
            }
        } else {
            $this->looping($server);
        }
    }

    /**
     * @param resource $server
     * @throws WebSocketException
     * @throws ConnectionException
     */
    private function looping($server)
    {
        while (true) {
            //prepare readable sockets
            $readSocks = $this->clients;
            $readSocks[] = $server;
            $this->cleanSocketResources($readSocks);

            //start reading and use a large timeout
            if (!stream_select($readSocks, $write, $except, $this->config->getStreamSelectTimeout())) {
                // check any connection timeout or not if it's timeout then close from here as well.
                $this->log->debug("Socket Timeout: " . $this->config->getStreamSelectTimeout());
                $this->clearTimedoutRes($this->clients);
                //                throw new WebSocketException('something went wrong while selecting',
                //                    CommonsContract::SERVER_SELECT_ERROR);
            }

            $this->totalClients = count($this->clients) + 1;

            // maxClients prevents process fork on count down
            if ($this->totalClients > $this->maxClients) {
                $this->maxClients = $this->totalClients;
            }

            $doFork = $this->config->isForking() === true
                && $this->totalClients !== 0 // avoid 0 process creation
                && $this->stepRecursion === true // only once
                && $this->maxClients === $this->totalClients // only if stack grows
                && $this->totalClients % $this->config->getClientsPerFork() === 0; // only when N is there
            if ($doFork) {
                $this->stepRecursion = false;
                $this->eventLoop($server, true);
            }
            $this->lessConnThanProc($this->totalClients, $this->maxClients);

            //new client
            if (in_array($server, $readSocks, false)) {
                $this->acceptNewClient($server, $readSocks);
                if ($this->config->isCheckOrigin() && $this->config->isOriginHeader() === false) {
                    continue;
                }
            }

            //message from existing client
            $this->messagesWorker($readSocks);
        }
    }

    /**
     * @param resource $server
     * @param array $readSocks
     * @throws ConnectionException
     */
    private function acceptNewClient($server, array &$readSocks)
    {
        $newClient = stream_socket_accept($server, 0); // must be 0 to non-block
        if ($newClient) {
            $validRequest = true;

            // important to read from headers here coz later client will change and there will be only msgs on pipe
            $headers = fread($newClient, self::HEADER_BYTES_READ);

            $client_ip = false;
            $xForwardedFor = $this->getXForwardedFor($headers);
            if (!is_bool($xForwardedFor)) {
                $client_ip = current($this->getXForwardedFor($headers)) || null;
            }


            if ($client_ip) {
				$client_ip = (string) $client_ip;
                $this->clientIps[] = $client_ip;
                $this->clientIpWithTime[][$client_ip] = date('Y-m-d H:i:s');

                $requested_ip_list = array_count_values($this->clientIps);
                if ($requested_ip_list[$client_ip] > $this->maxClientsReqs) {
                    $validRequest = false;
                    $this->log->debug("Request from $client_ip more than $this->maxClientsReqs");

                    $alltimesForSpecificIp = array_column($this->clientIpWithTime, $client_ip);
                    $minutes = (strtotime(date('Y-m-d H:i:s')) - strtotime(current($alltimesForSpecificIp))) / 60;
                    $minutes = ($minutes < 0) ? 0 : (int) abs($minutes);

                    $this->log->debug("Client IP: $client_ip, Minutes: $minutes, Max Block Time: $this->maxClientsReqsBlockTime");
                    if ($minutes >= $this->maxClientsReqsBlockTime) {
                        $this->clientIps = array_diff($this->clientIps, [$client_ip]);
                        foreach ($this->clientIpWithTime as $key => $val) {
                            if (count(@$val[$client_ip]) > 0) {
                                unset($this->clientIpWithTime[$key]);
                            }
                        }
                    }
                }
            }

            if ($validRequest) {
                if ($this->config->isCheckOrigin()) {
                    $hasOrigin = (new OriginComponent($this->config, $newClient))->checkOrigin($headers);
                    $this->config->setOriginHeader($hasOrigin);
                    if ($hasOrigin === false) {
                        return;
                    }
                }

                if (empty($this->handler->pathParams[0]) === false) {
                    $this->setPathParams($headers);
                }

                $this->clients[] = $newClient;
                $this->stepRecursion = true; // set on new client - remainder % is always 0

                // trigger OPEN event
                $this->handler->onOpen(new Connection($newClient, $this->clients));
                $this->handshake($newClient, $headers);
            }
        }

        //delete the server socket from the read sockets
        unset($readSocks[array_search($server, $readSocks, false)]);
    }

    /**
     * @param array $readSocks
     * @uses onPing
     * @uses onPong
     * @uses onMessage
     */
    private function messagesWorker(array $readSocks)
    {
        foreach ($readSocks as $kSock => $sock) {
            $data = $this->decode(fread($sock, self::MAX_BYTES_READ));
            if ($data !== null) {
                $dataType = $data['type'];
                $dataPayload = $data['payload'];

                // to manipulate connection through send/close methods via handler, specified in IConnection
                $this->cureentConn = new Connection($sock, $this->clients);
                if (empty($data) || $dataType === self::EVENT_TYPE_CLOSE) { // close event triggered from client - browser tab or close socket event
                    // trigger CLOSE event
                    try {
                        $this->handler->onClose($this->cureentConn);
                    } catch (WebSocketException $e) {
                        $e->printStack();
                    }

                    // to avoid event leaks
                    unset($this->clients[array_search($sock, $this->clients)], $readSocks[$kSock]);
                    continue;
                }

                $isSupportedMethod = empty(self::MAP_EVENT_TYPE_TO_METHODS[$dataType]) === false
                    && method_exists($this->handler, self::MAP_EVENT_TYPE_TO_METHODS[$dataType]);
                if ($isSupportedMethod) {
                    try {
                        // dynamic call: onMessage, onPing, onPong
                        $this->handler->{self::MAP_EVENT_TYPE_TO_METHODS[$dataType]}($this->cureentConn, $dataPayload);
                    } catch (WebSocketException $e) {
                        $e->printStack();
                    }
                }
            }
        }
    }

    /**
     * Handshakes/upgrade and key parse
     *
     * @param resource $client Source client socket to write
     * @param string $headers Headers that client has been sent
     * @return string   socket handshake key (Sec-WebSocket-Key)| false on parse error
     * @throws ConnectionException
     */
    private function handshake($client, string $headers): string
    {
        $match = [];
        preg_match(self::SEC_WEBSOCKET_KEY_PTRN, $headers, $match);
        if (empty($match[1])) {
            return false;
        }

        $key = $match[1];
        $this->handshakes[(int) $client] = $key;

        // sending header according to WebSocket Protocol
        $secWebSocketAccept = base64_encode(sha1(trim($key) . self::HEADER_WEBSOCKET_ACCEPT_HASH, true));
        $this->setHeadersUpgrade($secWebSocketAccept);
        $upgradeHeaders = $this->getHeadersUpgrade();

        fwrite($client, $upgradeHeaders);

        return $key;
    }

    /**
     * Sets an array of headers needed to upgrade server/client connection
     *
     * @param string $secWebSocketAccept base64 encoded Sec-WebSocket-Accept header
     */
    private function setHeadersUpgrade($secWebSocketAccept)
    {
        $this->headersUpgrade = [
            self::HEADERS_UPGRADE_KEY => self::HEADERS_UPGRADE_VALUE,
            self::HEADERS_CONNECTION_KEY => self::HEADERS_CONNECTION_VALUE,
            self::HEADERS_SEC_WEBSOCKET_ACCEPT_KEY => ' ' . $secWebSocketAccept
            // the space before key is really important
        ];
    }

    /**
     * Retreives headers from an array of headers to upgrade server/client connection
     *
     * @return string   Headers to Upgrade communication connection
     * @throws ConnectionException
     */
    private function getHeadersUpgrade(): string
    {
        $handShakeHeaders = self::HEADER_HTTP1_1 . self::HEADERS_EOL;
        if (empty($this->headersUpgrade)) {
            throw new ConnectionException(
                'Headers for upgrade handshake are not set' . PHP_EOL,
                CommonsContract::SERVER_HEADERS_NOT_SET
            );
        }

        foreach ($this->headersUpgrade as $key => $header) {
            $handShakeHeaders .= $key . ':' . $header . self::HEADERS_EOL;
            if ($key === self::HEADERS_SEC_WEBSOCKET_ACCEPT_KEY) { // add additional EOL fo Sec-WebSocket-Accept
                $handShakeHeaders .= self::HEADERS_EOL;
            }
        }

        return $handShakeHeaders;
    }

    /**
     * Parses parameters from GET on web-socket client connection before handshake
     *
     * @param string $headers
     */
    private function setPathParams(string $headers)
    {
        if (empty($this->handler->pathParams) === false) {
            $matches = [];
            preg_match('/GET\s(.*?)\s/', $headers, $matches);
            $left = $matches[1];

            foreach ($this->handler->pathParams as $k => $param) {
                if (empty($this->handler->pathParams[$k + 1]) && strpos($left, '/', 1) === false) {
                    // do not eat last char if there is no / at the end
                    $this->handler->pathParams[$param] = substr($left, strpos($left, '/') + 1);
                } else {
                    // eat both slashes
                    $this->handler->pathParams[$param] = substr(
                        $left,
                        strpos($left, '/') + 1,
                        strpos($left, '/', 1) - 1
                    );
                }

                // clear the declaration of parsed param
                unset($this->handler->pathParams[array_search($param, $this->handler->pathParams, false)]);
                $left = substr($left, strpos($left, '/', 1));
            }
        }
    }

    private function clearTimedoutRes(array &$clients)
    {
        foreach ($clients as $kSock => $sock) {
            $data = $this->decode(fread($sock, self::MAX_BYTES_READ));
            // to manipulate connection through send/close methods via handler, specified in IConnection
            $cureentConn = new Connection($sock, $clients);
            if (empty($data)) { // close event triggered from client - browser tab or close socket event
                // trigger CLOSE event
                try {
                    $this->handler->onClose($cureentConn);
                } catch (WebSocketException $e) {
                    $e->printStack();
                }

                // to avoid event leaks
                unset($this->clients[array_search($sock, $clients)], $clients[$kSock]);
                continue;
            }
        }
    }

    /**
     * Get IP Addresses
     * @param string $headers
     * @return Array
     */
    private function getXForwardedFor(string $headers)
    {
        $re = '/X-Forwarded-For\:\s(.\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}[,]?)+/';
        preg_match($re, $headers, $matches, PREG_OFFSET_CAPTURE, 0);

        // Print the entire match result
        if (empty($matches[0]) || empty($matches[0][0])) {
            // $this->sendAndClose('No IP Detected.');
            return false;
        } else {
            $xForwardedFor = str_replace('X-Forwarded-For:', '', $matches[0][0]);
            $xForwardedFor = explode(",", $xForwardedFor);

            foreach ($xForwardedFor as &$a) $a = trim($a);

            return $xForwardedFor;
        }
        return false;
    }
}
