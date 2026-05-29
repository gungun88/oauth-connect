<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\AccessToken;
use ISeekUp\OAuthConnect\Models\RefreshToken;
use ISeekUp\OAuthConnect\Support\RequestData;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RevokeTokenController implements RequestHandlerInterface
{
    private $data;

    public function __construct(RequestData $data)
    {
        $this->data = $data;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = $this->data->all($request);
        $token = (string) ($body['token'] ?? '');

        if ($token === '') {
            return new JsonResponse(['error' => 'token is required.'], 422);
        }

        $now = Carbon::now();
        $accessCount = AccessToken::where('token', $token)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        $refreshCount = RefreshToken::where('token', $token)->whereNull('revoked_at')->update(['revoked_at' => $now]);

        return new JsonResponse(['data' => ['revoked' => ($accessCount + $refreshCount) > 0]]);
    }
}
