<?php

namespace PopulousWSS;

use Monolog\Handler\StreamHandler;
use PopulousWSS\Common\PopulousWSSConstants;
use PopulousWSS\Common\PopulousLogger;
use WSSC\Contracts\ConnectionContract;
use WSSC\Contracts\WebSocket;
use WSSC\Exceptions\WebSocketException;

class ServerBaseHandler extends WebSocket
{
    protected $clients = [];

    protected $log;
    /**
     * ServerHandler constructor.
     *
     * @throws \Exception
     */

    protected $CI;

    public function __construct()
    {
        $this->log = new PopulousLogger('ServerSocket');
        $this->log->pushHandler(new StreamHandler(APPPATH . 'socket_log/socket.log'));

        $isProduction  = getenv('POPULOUS_LOGGER') == '0';
        if ($isProduction) {
            $this->log->setProduction();
        }

        $this->CI = &get_instance();

        $this->CI->load->model([
            'WsServer_model',
        ]);
    }

    public function onOpen(ConnectionContract $conn)
    {
        $connId = $conn->getUniqueSocketId();
        $this->clients[$connId] = $conn;
        $this->log->debug("Connection opend Conn ID : $connId total clients: " . count($this->clients));
    }

    public function onMessage(ConnectionContract $recv, $msg)
    {
        $this->log->debug("Message " . $msg);
        $_decoded_msg = json_decode($msg, true);
        if (is_array($_decoded_msg)) {
            // $this->log->debug($msg);
            if (isset($_decoded_msg['event'])) {

                $data = [];
                if (isset($_decoded_msg['data'])) {
                    $data = $_decoded_msg['data'];
                }

                $event = strtolower($_decoded_msg['event']);
                $isApiEvent = strpos($event, 'api-event:') === 0;

                $this->log->debug('Is API Event ' . ($isApiEvent ? 'YES' : 'NO'));


                if ($isApiEvent) {
                    $eventExplode = explode(':', $event);
                    $this->_api_event_handler($eventExplode[1], $data);
                } else if (isset($_decoded_msg['channel'])) {
                    $channel = strtolower($_decoded_msg['channel']);
                    $this->_event_handler($recv, $channel, $event, $data);
                }
            }
        } else {
            $this->log->debug($msg);
            if ($msg == 'ping') {
                // $this->log->debug("I got Ping...");
                // if (is_resource($recv->getUniqueSocketId())) {
                //     // $this->log->debug("Sending Pong...");
                // }
                $recv->send("pong");
            }
        }
    }

    public function onClose(ConnectionContract $conn)
    {
        $connId = $conn->getUniqueSocketId();
        unset($this->clients[$connId]);
        $this->log->debug("Close, Conn Id : $connId ,  total clients: " . count($this->clients));
        $conn->close();
    }

    /**
     * @param ConnectionContract $conn
     * @param WebSocketException $ex
     */
    public function onError(ConnectionContract $conn, WebSocketException $ex)
    {
        echo 'Error occured: ' . $ex->printStack();
    }

    /**
     * You may want to implement these methods to bring ping/pong events
     *
     * @param ConnectionContract $conn
     * @param string $msg
     */
    public function onPing(ConnectionContract $conn, $msg)
    {
        // TODO: Implement onPing() method.
        echo "PING arrived: $msg \n";
    }

    /**
     * @param ConnectionContract $conn
     * @param $msg
     * @return mixed
     */
    public function onPong(ConnectionContract $conn, $msg)
    {
        // TODO: Implement onPong() method.
        echo "PONG arrived: $msg \n";
    }

    public function _get_clients()
    {
        return $this->clients;
    }

    protected function _is_subscribe_required($event): bool
    {
        return $event === PopulousWSSConstants::SUBSCRIBE_REQUIRED;
    }

    protected function _is_private_channel(string $channel): bool
    {
        return explode('-', $channel)[0] === PopulousWSSConstants::PRIVATE_CHANNEL;
    }

    protected function _is_external_channel(string $channel): bool
    {
        return explode('-', $channel)[0] === PopulousWSSConstants::EXTERNAL_CHANNEL;
    }
}
