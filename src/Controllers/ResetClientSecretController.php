<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Repositories\ClientRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ResetClientSecretController implements RequestHandlerInterface
{
    private $clients;

    public function __construct(ClientRepository $clients)
    {
        $this->clients = $clients;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $client = $this->clients->find((string) ($request->getQueryParams()['clientId'] ?? ''));

        if (! $client) {
            return new JsonResponse(['error' => 'Client not found.'], 404);
        }

        $secret = $this->clients->resetSecret($client);
        $this->clients->revokeTokens($client);

        return new JsonResponse(['data' => $this->clients->serialize($client, $secret)]);
    }
}
