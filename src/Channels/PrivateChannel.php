<?php

namespace PopulousWSS\Channels;

use PopulousWSS\Common\Auth;
use PopulousWSS\ServerHandler;

class PrivateChannel extends PublicChannel
{
    use Auth;

    public function __construct(ServerHandler $server)
    {
        parent::__construct($server);
    }
}
