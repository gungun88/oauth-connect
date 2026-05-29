<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\Client;
use ISeekUp\OAuthConnect\Repositories\ClientRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListClientsController implements RequestHandlerInterface
{
    private $clients;

    public function __construct(ClientRepository $clients)
    {
        $this->clients = $clients;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $clients = Client::query()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Client $client) {
                return $this->clients->serialize($client);
            })
            ->values()
            ->all();

        return new JsonResponse(['data' => $clients]);
    }
}
