<?php

namespace PopulousWSS\Common;

class Auth
{
    /**
     * @return bool
     */
    public function _authenticate(string $channel, string $auth): bool
    {
        return $this->CI->WsServer_model->is_valid_channel_auth($channel, $auth);
    }

    /**
     * @return string user_id
     */
    public function _get_user_id(string $auth): string
    {
        return $this->CI->WsServer_model->get_user_id_from_auth($auth);
    }
}
