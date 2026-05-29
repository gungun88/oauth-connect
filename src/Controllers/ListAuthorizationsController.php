<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\ClientAuthorization;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListAuthorizationsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $authorizations = ClientAuthorization::query()
            ->with(['user', 'client'])
            ->orderBy('authorized_at', 'desc')
            ->limit(200)
            ->get()
            ->map(function (ClientAuthorization $authorization) {
                return [
                    'client_id' => $authorization->client_id,
                    'client_name' => $authorization->client ? $authorization->client->name : $authorization->client_id,
                    'user_id' => $authorization->user_id,
                    'username' => $authorization->user ? $authorization->user->username : null,
                    'display_name' => $authorization->user ? $authorization->user->display_name : null,
                    'scopes' => $authorization->scopeList(),
                    'authorized_at' => $this->date($authorization->authorized_at),
                    'revoked_at' => $this->date($authorization->revoked_at),
                ];
            })
            ->values()
            ->all();

        return new JsonResponse(['data' => $authorizations]);
    }

    private function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
