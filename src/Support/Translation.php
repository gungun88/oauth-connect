<?php

namespace ISeekUp\OAuthConnect\Support;

use Symfony\Contracts\Translation\TranslatorInterface;

class Translation
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function trans(string $key, array $params = [], string $fallback = ''): string
    {
        $id = strpos($key, 'iseekup-oauth-connect.') === 0 ? $key : 'iseekup-oauth-connect.'.$key;
        $translated = $this->translator->trans($id, $this->parameters($params));

        return $translated === $id ? $this->fallback($fallback, $params) : $translated;
    }

    private function fallback(string $fallback, array $params): string
    {
        return strtr($fallback, $this->parameters($params));
    }

    private function parameters(array $params): array
    {
        $normalized = $params;

        foreach ($params as $key => $value) {
            if (is_string($key) && $key !== '' && $key[0] !== '{') {
                $normalized['{'.$key.'}'] = $value;
            }
        }

        return $normalized;
    }
}
