<?php

namespace ISeekUp\OAuthConnect\Middlewares;

use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\AccessToken;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OAuthBearerMiddleware implements MiddlewareInterface
{
    private const ROUTES = [
        'oauth-connect.user',
        'oauth-connect.user.alias',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! in_array($request->getAttribute('routeName'), self::ROUTES, true)) {
            return $handler->handle($request);
        }

        $authorization = $request->getHeaderLine('authorization');

        if (stripos($authorization, 'Bearer ') !== 0) {
            return $handler->handle($request);
        }

        $tokenValue = trim(substr($authorization, 7));
        $token = $tokenValue !== '' ? AccessToken::findValid($tokenValue) : null;

        if (! $token || ! $token->user) {
            return new JsonResponse([
                'error' => 'invalid_token',
                'error_description' => 'Bearer token is invalid, expired, or revoked.',
            ], 401, [
                'WWW-Authenticate' => 'Bearer realm="OAuth2 UserInfo"',
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        }

        $actor = $token->user;
        $actor->updateLastSeen()->save();

        $request = RequestUtil::withActor($request, $actor)
            ->withAttribute('oauthConnectToken', $token)
            ->withAttribute('oauthConnectScopes', $token->scopeList())
            ->withAttribute('bypassCsrfToken', true)
            ->withoutAttribute('session');

        return $handler->handle($request);
    }
}
