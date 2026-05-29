<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\AccessToken;
use ISeekUp\OAuthConnect\Models\ClientAuthorization;
use ISeekUp\OAuthConnect\Models\RefreshToken;
use ISeekUp\OAuthConnect\Support\RequestData;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RevokeAuthorizationController implements RequestHandlerInterface
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
        $clientId = (string) ($body['client_id'] ?? '');
        $userId = (int) ($body['user_id'] ?? 0);

        if ($clientId === '' || $userId <= 0) {
            return new JsonResponse(['error' => 'client_id and user_id are required.'], 422);
        }

        $now = Carbon::now();

        ClientAuthorization::where('client_id', $clientId)
            ->where('user_id', $userId)
            ->update(['revoked_at' => $now]);

        AccessToken::where('client_id', $clientId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        RefreshToken::where('client_id', $clientId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        return new JsonResponse(['data' => ['revoked' => true]]);
    }
}
