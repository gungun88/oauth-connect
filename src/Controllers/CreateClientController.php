<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Http\RequestUtil;
use InvalidArgumentException;
use ISeekUp\OAuthConnect\Repositories\ClientRepository;
use ISeekUp\OAuthConnect\Support\RequestData;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateClientController implements RequestHandlerInterface
{
    private $clients;
    private $data;

    public function __construct(
        ClientRepository $clients,
        RequestData $data
    ) {
        $this->clients = $clients;
        $this->data = $data;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        try {
            [$client, $secret] = $this->clients->create($this->data->all($request));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['data' => $this->clients->serialize($client, $secret)], 201);
    }
}
