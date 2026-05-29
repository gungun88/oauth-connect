<?php

namespace ISeekUp\OAuthConnect\Models;

use Flarum\Database\AbstractModel;

class Client extends AbstractModel
{
    protected $table = 'oauth_connect_clients';

    public $timestamps = true;

    protected $casts = [
        'is_enabled' => 'bool',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
    ];

    public function redirectUris(): array
    {
        $decoded = json_decode((string) $this->redirect_uris, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'strlen')) : [];
    }

    public function scopeList(): array
    {
        return $this->splitList($this->scopes);
    }

    public function grantTypeList(): array
    {
        return $this->splitList($this->grant_types);
    }

    public function allowsRedirectUri(string $redirectUri): bool
    {
        return in_array($redirectUri, $this->redirectUris(), true);
    }

    public function allowsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypeList(), true);
    }

    private function splitList(?string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim((string) $value)) ?: [], 'strlen'));
    }
}
