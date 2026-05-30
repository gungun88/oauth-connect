<?php

namespace ISeekUp\OAuthConnect\Support;

use InvalidArgumentException;
use ISeekUp\OAuthConnect\Models\Client;

class ScopeRegistry
{
    private $translation;

    public function __construct(Translation $translation)
    {
        $this->translation = $translation;
    }

    public function all(): array
    {
        return [
            'user.read' => $this->trans('scopes.user_read', [], 'Read basic profile'),
            'user.email' => $this->trans('scopes.user_email', [], 'Read email address'),
            'user.stats' => $this->trans('scopes.user_stats', [], 'Read public activity counters'),
            'user.moderation' => $this->trans('scopes.user_moderation', [], 'Read moderation status'),
            'user.trust' => $this->trans('scopes.user_trust', [], 'Read trust level when installed'),
        ];
    }

    public function defaults(): array
    {
        return ['user.read'];
    }

    public function normalize($scope, ?Client $client = null): array
    {
        $scopes = is_array($scope)
            ? $scope
            : preg_split('/\s+/', trim((string) $scope));

        $scopes = array_values(array_unique(array_filter($scopes ?: [], 'strlen')));

        if ($scopes === []) {
            $scopes = $this->defaults();
        }

        $known = array_keys($this->all());
        $allowedByClient = $client ? $client->scopeList() : $known;
        $allowed = $allowedByClient === [] ? $known : array_intersect($known, $allowedByClient);

        foreach ($scopes as $scopeName) {
            if (! in_array($scopeName, $known, true)) {
                throw new InvalidArgumentException($this->trans('errors.unknown_scope', [
                    'scope' => $scopeName,
                ], 'Unknown scope: {scope}'));
            }

            if (! in_array($scopeName, $allowed, true)) {
                throw new InvalidArgumentException($this->trans('errors.scope_not_allowed', [
                    'scope' => $scopeName,
                ], 'Scope is not allowed for this client: {scope}'));
            }
        }

        if (! in_array('user.read', $scopes, true)) {
            array_unshift($scopes, 'user.read');
        }

        return array_values(array_unique($scopes));
    }

    public function toString(array $scopes): string
    {
        return implode(' ', array_values(array_unique($scopes)));
    }

    private function trans(string $key, array $params = [], string $fallback = ''): string
    {
        return $this->translation->trans($key, $params, $fallback);
    }
}
