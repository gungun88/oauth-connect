<?php

namespace ISeekUp\OAuthConnect\Models;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class ClientAuthorization extends AbstractModel
{
    protected $table = 'oauth_connect_authorizations';

    public $timestamps = true;

    protected $dates = [
        'authorized_at',
        'revoked_at',
        'created_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->hasOne(Client::class, 'client_id', 'client_id');
    }

    public function scopeList(): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim((string) $this->scope)) ?: [], 'strlen'));
    }
}
