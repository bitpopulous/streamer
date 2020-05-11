<?php
namespace PopulousWSS;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PopulousWSS\Common\PopulousWSSConstants;
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
        $this->log = new Logger('ServerSocket');
        $this->log->pushHandler(new StreamHandler(APPPATH . 'socket_log/socket.log'));

        $this->CI = &get_instance();

        $this->CI->load->model([
            'WsServer_model',
        ]);

        $this->CI->load->library([
            'ApiSocket',
        ]);
    }

    public function onOpen(ConnectionContract $conn)
    {
        $connId = $conn->getUniqueSocketId();
        $this->clients[$connId] = $conn;
        $this->log->debug("Connection opend, Conn ID : $connId total clients: " . count($this->clients));
    }

    public function onMessage(ConnectionContract $recv, $msg)
    {
        $_decoded_msg = json_decode($msg, true);
        if (is_array($_decoded_msg)) {

            if (isset($_decoded_msg['event']) && isset($_decoded_msg['channel'])) {

                $event = strtolower($_decoded_msg['event']);
                $channel = strtolower($_decoded_msg['channel']);

                $data = [];
                if (isset($_decoded_msg['data'])) {
                    $data = $_decoded_msg['data'];
                }

                $this->_event_handler($recv, $channel, $event, $data);
            }

        } else {
            $this->log->debug($msg);
            if ($msg == 'ping') {
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
   
}
