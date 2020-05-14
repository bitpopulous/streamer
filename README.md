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

**Table of Contents**
- [Events](#events)
    - [Subscribe Channels](#subscribe-channels)
        - [Subscribe global market](#subscribe-global-market)
        - [Subscribe Crypto Rates channel](#subscribe-crypto-rates-channel)
        - [Subscribe Private user channel](#subscribe-private-user-channel)
    - [Exchange Events](#exchange-events)
        - [Init Exchange Guest](#init-exchange-guest)
        - [Init Exchange](#init-exchange)
        - [Buy Limit](#buy-limit)
        - [Buy Market](#buy-market)
        - [Buy Stop Limit](#buy-stop-limit)
        - [Sell Limit](#sell-limit)
        - [Sell Market](#sell-market)
        - [Sell Stop Limit](#sell-stop-limit)
        - [Cancel Order Event](#cancel-order-event)
    - [Orderbook Events](#orderbook-events)
        - [Init Orderbook](#init-orderbook)
    - [API settings Events](#api-settings-events)
        - [Init API settings](#init-api-settings)
        - [Create API](#create-api)
        - [Update API](#update-api)
        - [Delete API](#delete-api)

## Events
| Events                    | Channels                  | Message Direction  |
|---------------------------|---------------------------|--------------------|
|**⬆ exchange-buy**         |**private-channel**        |**C->S**            |
|*⬇ exchange-buy*           |*private-channel*          |*S->C*              |
|**⬆ exchange-sell**        |**private-channel**        |**C->S**            |
|*⬇ exchange-sell*          |*private_channel*          |*S->C*              |
|**⬆ exchange-cancel-order**|**private-channel**        |**C->S**            |
|*⬇ exchange-cancel-order*  |*private_channel*          |*S->C*              |
|**⬆ exchange-init-guest**  |**private-channel**        |**C->S**            |
|*⬇ exchange-init-guest*    |*private_channel*          |*S->C*              |
|**⬆ exchange-init**        |**private-channel**        |**C->S**            |
|*⬇ exchange-init*          |*private_channel*          | *S->C*             |
|**⬆ orderbook-init**       |**private-channel**        |**C->S**            |
|*⬇ orderbook-init*         |*private_channel*          |*S->C*              |
|**⬆ api-setting-init**     |**private-channel**        |**C->S**            |
|*⬇ api-setting-init*       |*private_channel*          |*S->C*              |
|**⬆ api-setting-create**   |**private-channel**        |**C->S**            |
|*⬇ api-setting-create*     |*private_channel*          |*S->C*              |
|**⬆ api-setting-update**   |**private-channel**        |**C->S**            |
|*⬇ api-setting-update*     |*private_channel*          |*S->C*              |
|**⬆ api-setting-delete**   |**private-channel**        |**C->S**            |
|*⬇ api-setting-delete*     |*private_channel*          |*S->C*              |
|*⬇ order-update*           |*private_channel*          |*S->C*              |
|*⬇ balance-update*         |*private_channel*          |*S->C*              |
|*⬇ orderbook*              |*market-global-channel*    |*S->C*              |
|*⬇ trade-history*          |*market-global-channel*    |*S->C*              |
|*⬇ price-change*           |*crypto-rates-channel*     |*S->C*              |

## Subscribe Channels
### Subscribe global market

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

### Subscribe Crypto Rates channel

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


### Subscribe Private user channel

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

## Exchange Events
### Init Exchange Guest
Request Payload
```json
{
    "event": "exchange-init-guest",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "market": "PPT_USDC",
    }
}
```

Response Payload
```json
{
    "event": "exchange-init-guest",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "buy_orders": "ARRAY_OF_BUY_ORDERS",
        "sell_orders": "ARRAY_OF_SELL_ORDERS",
        "coin_history": "ARRAY_OF_COIN_HISTORY",
        "coinpairs_24h_summary": "OBJECT_OF_COINPAIR_SUMMARY",
        "market_pairs": "ARRAY_OF_MARKET_PAIRS",
        "trade_history": "ARRAY_OF_TRADE_HISTORY",
    }
}
```

### Init Exchange
Request Payload
```json
{
    "event": "exchange-init",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "market": "PPT_USDC",
        "ua": "USER_AUTH_DATA",
    }
}
```

Response Payload
```json
{
    "event": "exchange-init",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "buy_orders": "ARRAY_OF_BUY_ORDERS",
        "sell_orders": "ARRAY_OF_SELL_ORDERS",
        "coin_history": "ARRAY_OF_COIN_HISTORY",
        "coinpairs_24h_summary": "OBJECT_OF_COINPAIR_SUMMARY",
        "market_pairs": "ARRAY_OF_MARKET_PAIRS",
        "trade_history": "ARRAY_OF_TRADE_HISTORY",
        "completed_orders": "ARRAY_OF_COMPLETED_ORDERS",
        "pending_orders": "ARRAY_OF_PENDING_ORDERS",
        "user_balance": "ARRAY_OF_USER_BALANCE",
    }
}
```

### Buy Limit
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
    "event": "orderbook",
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

### Buy Market
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
    "event": "orderbook",
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


### Buy Stop Limit
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
    "event": "orderbook",
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

### Sell Limit
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
    "event": "orderbook",
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

### Sell Market
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
    "event": "orderbook",
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


### Sell Stop Limit
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
    "event": "orderbook",
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

### Cancel Order Event

Request Payload
```json
{
    "event": "exchange-cancel-order",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "order_id": "ORDER_ID",
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
    "event": "orderbook",
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
    "event": "exchange-cancel-order",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": true,
        "message": "Request cancelled successfully."
    }
}
```

Error Response payload

message will have one of [
"You are not allow to cancel this order.",
"Could not cancelled the order"
]

```json
{
    "event": "exchange-cancel-order",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "isSuccess": false,
        "message": "You are not allow to cancel this order."
    }
}
```

## Orderbook Events
### Init Orderbook
Request Payload
```json
{
    "event": "orderbook-init",
    "channel": "market-<MARKET_NAME>-global",
    "data": {
        "market": "PPT_USDC",
    }
}
```

Response Payload
```json
{
    "event": "orderbook-init",
    "channel": "market-<MARKET_NAME>-global",
    "data": {
        "buy_orders": "ARRAY_OF_BUY_ORDERS",
        "sell_orders": "ARRAY_OF_SELL_ORDERS",
    }
}
```

## API settings Events
### Init API settings
Request Payload
```json
{
    "event": "api-setting-init",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "ua": "USER_AUTH",
    }
}
```

Response Payload
```json
{
    "event": "api-setting-init",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "status": true,
        "all_keys": [],
    }
}
```

### Create API
Request Payload
```json
{
    "event": "api-setting-create",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "ua": "USER_AUTH",
        "api_name": "API_NAME",
        "ga_token": "GA_TOKEN (if set)",
        "is_ga_required": false,
    }
}
```

Response Payload
```json
{
    "event": "api-setting-create",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "status": true,
        "message": "API successfully created.",
        "new_key": {},
    }
}
```

### Update API
Request Payload
```json
{
    "event": "api-setting-update",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "ua": "USER_AUTH",
        "api_name": "API_NAME",
        "ga_token": "GA_TOKEN (if set)",
        "is_ga_required": false,
        "id": "API_ID",
        "can_trade": 0,
        "can_withdraw": 1,
        "ip_addresses_text": "",
        "ip_restricted": 0,
        "read_info": 1,
        "status": 0
    }
}
```

Response Payload
```json
{
    "event": "api-setting-update",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "status": true,
        "message": "API successfully updated.",
    }
}
```

### Delete API
Request Payload
```json
{
    "event": "api-setting-delete",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "ua": "USER_AUTH",
        "api_id": "API_ID",
        "ga_token": "GA_TOKEN (if set)",
        "is_ga_required": false,
    }
}
```

Response Payload
```json
{
    "event": "api-setting-delete",
    "channel": "private-<PRIVATE_ID>",
    "data": {
        "status": true,
        "message": "API <api_name> successfully deleted.",
        "deleted_id": "DELETED_API_ID",
    }
}
```