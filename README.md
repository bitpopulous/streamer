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


# Official Documentation

* The base endpoint is: wss//streamer.populous.world/wss
## Subscribe channels
### 1. Subscribe global market

Request Payload
```json
{
    "event": "populous:subscribe",
    "channel": "market-<MARKET_NAME>-global",
    "data": []
}
```

Response Payload
```json
{
    "event": "populous:subscribe_succeeded",
    "channel": "market-<MARKET_NAME>-global",
    "data": []
}
```

### 2. Subscribe Crypto Rates channel

Request Payload
```json
{
    "event": "populous:subscribe",
    "channel": "crypto-rates",
    "data": []
}
```

Response Payload
```json
{
    "event": "populous:subscribe_succeeded",
    "channel": "crypto-rates",
    "data": []
}
```


### 3. Subscribe Private user channel

Request Payload
```json
{
    "event": "populous:subscribe",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "auth": "USER_AUTH_DATA"
    }
}
```

Response Payload
```json
{
    "event": "populous:subscribe_succeeded",
    "channel": "private-<PRIVATE_ID>",
    "data": []
}
```

## Buy Event
### Limit
Request Payload
```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "amount": "AMOUNT_OF_BUYING",
        "pair_id": "COINPAIR_ID",
        "price": "PRICE_OF_BUYING",
        "trade_type": "limit",
        "ua": "USER_AUTH_DATA",
    }
}
```

Response Payload
```json
{
    "event": "order-update",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "amount_available": "AMOUNT_AVAILABLE_NUMBER",
        "bid_price": "PRICE_OF_BUYING",
        "bid_qty": "AMOUNT_OF_BUYING",
        ...
    }
}
```
```json
{
    "event": "balance-update",
    "channel": "private-<PRIVATE_ID>",
    "data": [
        {
            "balance": "BALANCE",
            "balance_on_hold": "BALANCE_ON_HOLD",
            "currency_id": "PRIMARY_CURRENCY_ID",
            "user_id": "USER_ID",
        },
        {
            "balance": "BALANCE",
            "balance_on_hold": "BALANCE_ON_HOLD",
            "currency_id": "SECONDARY_CURRENCY_ID",
            "user_id": "USER_ID",
        }
    ]
}
```
```json
{
    "event": "orderbook-update",
    "channel": "market-<MARKET_NAME>-global",
    "data": {
        "buy_orders": [...],
        "sell_orders": [...],
    }
}
```
```json
{
    "event": "trade-history",
    "channel": "market-<MARKET_NAME>-global",
    "data": [
        {
            "bid_price": "1.53",
            "bid_type": "SELL",
            "complete_qty": "30",
            "success_time: "2020-05-13 17:04:31"
        },
        ...
    ]
}
```
```json
{
    "event": "price-change",
    "channel": "crypto-rates",
    "data": {
        "current_price": "1.530000",
        "previous_price": "1.530000",
    }
}
```
```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": true,
        "message": "Buy order successfully placed.",
        "trade_type": "limit"
    }
}
```

Error Response payload
```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." || "Invalid pair"
            || "Buy price is invalid." || "Buy amount is invalid."
            || "Trade could not submitted." || "Insufficient balance." ,
        "trade_type": "limit"
    }
}
```