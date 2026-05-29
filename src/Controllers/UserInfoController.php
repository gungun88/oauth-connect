<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Support\OAuthErrorResponse;
use ISeekUp\OAuthConnect\Support\UserInfoBuilder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UserInfoController implements RequestHandlerInterface
{
    private $builder;
    private $errors;

    public function __construct(
        UserInfoBuilder $builder,
        OAuthErrorResponse $errors
    ) {
        $this->builder = $builder;
        $this->errors = $errors;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $request->getAttribute('oauthConnectToken')) {
            return $this->errors->make('invalid_token', 'A valid OAuth2 Bearer token is required.', 401, [
                'WWW-Authenticate' => 'Bearer realm="OAuth2 UserInfo"',
            ]);
        }

        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        return new JsonResponse($this->builder->build($actor, $request->getAttribute('oauthConnectScopes', ['user.read'])));
    }
}
