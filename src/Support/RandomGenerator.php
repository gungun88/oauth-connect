<?php

namespace ISeekUp\OAuthConnect\Support;

class RandomGenerator
{
    public function clientId(): string
    {
        return 'oc_'.strtolower($this->token(18));
    }

    public function clientSecret(): string
    {
        return 'ocs_'.$this->token(36);
    }

    public function authorizationCode(): string
    {
        return $this->token(48);
    }

    public function accessToken(): string
    {
        return $this->token(48);
    }

    public function refreshToken(): string
    {
        return $this->token(64);
    }

    private function token(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
