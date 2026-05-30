<?php

namespace ISeekUp\OAuthConnect\Controllers;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use ISeekUp\OAuthConnect\Models\ClientAuthorization;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListAuthorizationsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $params = $request->getQueryParams();
        $pageParam = $params['page_number'] ?? ($params['number'] ?? null);

        if ($pageParam === null && isset($params['page']) && is_scalar($params['page'])) {
            $pageParam = $params['page'];
        }

        $page = max(1, (int) ($pageParam ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $query = ClientAuthorization::query()
            ->with(['user', 'client']);

        $clientId = trim((string) ($params['client_id'] ?? ''));
        if ($clientId !== '') {
            $query->where('client_id', $clientId);
        }

        $status = trim((string) ($params['status'] ?? ''));
        if ($status === 'active') {
            $query->whereNull('revoked_at');
        } elseif ($status === 'revoked') {
            $query->whereNotNull('revoked_at');
        }

        $search = trim((string) ($params['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                if (ctype_digit($search)) {
                    $query->orWhere('user_id', (int) $search);
                }

                $query->orWhereHas('user', function ($query) use ($search) {
                    $query->where('username', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            });
        }

        $total = (clone $query)->count();

        $authorizations = $query
            ->orderBy('authorized_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function (ClientAuthorization $authorization) {
                return [
                    'client_id' => $authorization->client_id,
                    'client_name' => $authorization->client ? $authorization->client->name : $authorization->client_id,
                    'user_id' => $authorization->user_id,
                    'username' => $authorization->user ? $authorization->user->username : null,
                    'display_name' => $authorization->user ? $authorization->user->display_name : null,
                    'scopes' => $authorization->scopeList(),
                    'authorized_at' => $this->date($authorization->authorized_at),
                    'revoked_at' => $this->date($authorization->revoked_at),
                ];
            })
            ->values()
            ->all();

        return new JsonResponse([
            'data' => $authorizations,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $limit)),
                'has_prev' => $page > 1,
                'has_next' => $offset + $limit < $total,
            ],
        ]);
    }

    private function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
