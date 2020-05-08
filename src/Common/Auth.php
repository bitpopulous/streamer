<?php

namespace PopulousWSS\Common;

class Auth
{
    /**
     * @return bool
     */
    public function _authenticate(string $channel, string $auth): bool
    {
        return $this->CI->privatechannels_model->isValidChannelAuth($channel, $auth);
    }

    /**
     * @return string user_id
     */
    public function _get_user_id(string $auth): string
    {
        return $this->CI->privatechannels_model->getUserIdFromAuth($auth);
    }
}
