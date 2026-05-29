# OAuth Connect

OAuth Connect turns a Flarum forum into an OAuth2 authorization server.

This is a first-version provider extension for `iseekup/oauth-connect`. It is designed as a reusable Flarum extension, so another Flarum forum can install the same package and become its own OAuth2 authorization center.

## Supported flows

- Authorization Code grant
- Refresh Token grant with refresh token rotation
- Bearer token UserInfo endpoint

Not included in this version:

- OpenID Connect ID tokens
- PKCE
- Implicit grant
- Password grant
- Client credentials grant
- Dynamic client registration

## Endpoints

Use the forum base URL for OAuth endpoints:

- Authorization: `/oauth2/authorize`
- Token: `/oauth2/token`

Use the API base URL for UserInfo endpoints:

- UserInfo: `/api/oauth/user`
- UserInfo alias: `/api/user`

## Scopes

- `user.read`: basic profile
- `user.email`: email address and email confirmation state
- `user.stats`: joined date, last seen date, discussion count, comment count
- `user.moderation`: suspended/silenced state
- `user.trust`: trust level when the local forum exposes that user attribute

`user.read` is always included.

## Admin

After enabling the extension, open the Flarum admin panel and go to the OAuth Connect extension page. From there an administrator can:

- create OAuth2 clients
- edit redirect URIs, allowed scopes, and enabled status
- reset client secrets
- revoke client authorizations
- delete clients

Client secrets are stored with `password_hash()` and only shown once when created or reset.

## Installation in this repository

The package is installed as a Composer path repository:

```sh
composer update iseekup/oauth-connect --with-dependencies
php flarum extension:enable iseekup-oauth-connect
php flarum migrate
php flarum cache:clear
```

## Installation in another Flarum forum

Publish this directory as a normal Composer package named `iseekup/oauth-connect`, then install it in the target forum:

```sh
composer require iseekup/oauth-connect
php flarum extension:enable iseekup-oauth-connect
php flarum migrate
php flarum cache:clear
```

For private distribution before publishing, add a Composer VCS or path repository in the target forum's `composer.json`, then require the package with the same name.

## Security notes

- Redirect URI matching is exact.
- `state` is required by default.
- Authorization codes are one-time use.
- Access tokens expire after 2 hours by default.
- Refresh tokens expire after 30 days by default and rotate on use.
- Bearer token authentication is only applied to `/api/oauth/user` and `/api/user`.

