<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Flarum\Foundation\Application;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
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
    private $settings;
    private $translation;

    public function __construct(
        OAuthFlow $flow,
        ScopeRegistry $scopes,
        Application $app,
        SettingsRepositoryInterface $settings,
        Translation $translation
    ) {
        $this->flow = $flow;
        $this->scopes = $scopes;
        $this->app = $app;
        $this->settings = $settings;
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

            return '<li class="oc-permission"><span class="oc-permission-icon"></span><span><strong>'.$this->escape($label).'</strong></span></li>';
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

        $description = $client->description ? '<p class="oc-description">'.$this->escape($client->description).'</p>' : '';
        $homepage = $client->homepage_url ? '<a href="'.$this->escape($client->homepage_url).'" rel="noopener noreferrer" target="_blank">'.$this->escape($client->homepage_url).'</a>' : '-';
        $clientName = $this->escape($client->name);
        $rawDisplayName = $displayName;
        $displayName = $this->escape($rawDisplayName);
        $accountInitials = $this->escape($this->initials($rawDisplayName));
        $forumTitle = $this->settings->get('forum_title', 'this forum');
        $subtitle = $this->escape($this->trans('forum.authorize.subtitle', [
            'forum' => $forumTitle,
        ], 'Requesting access to your '.$forumTitle.' account'));
        $accountCaption = $this->escape($this->trans('forum.authorize.account_caption', [
            'user' => $rawDisplayName,
        ], 'Authorize as '.$rawDisplayName));
        $appInfoTitle = $this->escape($this->trans('forum.authorize.app_info_title', [], 'Application info'));
        $homepageLabel = $this->escape($this->trans('forum.authorize.homepage_label', [], 'Homepage'));
        $applicationLabel = $this->escape($this->trans('forum.authorize.application_label', [], 'Application'));
        $permissionsTitle = $this->escape($this->trans('forum.authorize.permissions_title', [], 'This application will be able to'));
        $approve = $this->escape($this->trans('forum.authorize.approve', [], 'Authorize'));
        $deny = $this->escape($this->trans('forum.authorize.deny', [], 'Deny'));
        $action = $this->escape($this->baseUrl().'/oauth2/authorize');

        return <<<HTML
<main class="oc-page">
  <section class="oc-shell">
    <div class="oc-hero-icon"><span class="oc-lock"></span></div>
    <h1 class="oc-title">{$clientName}</h1>
    <p class="oc-subtitle">{$subtitle}</p>

    <div class="oc-account-card">
      <div class="oc-avatar">{$accountInitials}</div>
      <div>
        <strong>{$displayName}</strong>
        <span>{$accountCaption}</span>
      </div>
    </div>

    <section class="oc-info-card">
      <h2>{$appInfoTitle}</h2>
      <div class="oc-info-row">
        <span>{$homepageLabel}</span>
        <div>{$homepage}</div>
      </div>
      <div class="oc-info-row">
        <span>{$applicationLabel}</span>
        <div>{$clientName}</div>
      </div>
      {$description}
    </section>

    <section class="oc-info-card">
      <h2>{$permissionsTitle}</h2>
      <ul class="oc-permissions">{$scopeItems}</ul>
    </section>

    <form class="oc-action-form" method="post" action="{$action}">
      {$hiddenInputs}
      <button class="oc-button oc-primary" type="submit" name="decision" value="approve">{$approve}</button>
      <button class="oc-button oc-secondary" type="submit" name="decision" value="deny">{$deny}</button>
    </form>
  </section>
</main>
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
<main class="oc-page">
  <section class="oc-shell oc-shell-simple">
    <div class="oc-hero-icon"><span class="oc-lock"></span></div>
    <h1 class="oc-title">{$title}</h1>
    <p class="oc-subtitle">{$message}</p>
    <a class="oc-button oc-primary" href="{$base}">{$openForum}</a>
    <p class="oc-footnote">{$returnUrl}</p>
  </section>
</main>
HTML;
    }

    private function errorMarkup(string $message): string
    {
        $message = $this->escape($message);
        $title = $this->escape($this->trans('forum.error.invalid_request', [], 'Invalid OAuth request'));

        return <<<HTML
<main class="oc-page">
  <section class="oc-shell oc-shell-simple">
    <div class="oc-hero-icon oc-hero-error"></div>
    <h1 class="oc-title">{$title}</h1>
    <p class="oc-error">{$message}</p>
  </section>
</main>
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
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:#f7f7f6;color:#171717;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
body:before{content:"";position:fixed;inset:0;z-index:-1;background-image:linear-gradient(#e7e7e3 1px,transparent 1px),linear-gradient(90deg,#e7e7e3 1px,transparent 1px);background-size:28px 28px;opacity:.75}
a{color:#0284c7;text-decoration:none}
a:hover{text-decoration:underline}
.oc-page{min-height:100vh;display:flex;justify-content:center;padding:56px 18px}
.oc-shell{width:min(520px,100%);display:flex;flex-direction:column;align-items:stretch}
.oc-shell-simple{text-align:center}
.oc-hero-icon{width:88px;height:88px;margin:0 auto 18px;border:1px solid #e3e3df;border-radius:18px;background:#eeeeec;display:grid;place-items:center}
.oc-hero-error:before{content:"";width:34px;height:34px;border-radius:50%;border:4px solid #b42318}
.oc-lock,.oc-lock:before,.oc-lock:after{box-sizing:border-box}
.oc-lock{position:relative;width:38px;height:42px;display:block}
.oc-lock:before{content:"";position:absolute;left:8px;top:0;width:22px;height:22px;border:4px solid #555;border-bottom:0;border-radius:14px 14px 0 0}
.oc-lock:after{content:"";position:absolute;left:2px;bottom:0;width:34px;height:28px;border:4px solid #555;border-radius:7px}
.oc-title{margin:0;color:#121212;text-align:center;font-size:30px;line-height:1.15;font-weight:800;letter-spacing:0}
.oc-subtitle{margin:10px 0 28px;color:#858585;text-align:center;font-size:15px}
.oc-account-card,.oc-info-card{width:100%;border:1px solid #deded9;border-radius:8px;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.02)}
.oc-account-card{display:flex;gap:14px;align-items:center;margin-bottom:22px;padding:18px 20px}
.oc-avatar{width:38px;height:38px;border-radius:50%;background:#7d9a76;color:#fff;display:grid;place-items:center;font-weight:800;text-transform:uppercase}
.oc-account-card strong{display:block;color:#272727;font-size:15px}
.oc-account-card span{display:block;color:#878787;font-size:13px}
.oc-info-card{margin-bottom:22px;padding:18px 20px}
.oc-info-card h2{margin:0 0 14px;color:#8a8a8a;font-size:13px;font-weight:700}
.oc-info-row{display:grid;grid-template-columns:92px minmax(0,1fr);gap:12px;margin:8px 0;color:#6e6e6e}
.oc-info-row>span{color:#9a9a9a}
.oc-info-row div{min-width:0;overflow-wrap:anywhere}
.oc-description{margin:12px 0 0;color:#6e6e6e}
.oc-permissions{list-style:none;margin:0;padding:0}
.oc-permission{display:flex;gap:12px;align-items:center;padding:12px 0;border-top:1px solid #eeeeeb}
.oc-permission:first-child{border-top:0;padding-top:2px}
.oc-permission-icon{position:relative;width:24px;height:24px;flex:0 0 24px}
.oc-permission-icon:before{content:"";position:absolute;left:8px;top:3px;width:8px;height:8px;border:2px solid #555;border-radius:50%}
.oc-permission-icon:after{content:"";position:absolute;left:4px;bottom:2px;width:16px;height:8px;border:2px solid #555;border-radius:8px 8px 3px 3px}
.oc-permission strong{display:block;color:#333;font-weight:700}
.oc-action-form{display:flex;flex-direction:column;gap:10px}
.oc-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:48px;padding:0 18px;border-radius:24px;text-decoration:none;cursor:pointer;font:inherit;font-weight:700}
.oc-primary{border:1px solid #111;background:#111;color:#fff}
.oc-secondary{border:1px solid #d4d4d0;background:rgba(255,255,255,.55);color:#333}
.oc-error{margin:8px 0 0;color:#b42318;text-align:center}
.oc-footnote{margin:16px 0 0;color:#8a8a8a;font-size:12px;word-break:break-all}
@media (max-width:560px){.oc-page{padding:32px 14px}.oc-title{font-size:26px}.oc-info-row{grid-template-columns:1fr;gap:2px}.oc-account-card,.oc-info-card{padding:16px}.oc-hero-icon{width:78px;height:78px}}
</style>
HTML;
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $initials .= mb_substr($part, 0, 1);

            if (mb_strlen($initials, 'UTF-8') >= 2) {
                break;
            }
        }

        return mb_strtoupper($initials ?: 'O', 'UTF-8');
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
