<?php

namespace ISeekUp\OAuthConnect\Support;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use InvalidArgumentException;
use ISeekUp\OAuthConnect\Models\AuthorizationCode;
use ISeekUp\OAuthConnect\Models\Client;
use ISeekUp\OAuthConnect\Models\ClientAuthorization;
use ISeekUp\OAuthConnect\Repositories\ClientRepository;

class OAuthFlow
{
    private $clients;
    private $random;
    private $scopes;
    private $settings;
    private $translation;

    public function __construct(
        ClientRepository $clients,
        RandomGenerator $random,
        ScopeRegistry $scopes,
        SettingsRepositoryInterface $settings,
        Translation $translation
    ) {
        $this->clients = $clients;
        $this->random = $random;
        $this->scopes = $scopes;
        $this->settings = $settings;
        $this->translation = $translation;
    }

    public function validateAuthorizeRequest(array $query): array
    {
        $responseType = (string) ($query['response_type'] ?? '');

        if ($responseType !== 'code') {
            throw new InvalidArgumentException($this->trans('forum.error.response_type', [], 'Only response_type=code is supported.'));
        }

        $clientId = (string) ($query['client_id'] ?? '');
        $client = $clientId !== '' ? $this->clients->findEnabled($clientId) : null;

        if (! $client) {
            throw new InvalidArgumentException($this->trans('forum.error.unknown_client', [], 'Unknown or disabled client.'));
        }

        $redirectUri = (string) ($query['redirect_uri'] ?? '');

        if ($redirectUri === '') {
            throw new InvalidArgumentException($this->trans('forum.error.redirect_uri_required', [], 'redirect_uri is required.'));
        }

        if (! $client->allowsRedirectUri($redirectUri)) {
            throw new InvalidArgumentException($this->trans('forum.error.redirect_uri_not_registered', [], 'redirect_uri is not registered for this client.'));
        }

        $state = (string) ($query['state'] ?? '');

        if ($this->requiresState() && $state === '') {
            throw new InvalidArgumentException($this->trans('forum.error.state_required', [], 'state is required.'));
        }

        $scope = $this->scopes->normalize($query['scope'] ?? '', $client);

        return [$client, $redirectUri, $scope, $state];
    }

    public function safeRedirectUri(array $query): ?string
    {
        $clientId = (string) ($query['client_id'] ?? '');
        $redirectUri = (string) ($query['redirect_uri'] ?? '');
        $client = $clientId !== '' ? $this->clients->findEnabled($clientId) : null;

        if (! $client || $redirectUri === '' || ! $client->allowsRedirectUri($redirectUri)) {
            return null;
        }

        return $redirectUri;
    }

    public function createAuthorizationCode(Client $client, int $userId, string $redirectUri, array $scopes): AuthorizationCode
    {
        $code = new AuthorizationCode();
        $code->code = $this->uniqueAuthorizationCode();
        $code->client_id = $client->client_id;
        $code->user_id = $userId;
        $code->redirect_uri = $redirectUri;
        $code->scope = $this->scopes->toString($scopes);
        $code->expires_at = Carbon::now()->addSeconds($this->authorizationCodeLifetime());
        $code->save();

        $this->rememberAuthorization($client, $userId, $scopes);

        return $code;
    }

    public function authorizationCodeLifetime(): int
    {
        return max(60, (int) $this->settings->get('iseekup.oauth-connect.authorization_code_lifetime', 600));
    }

    public function requiresState(): bool
    {
        return (bool) ((int) $this->settings->get('iseekup.oauth-connect.require_state', 1));
    }

    private function rememberAuthorization(Client $client, int $userId, array $scopes): void
    {
        $authorization = ClientAuthorization::where('client_id', $client->client_id)
            ->where('user_id', $userId)
            ->first() ?: new ClientAuthorization();

        $authorization->client_id = $client->client_id;
        $authorization->user_id = $userId;
        $authorization->scope = $this->scopes->toString($scopes);
        $authorization->authorized_at = Carbon::now();
        $authorization->revoked_at = null;
        $authorization->save();
    }

    private function uniqueAuthorizationCode(): string
    {
        do {
            $code = $this->random->authorizationCode();
        } while (AuthorizationCode::where('code', $code)->exists());

        return $code;
    }

    private function trans(string $key, array $params = [], string $fallback = ''): string
    {
        return $this->translation->trans($key, $params, $fallback);
    }
}
