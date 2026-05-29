<?php

namespace ISeekUp\OAuthConnect\Support;

use Psr\Http\Message\ServerRequestInterface;

class RequestData
{
    public function all(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (is_array($body)) {
            return $body;
        }

        if (is_object($body)) {
            return (array) $body;
        }

        $contentType = $request->getHeaderLine('content-type');

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str((string) $request->getBody(), $parsed);

            return $parsed;
        }

        return [];
    }
}
