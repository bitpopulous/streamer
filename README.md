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

FEES_BALANCE_OF=YOUR_SYMBOL
FEES_BALANCE_ABOVE=LIMIT_AMOUNT
FEES_BALANCE_DISCOUNT=DISCOUNT_PERCENT
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

![channels](https://user-images.githubusercontent.com/23646985/81881522-f5e76c80-95c2-11ea-8faf-a1137c21404f.png)

## Events
| Events              | Channels            | Message Direction |
|---------------------|---------------------|-------------------|
|<span style='color: #1F85DE'>⬆ exchange-buy</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ exchange-buy</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ exchange-sell</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ exchange-sell</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ exchange-cancel-order</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ exchange-cancel-order</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ exchange-init-guest</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ exchange-init-guest</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ exchange-init</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ exchange-init</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ orderbook-init</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ orderbook-init</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ api-setting-init</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ api-setting-init</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ api-setting-create</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ api-setting-create</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ api-setting-update</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ api-setting-update</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #1F85DE'>⬆ api-setting-delete</span>|<span style='color: #1F85DE'>private-channel</span>|<span style='color: #1F85DE'>C->S</span>|
|<span style='color: #ED781F'>⬇ api-setting-delete</span>|<span style='color: #ED781F'>private-channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #ED781F'>⬇ order-update</span>|<span style='color: #ED781F'>private_channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #ED781F'>⬇ balance-update</span>|<span style='color: #ED781F'>private_channel</span>|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #ED781F'>⬇ orderbook-update</span>|<span style='color: #ED781F'>market-global-channel|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #ED781F'>⬇ trade-history</span>|<span style='color: #ED781F'>market-global-channel|<span style='color: #ED781F'>S->C</span>|
|<span style='color: #ED781F'>⬇ price-change</span>|<span style='color: #ED781F'>crypto-rates-channel |<span style='color: #ED781F'>S->C</span>|

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
        "buy_orders": "<BUY_ORDER_DATA_ARRAY>",
        "sell_orders": "<SELL_ORDER_DATA_ARRAY>",
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
            "success_time: `2020-05-13 17:04:31`
        },
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

message will have one of [
"User could not found.",
"Invalid pair",
"Buy price is invalid.",
"Buy amount is invalid.",
"Trade could not submitted.",
"Insufficient balance."
]

```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." ,
        "trade_type": "limit"
    }
}
```

### Market
Request Payload
```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "amount": "AMOUNT_OF_BUYING",
        "pair_id": "COINPAIR_ID",
        "trade_type": "market",
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
        "buy_orders": "<BUY_ORDER_DATA_ARRAY>",
        "sell_orders": "<SELL_ORDER_DATA_ARRAY>",
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
            "success_time: `2020-05-13 17:04:31`
        },
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
        "message": "<AMOUNT> bought. <QTY> created open order at price <PRICE>.",
        "trade_type": "market"
    }
}
```

Error Response payload

message will have one of [
    "User could not found.",
    "Invalid pair",
    "Buy price is invalid.",
    "Trade could not submitted.",
    "Insufficient balance."
] 

```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." ,
        "trade_type": "market"
    }
}
```


### Stop Limit
Request Payload
```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "stop": "STOP_PRICE_OF_BUYING",
        "limit": "LIMIT_PRICE_OF_BUYING",
        "amount": "AMOUNT_OF_BUYING",
        "pair_id": "COINPAIR_ID",
        "trade_type": "stop_limit",
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
        "buy_orders": "<BUY_ORDER_DATA_ARRAY>",
        "sell_orders": "<SELL_ORDER_DATA_ARRAY>",
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
            "success_time: `2020-05-13 17:04:31`
        },
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
        "message": "Stop limit order has been placed",
        "trade_type": "stop_limit"
    }
}
```

Error Response payload

message will have one of [
    "User could not found.",
    "Invalid pair",
    "Buy Stop price invalid.",
    "Buy Limit price invalid.",
    "Buy Amounts invalid.",
    "Could not create order",
    "You have insufficient balance, More <AMOUNT_NEEDED> needed to create an order.""
] 

```json
{
    "event": "exchange-buy",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." ,
        "trade_type": "limit"
    }
}
```
## Sell Event
### Limit
Request Payload
```json
{
    "event": "exchange-sell",
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
        "buy_orders": "<BUY_ORDER_DATA_ARRAY>",
        "sell_orders": "<SELL_ORDER_DATA_ARRAY>",
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
            "success_time: `2020-05-13 17:04:31`
        },
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
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": true,
        "message": "Sell order successfully placed.",
        "trade_type": "limit"
    }
}
```

Error Response payload

message will have one of [
"User could not found.",
"Invalid pair",
"Buy price is invalid.",
"Buy amount is invalid.",
"Trade could not submitted.",
"Insufficient balance."
]

```json
{
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." ,
        "trade_type": "limit"
    }
}
```

### Market
Request Payload
```json
{
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "amount": "AMOUNT_OF_BUYING",
        "pair_id": "COINPAIR_ID",
        "trade_type": "market",
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
        "buy_orders": "<BUY_ORDER_DATA_ARRAY>",
        "sell_orders": "<SELL_ORDER_DATA_ARRAY>",
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
            "success_time: `2020-05-13 17:04:31`
        },
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
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": true,
        "message": "<AMOUNT> bought. <QTY> created open order at price <PRICE>.",
        "trade_type": "market"
    }
}
```

Error Response payload

message will have one of [
    "User could not found.",
    "Invalid pair",
    "Buy price is invalid.",
    "Trade could not submitted.",
    "Insufficient balance."
] 

```json
{
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." ,
        "trade_type": "market"
    }
}
```


### Stop Limit
Request Payload
```json
{
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "stop": "STOP_PRICE_OF_BUYING",
        "limit": "LIMIT_PRICE_OF_BUYING",
        "amount": "AMOUNT_OF_BUYING",
        "pair_id": "COINPAIR_ID",
        "trade_type": "stop_limit",
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
        "buy_orders": "<BUY_ORDER_DATA_ARRAY>",
        "sell_orders": "<SELL_ORDER_DATA_ARRAY>",
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
            "success_time: `2020-05-13 17:04:31`
        },
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
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": true,
        "message": "Stop limit order has been placed",
        "trade_type": "stop_limit"
    }
}
```

Error Response payload

message will have one of [
    "User could not found.",
    "Invalid pair",
    "Buy Stop price invalid.",
    "Buy Limit price invalid.",
    "Buy Amounts invalid.",
    "Could not create order",
    "You have insufficient balance, More <AMOUNT_NEEDED> needed to create an order.""
] 

```json
{
    "event": "exchange-sell",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "User could not found." ,
        "trade_type": "stop_limit"
    }
}
```
