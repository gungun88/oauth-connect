<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Foundation\Application;
use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\Client;
use ISeekUp\OAuthConnect\Support\OAuthFlow;
use ISeekUp\OAuthConnect\Support\ScopeRegistry;
use ISeekUp\OAuthConnect\Support\Translation;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class AuthorizePageController implements RequestHandlerInterface
{
    private $flow;
    private $scopes;
    private $app;
    private $translation;

    public function __construct(
        OAuthFlow $flow,
        ScopeRegistry $scopes,
        Application $app,
        Translation $translation
    ) {
        $this->flow = $flow;
        $this->scopes = $scopes;
        $this->app = $app;
        $this->translation = $translation;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return new HtmlResponse($this->page(
                $this->trans('forum.page_title.login_required', [], 'Login required'),
                $this->loginMarkup($request)
            ), 401);
        }

        try {
            [$client, $redirectUri, $requestedScopes, $state] = $this->flow->validateAuthorizeRequest($request->getQueryParams());
        } catch (Throwable $e) {
            return new HtmlResponse($this->page(
                $this->trans('forum.page_title.invalid_request', [], 'Invalid OAuth request'),
                $this->errorMarkup($e->getMessage())
            ), 400);
        }

        $session = $request->getAttribute('session');
        $csrfToken = $session ? $session->token() : '';

        return new HtmlResponse($this->page($this->trans('forum.page_title.authorize', [
            'client' => $client->name,
        ], 'Authorize '.$client->name), $this->authorizeMarkup(
            $client,
            $redirectUri,
            $requestedScopes,
            $state,
            $csrfToken,
            $actor->display_name ?: $actor->username
        )));
    }

    private function authorizeMarkup(Client $client, string $redirectUri, array $requestedScopes, string $state, string $csrfToken, string $displayName): string
    {
        $scopeLabels = $this->scopes->all();
        $scopeItems = implode('', array_map(function ($scope) use ($scopeLabels) {
            $label = $scopeLabels[$scope] ?? $scope;

            return '<li><strong>'.$this->escape($scope).'</strong><span>'.$this->escape($label).'</span></li>';
        }, $requestedScopes));

        $hidden = [
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $requestedScopes),
            'state' => $state,
            'csrfToken' => $csrfToken,
        ];

        $hiddenInputs = implode('', array_map(function ($name, $value) {
            return '<input type="hidden" name="'.$this->escape($name).'" value="'.$this->escape($value).'">';
        }, array_keys($hidden), $hidden));

        $logo = $client->icon_url ? '<img src="'.$this->escape($client->icon_url).'" alt="">' : '<div class="oc-icon">OAuth</div>';
        $description = $client->description ? '<p>'.$this->escape($client->description).'</p>' : '';
        $homepage = $client->homepage_url ? '<a href="'.$this->escape($client->homepage_url).'" rel="noopener noreferrer" target="_blank">'.$this->escape($client->homepage_url).'</a>' : '';
        $clientName = $this->escape($client->name);
        $signedInAs = $this->escape($this->trans('forum.authorize.signed_in_as', [
            'user' => $displayName,
        ], 'Signed in as '.$displayName.'. This application is requesting access to your account.'));
        $approve = $this->escape($this->trans('forum.authorize.approve', [], 'Authorize'));
        $deny = $this->escape($this->trans('forum.authorize.deny', [], 'Deny'));
        $action = $this->escape($this->baseUrl().'/oauth2/authorize');

        return <<<HTML
<section class="oc-card">
  <div class="oc-client">
    {$logo}
    <div>
      <h1>{$clientName}</h1>
      {$description}
      {$homepage}
    </div>
  </div>
  <p class="oc-muted">{$signedInAs}</p>
  <ul class="oc-scopes">{$scopeItems}</ul>
  <form method="post" action="{$action}">
    {$hiddenInputs}
    <div class="oc-actions">
      <button class="oc-button oc-primary" type="submit" name="decision" value="approve">{$approve}</button>
      <button class="oc-button" type="submit" name="decision" value="deny">{$deny}</button>
    </div>
  </form>
</section>
HTML;
    }

    private function loginMarkup(ServerRequestInterface $request): string
    {
        $currentUrl = (string) $request->getUri();
        $base = $this->escape($this->baseUrl());
        $title = $this->escape($this->trans('forum.login.title', [], 'Login required'));
        $message = $this->escape($this->trans('forum.login.message', [], 'Sign in to this forum first, then open the authorization request again.'));
        $openForum = $this->escape($this->trans('forum.login.open_forum', [], 'Open forum'));
        $returnUrl = $this->escape($this->trans('forum.login.return_url', [
            'url' => $currentUrl,
        ], 'Return URL: '.$currentUrl));

        return <<<HTML
<section class="oc-card">
  <h1>{$title}</h1>
  <p class="oc-muted">{$message}</p>
  <a class="oc-button oc-primary" href="{$base}">{$openForum}</a>
  <p class="oc-footnote">{$returnUrl}</p>
</section>
HTML;
    }

    private function errorMarkup(string $message): string
    {
        $message = $this->escape($message);
        $title = $this->escape($this->trans('forum.error.invalid_request', [], 'Invalid OAuth request'));

        return <<<HTML
<section class="oc-card">
  <h1>{$title}</h1>
  <p class="oc-error">{$message}</p>
</section>
HTML;
    }

    private function page(string $title, string $content): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->escape($title).'</title>'.$this->styles().'</head><body>'.$content.'</body></html>';
    }

    private function styles(): string
    {
        return <<<'HTML'
<style>
body{margin:0;background:#f5f7fb;color:#1f2937;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.oc-card{width:min(560px,calc(100vw - 32px));margin:8vh auto;background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:28px;box-shadow:0 10px 35px rgba(15,23,42,.08)}
.oc-client{display:flex;gap:16px;align-items:center;margin-bottom:18px}
.oc-client img,.oc-icon{width:56px;height:56px;border-radius:8px;object-fit:cover;background:#2563eb;color:#fff;display:grid;place-items:center;font-weight:700}
h1{font-size:24px;line-height:1.25;margin:0 0 6px}
p{margin:0 0 12px}
a{color:#2563eb}
.oc-muted{color:#596579}
.oc-scopes{list-style:none;margin:18px 0;padding:0;border:1px solid #e5e9f0;border-radius:8px;overflow:hidden}
.oc-scopes li{padding:12px 14px;border-top:1px solid #e5e9f0}
.oc-scopes li:first-child{border-top:0}
.oc-scopes strong{display:block}
.oc-scopes span{color:#596579}
.oc-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
.oc-button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 16px;border:1px solid #ccd4df;border-radius:6px;background:#fff;color:#1f2937;text-decoration:none;cursor:pointer;font:inherit}
.oc-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.oc-error{color:#b42318}
.oc-footnote{margin-top:18px;color:#6b7280;font-size:12px;word-break:break-all}
</style>
HTML;
    }

    private function baseUrl(): string
    {
        return rtrim($this->app->url(), '/');
    }

    private function trans(string $key, array $params = [], string $fallback = ''): string
    {
        return $this->translation->trans($key, $params, $fallback);
    }

    private function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
