<?php

namespace ISeekUp\OAuthConnect\Models;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;

class AuthorizationCode extends AbstractModel
{
    protected $table = 'oauth_connect_authorization_codes';

    public $timestamps = true;

    protected $dates = [
        'expires_at',
        'used_at',
        'created_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public static function findValid(string $code): ?self
    {
        return static::where('code', $code)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }
}
