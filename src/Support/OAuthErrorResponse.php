<?php

namespace ISeekUp\OAuthConnect\Support;

use Laminas\Diactoros\Response\JsonResponse;

class OAuthErrorResponse
{
    public function make(string $error, string $description, int $status = 400, array $headers = []): JsonResponse
    {
        $headers = array_merge([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ], $headers);

        return new JsonResponse([
            'error' => $error,
            'error_description' => $description,
        ], $status, $headers);
    }
}
