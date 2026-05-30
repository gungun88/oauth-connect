<?php

namespace ISeekUp\OAuthConnect\Repositories;

use Carbon\Carbon;
use InvalidArgumentException;
use ISeekUp\OAuthConnect\Models\AccessToken;
use ISeekUp\OAuthConnect\Models\AuthorizationCode;
use ISeekUp\OAuthConnect\Models\Client;
use ISeekUp\OAuthConnect\Models\ClientAuthorization;
use ISeekUp\OAuthConnect\Models\RefreshToken;
use ISeekUp\OAuthConnect\Support\RandomGenerator;
use ISeekUp\OAuthConnect\Support\ScopeRegistry;
use ISeekUp\OAuthConnect\Support\Translation;

class ClientRepository
{
    private $random;
    private $scopes;
    private $translation;

    public function __construct(
        RandomGenerator $random,
        ScopeRegistry $scopes,
        Translation $translation
    ) {
        $this->random = $random;
        $this->scopes = $scopes;
        $this->translation = $translation;
    }

    public function find(string $clientId): ?Client
    {
        return Client::where('client_id', $clientId)->first();
    }

    public function findEnabled(string $clientId): ?Client
    {
        return Client::where('client_id', $clientId)->where('is_enabled', true)->first();
    }

    public function create(array $data): array
    {
        $client = new Client();
        $client->client_id = $this->uniqueClientId();

        $secret = $this->random->clientSecret();
        $client->client_secret_hash = password_hash($secret, PASSWORD_DEFAULT);

        $this->fill($client, $data, true);
        $client->save();

        return [$client, $secret];
    }

    public function update(Client $client, array $data): Client
    {
        $this->fill($client, $data, false);
        $client->save();

        return $client;
    }

    public function resetSecret(Client $client): string
    {
        $secret = $this->random->clientSecret();
        $client->client_secret_hash = password_hash($secret, PASSWORD_DEFAULT);
        $client->save();

        return $secret;
    }

    public function revokeTokens(Client $client): void
    {
        $now = Carbon::now();

        AccessToken::where('client_id', $client->client_id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        RefreshToken::where('client_id', $client->client_id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        AuthorizationCode::where('client_id', $client->client_id)->whereNull('used_at')->update(['used_at' => $now]);
    }

    public function delete(Client $client): void
    {
        $this->revokeTokens($client);

        ClientAuthorization::where('client_id', $client->client_id)->update(['revoked_at' => Carbon::now()]);
        $client->delete();
    }

    public function serialize(Client $client, ?string $secret = null): array
    {
        $data = [
            'client_id' => $client->client_id,
            'name' => $client->name,
            'description' => $client->description,
            'homepage_url' => $client->homepage_url,
            'icon_url' => $client->icon_url,
            'redirect_uris' => $client->redirectUris(),
            'scopes' => $client->scopeList(),
            'grant_types' => $client->grantTypeList(),
            'is_enabled' => (bool) $client->is_enabled,
            'created_at' => $this->date($client->created_at),
            'updated_at' => $this->date($client->updated_at),
        ];

        if ($secret !== null) {
            $data['client_secret'] = $secret;
        }

        return $data;
    }

    private function fill(Client $client, array $data, bool $creating): void
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException($this->trans('admin.errors.client_name_required', [], 'Client name is required.'));
        }

        $client->name = mb_substr($name, 0, 120);
        $client->description = $this->nullableString($data['description'] ?? null, 500);
        $client->homepage_url = $this->nullableUrl($data['homepage_url'] ?? null, true);
        $client->icon_url = $this->nullableUrl($data['icon_url'] ?? null, true);
        $client->redirect_uris = json_encode($this->redirectUris($data['redirect_uris'] ?? $data['redirect_uri'] ?? []));
        $client->scopes = $this->scopes->toString($this->scopes->normalize($data['scopes'] ?? $this->scopes->defaults()));
        $client->grant_types = 'authorization_code refresh_token';
        $client->is_enabled = array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : ($creating || (bool) $client->is_enabled);
    }

    private function redirectUris($value): array
    {
        if (is_string($value)) {
            $items = preg_split('/\r\n|\r|\n/', $value);
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            $items = [];
        }

        $items = array_values(array_unique(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $items), 'strlen')));

        if ($items === []) {
            throw new InvalidArgumentException($this->trans('admin.errors.redirect_uri_required', [], 'At least one redirect URI is required.'));
        }

        foreach ($items as $uri) {
            $this->assertValidRedirectUri($uri);
        }

        return $items;
    }

    private function assertValidRedirectUri(string $uri): void
    {
        $parts = parse_url($uri);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException($this->trans('admin.errors.invalid_redirect_uri', [
                'uri' => $uri,
            ], 'Invalid redirect URI: {uri}'));
        }

        if (! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            throw new InvalidArgumentException($this->trans('admin.errors.redirect_uri_scheme', [], 'Redirect URI must use http or https.'));
        }

        if (array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException($this->trans('admin.errors.redirect_uri_fragment', [], 'Redirect URI must not contain a fragment.'));
        }
    }

    private function nullableUrl($value, bool $allowEmpty): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $allowEmpty ? null : '';
        }

        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException($this->trans('admin.errors.invalid_url', [
                'url' => $value,
            ], 'Invalid URL: {url}'));
        }

        return mb_substr($value, 0, 255);
    }

    private function nullableString($value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function uniqueClientId(): string
    {
        do {
            $clientId = $this->random->clientId();
        } while (Client::where('client_id', $clientId)->exists());

        return $clientId;
    }

    private function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function trans(string $key, array $params = [], string $fallback = ''): string
    {
        return $this->translation->trans($key, $params, $fallback);
    }
}
