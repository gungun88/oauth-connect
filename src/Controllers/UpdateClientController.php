<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Http\RequestUtil;
use InvalidArgumentException;
use ISeekUp\OAuthConnect\Repositories\ClientRepository;
use ISeekUp\OAuthConnect\Support\RequestData;
use ISeekUp\OAuthConnect\Support\Translation;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UpdateClientController implements RequestHandlerInterface
{
    private $clients;
    private $data;
    private $translation;

    public function __construct(
        ClientRepository $clients,
        RequestData $data,
        Translation $translation
    ) {
        $this->clients = $clients;
        $this->data = $data;
        $this->translation = $translation;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $client = $this->clients->find((string) ($request->getQueryParams()['clientId'] ?? ''));

        if (! $client) {
            return new JsonResponse(['error' => $this->translation->trans('admin.errors.client_not_found', [], 'Client not found.')], 404);
        }

        try {
            $client = $this->clients->update($client, $this->data->all($request));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['data' => $this->clients->serialize($client)]);
    }
}
