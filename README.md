# populous world streamer package

This is a streamer package based on CodeIgniter 3.1.4 that is used for populous world.

## installation

<code>cd project_root</code>

<code>composer require populous/streamer</code>

## put .env file in application directory

<pre>
DB_HOST=YOUR_DB_HOST
DB_USERNAME=YOUR_DB_USERNAME
DB_PASSWORD=YOUR_DB_PASSWORD
DB_NAME=YOUR_DB_NAME

WEBSOCKET_IP=127.0.0.1
WEBSOCKET_URL='ws://127.0.0.1'
WEBSOCKET_PORT=8443
WEBSOCKET_ENDPOINT='ws://127.0.0.1:8443'
WEBSOCKET_ENDPOINT_SERVER_USE='ws://127.0.0.1:8443'

ADMIN_USER_ID=YOUR_ADMIN_USER_ID

FEES_BALANCE_OF=PXT
FEES_BALANCE_ABOVE=9999
FEES_BALANCE_DISCOUNT=25
</pre>

## put WsServer_model.php file in /application/models directory

this streamer packages uses WsServer_model.php file, so if don't have WsServer_model.php file, it will shows error.

## put shell_scripts directory in project root directory

> mkdir shell_scripts

> touch shell_scripts/run_public_socket_server.sh

<pre>
#!/bin/bash

sudo pkill -f php-wss
php /var/www/Altyex/index.php websocket runServer
</pre>

## run the WsServer 
> nohup sh shell_scripts/run_public_socket_server.sh > shell_scripts/logs/run_public_socket_server.log & disown