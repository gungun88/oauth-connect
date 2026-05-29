<?php

namespace ISeekUp\OAuthConnect\Support;

use InvalidArgumentException;
use ISeekUp\OAuthConnect\Models\Client;

class ScopeRegistry
{
    public function all(): array
    {
        return [
            'user.read' => 'Read basic profile',
            'user.email' => 'Read email address',
            'user.stats' => 'Read public activity counters',
            'user.moderation' => 'Read moderation status',
            'user.trust' => 'Read trust level when installed',
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
                throw new InvalidArgumentException("Unknown scope: $scopeName");
            }

            if (! in_array($scopeName, $allowed, true)) {
                throw new InvalidArgumentException("Scope is not allowed for this client: $scopeName");
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
}
