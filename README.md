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

## Installation

Install from Packagist after this package has been submitted:

```sh
composer require iseekup/oauth-connect:^0.1
php flarum extension:enable iseekup-oauth-connect
php flarum migrate
php flarum cache:clear
php flarum assets:publish
```

If you install before the package is available on Packagist, add the GitHub repository first:

```sh
composer config repositories.gungun88-oauth-connect vcs https://github.com/gungun88/oauth-connect.git
composer require iseekup/oauth-connect:^0.1
php flarum extension:enable iseekup-oauth-connect
php flarum migrate
php flarum cache:clear
php flarum assets:publish
```

For Docker Compose deployments, run the same commands inside the Flarum service:

```sh
docker compose run --rm flarum composer require iseekup/oauth-connect:^0.1
docker compose run --rm flarum php flarum extension:enable iseekup-oauth-connect
docker compose run --rm flarum php flarum migrate
docker compose run --rm flarum php flarum cache:clear
docker compose run --rm flarum php flarum assets:publish
```

Update an existing installation:

```sh
composer update iseekup/oauth-connect --with-dependencies
php flarum migrate
php flarum cache:clear
php flarum assets:publish
```

## Development installation

For local development, add this repository as a path repository in a Flarum installation and require the same package name:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/iseekup/oauth-connect",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then run:

```sh
composer require iseekup/oauth-connect:*@dev
php flarum extension:enable iseekup-oauth-connect
php flarum migrate
php flarum cache:clear
```

## Security notes

- Redirect URI matching is exact.
- `state` is required by default.
- Authorization codes are one-time use.
- Access tokens expire after 2 hours by default.
- Refresh tokens expire after 30 days by default and rotate on use.
- Bearer token authentication is only applied to `/api/oauth/user` and `/api/user`.
