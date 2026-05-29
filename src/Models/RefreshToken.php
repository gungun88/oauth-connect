<?php

namespace ISeekUp\OAuthConnect\Models;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;

class RefreshToken extends AbstractModel
{
    protected $table = 'oauth_connect_refresh_tokens';

    public $timestamps = true;

    protected $dates = [
        'expires_at',
        'revoked_at',
        'created_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function scopeList(): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim((string) $this->scope)) ?: [], 'strlen'));
    }

    public static function findValid(string $token): ?self
    {
        return static::where('token', $token)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }
}
