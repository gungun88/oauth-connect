<?php

namespace ISeekUp\OAuthConnect\Support;

use Carbon\Carbon;
use Flarum\User\User;

class UserInfoBuilder
{
    public function build(User $user, array $scopes): array
    {
        $suspendedUntil = $this->suspendedUntil($user);
        $suspended = $suspendedUntil !== null && $suspendedUntil->isFuture();

        $data = [
            'id' => $user->id,
            'sub' => (string) $user->id,
            'username' => $user->username,
            'name' => $user->display_name ?: $user->username,
            'avatar_url' => $user->avatar_url,
            'active' => (bool) $user->is_email_confirmed && ! $suspended,
        ];

        if (in_array('user.email', $scopes, true)) {
            $data['email'] = $user->email;
            $data['is_email_confirmed'] = (bool) $user->is_email_confirmed;
        }

        if (in_array('user.stats', $scopes, true)) {
            $data['joined_at'] = $this->date($user->joined_at);
            $data['last_seen_at'] = $this->date($user->last_seen_at);
            $data['discussion_count'] = (int) $user->discussion_count;
            $data['comment_count'] = (int) $user->comment_count;
        }

        if (in_array('user.moderation', $scopes, true)) {
            $data['suspended'] = $suspended;
            $data['suspended_until'] = $this->date($suspendedUntil);
            $data['silenced'] = $suspended;
        }

        if (in_array('user.trust', $scopes, true) && array_key_exists('trust_level', $user->getAttributes())) {
            $data['trust_level'] = $user->getAttribute('trust_level');
        }

        return $data;
    }

    private function suspendedUntil(User $user): ?Carbon
    {
        if (! array_key_exists('suspended_until', $user->getAttributes())) {
            return null;
        }

        $value = $user->getAttribute('suspended_until');

        if ($value instanceof Carbon) {
            return $value;
        }

        return $value ? Carbon::parse($value) : null;
    }

    private function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
