<?php

namespace PopulousWSS\Common;

/**
 *
 * @author Populous World Ltd
 */
interface PopulousWSSConstants
{
    public const SUBSCRIBE_REQUIRED = 'populous:subscribe';
    public const SUBSCRIBE_SUCCESS = 'populous:subscribe_succeeded';

    public const PRIVATE_CHANNEL = 'private';
    public const EXTERNAL_CHANNEL = 'external';

    public const TRADE_TYPE_LIMIT = 'limit';
    public const TRADE_TYPE_MARKET = 'market';
    public const TRADE_TYPE_STOP_LIMIT = 'stop_limit';


    public const BID_PENDING_STATUS = 0;
    public const BID_COMPLETE_STATUS = 1;
    public const BID_CANCELLED_STATUS = 2;
    public const BID_QUEUED_STATUS = 3;
    public const BID_FAILED_STATUS = 4;

    public const EXTERNAL_ORDER_INACTIVE_STATUS = 0;
    public const EXTERNAL_ORDER_ACTIVE_STATUS = 1;

    public const EVENT_ORDER_UPDATED = 1;
    public const EVENT_COINPAIR_UPDATED = 2;
    public const EVENT_TRADE_CREATED = 3;
    public const EVENT_MARKET_SUMMARY = 4;
    public const EVENT_EXTERNAL_ORDERBOOK_UPDATE = 5;

    public const OB_IDS = 'ids';
    public const OB_TYPE = 't';
    public const OB_PRICE = 'p';
    public const OB_USER_IDS = 'ui';
    public const OB_TOTAL_QTY = 'q';
    public const OB_TOTAL_AMOUNT = 'a';
}
