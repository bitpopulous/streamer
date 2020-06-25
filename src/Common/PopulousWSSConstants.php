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

    public const TRADE_TYPE_LIMIT = 'limit';
    public const TRADE_TYPE_MARKET = 'market';
    public const TRADE_TYPE_STOP_LIMIT = 'stop_limit';
    

    public const BID_PENDING_STATUS = 0;
    public const BID_COMPLETE_STATUS = 1;
    public const BID_CANCELLED_STATUS = 2;
    public const BID_QUEUED_STATUS = 3;

    public const EVENT_ORDER_UPDATED = 1;
    public const EVENT_COINPAIR_UPDATED = 2;
    public const EVENT_TRADE_CREATED = 3;
    public const EVENT_MARKET_SUMMARY = 4;
}
