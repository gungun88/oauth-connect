<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Support\OAuthFlow;
use ISeekUp\OAuthConnect\Support\RequestData;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class AuthorizeController implements RequestHandlerInterface
{
    private $flow;
    private $data;

    public function __construct(
        OAuthFlow $flow,
        RequestData $data
    ) {
        $this->flow = $flow;
        $this->data = $data;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $this->data->all($request);

        try {
            [$client, $redirectUri, $scopes, $state] = $this->flow->validateAuthorizeRequest($body);
        } catch (Throwable $e) {
            $redirectUri = $this->flow->safeRedirectUri($body);

            if ($redirectUri !== null) {
                return new RedirectResponse($this->withQuery($redirectUri, [
                    'error' => 'invalid_request',
                    'error_description' => $e->getMessage(),
                    'state' => (string) ($body['state'] ?? ''),
                ]));
            }

            return new HtmlResponse('Invalid OAuth request.', 400);
        }

        if (($body['decision'] ?? '') !== 'approve') {
            return new RedirectResponse($this->withQuery($redirectUri, [
                'error' => 'access_denied',
                'error_description' => 'The user denied the authorization request.',
                'state' => $state,
            ]));
        }

        $code = $this->flow->createAuthorizationCode($client, (int) $actor->id, $redirectUri, $scopes);

        return new RedirectResponse($this->withQuery($redirectUri, [
            'code' => $code->code,
            'state' => $state,
        ]));
    }

    private function withQuery(string $uri, array $params): string
    {
        $params = array_filter($params, function ($value) {
            return $value !== '';
        });
        $separator = strpos($uri, '?') !== false ? '&' : '?';

        return $uri.$separator.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
