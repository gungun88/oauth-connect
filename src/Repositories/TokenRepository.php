<?php

namespace ISeekUp\OAuthConnect\Repositories;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use ISeekUp\OAuthConnect\Models\AccessToken;
use ISeekUp\OAuthConnect\Models\RefreshToken;
use ISeekUp\OAuthConnect\Support\RandomGenerator;
use ISeekUp\OAuthConnect\Support\ScopeRegistry;

class TokenRepository
{
    private $random;
    private $settings;
    private $scopes;

    public function __construct(
        RandomGenerator $random,
        SettingsRepositoryInterface $settings,
        ScopeRegistry $scopes
    ) {
        $this->random = $random;
        $this->settings = $settings;
        $this->scopes = $scopes;
    }

    public function issuePair(string $clientId, int $userId, array $scopes): array
    {
        $accessToken = new AccessToken();
        $accessToken->token = $this->uniqueAccessToken();
        $accessToken->client_id = $clientId;
        $accessToken->user_id = $userId;
        $accessToken->scope = $this->scopes->toString($scopes);
        $accessToken->expires_at = Carbon::now()->addSeconds($this->accessLifetime());
        $accessToken->save();

        $refreshToken = new RefreshToken();
        $refreshToken->token = $this->uniqueRefreshToken();
        $refreshToken->client_id = $clientId;
        $refreshToken->user_id = $userId;
        $refreshToken->scope = $accessToken->scope;
        $refreshToken->expires_at = Carbon::now()->addSeconds($this->refreshLifetime());
        $refreshToken->save();

        return [$accessToken, $refreshToken];
    }

    public function accessLifetime(): int
    {
        return max(60, (int) $this->settings->get('iseekup.oauth-connect.access_token_lifetime', 7200));
    }

    public function refreshLifetime(): int
    {
        return max(300, (int) $this->settings->get('iseekup.oauth-connect.refresh_token_lifetime', 2592000));
    }

    private function uniqueAccessToken(): string
    {
        do {
            $token = $this->random->accessToken();
        } while (AccessToken::where('token', $token)->exists());

        return $token;
    }

    private function uniqueRefreshToken(): string
    {
        do {
            $token = $this->random->refreshToken();
        } while (RefreshToken::where('token', $token)->exists());

        return $token;
    }
}
