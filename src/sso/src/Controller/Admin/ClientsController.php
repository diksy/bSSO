<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\ClientForm;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\OAuth\ClientCreator;
use App\OAuth\TokenIssuer;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;
use App\Security\PasswordHasher;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Flash;
use App\Support\HttpClient;
use App\Support\UiSession;

/**
 * Aplikasi klien (F8). Secret: hanya hash di DB, tampil sekali lewat UiSession['one_time_secret'], tidak masuk audit/log.
 * Otorisasi lewat middleware rute (clients:read|manage, step-up untuk store/update/regenerate-secret).
 */
final class ClientsController
{
    public function __construct(
        private readonly AdminPage $page,
        private readonly ClientRepository $clients,
        private readonly ClientRedirectUriRepository $uris,
        private readonly ClientCreator $creator,
        private readonly ClientForm $form,
        private readonly PasswordHasher $hasher,
        private readonly TokenIssuer $issuer,
        private readonly HttpClient $http,
        private readonly Database $db,
        private readonly Audit $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $rows = [];
        foreach ($this->clients->listAll() as $c) {
            $rows[] = ['id' => $c->clientId, 'name' => $c->name, 'type' => $c->type, 'grants' => $c->allowedGrants, 'active' => $c->isEnabled];
        }

        return $this->page->render($request, 'admin/clients', 'Aplikasi klien', 'clients', ['clients' => $rows]);
    }

    public function create(Request $request): Response
    {
        return $this->page->render($request, 'admin/client_form', 'Tambah klien', 'clients', ['c' => null]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->page->actor($request);
        try {
            $in = $this->form->parse($request->body);
            $result = $this->creator->create(
                $in['name'],
                $in['type'],
                $in['login'],
                $in['logout'],
                $in['grants'],
                $in['scopes'],
                $in['backchannel'],
                $in['accessTtl'],
                $in['refreshTtl'],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->page->render($request, 'admin/client_form', 'Tambah klien', 'clients', ['c' => $this->echo($request->body), 'error' => $e->getMessage()], 422);
        }
        $client = $result['client'];
        $this->audit->record('client.create', $actor->id, null, $client->id, ['after' => $this->snapshot($client, $in['login'], $in['logout'])]);
        if ($result['secret'] !== null) {
            UiSession::set('one_time_secret', ['for' => $client->name, 'secret' => $result['secret']]);
        }
        Flash::add('ok', 'Klien dibuat. Client ID: ' . $client->clientId);

        return Response::redirect('/admin/clients/' . rawurlencode($client->clientId), 303);
    }

    public function edit(Request $request): Response
    {
        $client = $this->find($request);

        return $this->page->render($request, 'admin/client_form', 'Klien: ' . $client->name, 'clients', ['c' => $this->view($client)]);
    }

    public function update(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $client = $this->find($request);
        try {
            $in = $this->form->parse($request->body, $client->type);
        } catch (\InvalidArgumentException $e) {
            return $this->page->render(
                $request,
                'admin/client_form',
                'Klien: ' . $client->name,
                'clients',
                ['c' => array_merge($this->echo($request->body), ['id' => $client->clientId, 'type' => $client->type, 'active' => $client->isEnabled]), 'error' => $e->getMessage()],
                422,
            );
        }
        $before = $this->snapshot($client, $this->uriList($client, 'login'), $this->uriList($client, 'post_logout'));
        $this->db->transaction(function () use ($client, $in): void {
            $this->clients->update($client->id, $in['name'], $in['grants'], $in['scopes'], $in['accessTtl'], $in['refreshTtl'], $in['backchannel']);
            $this->uris->replace($client->id, 'login', $in['login']);
            $this->uris->replace($client->id, 'post_logout', $in['logout']);
        });
        $after = $before;
        $after['name'] = $in['name'];
        $after['grants'] = $in['grants'];
        $after['scopes'] = $in['scopes'];
        $after['access_ttl'] = $in['accessTtl'];
        $after['refresh_ttl'] = $in['refreshTtl'];
        $after['backchannel'] = $in['backchannel'];
        $after['redirect_login'] = $in['login'];
        $after['redirect_logout'] = $in['logout'];
        $this->audit->record('client.update', $actor->id, null, $client->id, ['before' => $before, 'after' => $after]);
        Flash::add('ok', 'Klien disimpan.');

        return Response::redirect('/admin/clients/' . rawurlencode($client->clientId), 303);
    }

    public function toggle(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $client = $this->find($request);
        $this->clients->setEnabled($client->id, !$client->isEnabled);
        $this->audit->record('client.toggle', $actor->id, null, $client->id, [
            'before' => ['enabled' => $client->isEnabled], 'after' => ['enabled' => !$client->isEnabled],
        ]);
        Flash::add('ok', $client->isEnabled ? 'Klien dinonaktifkan.' : 'Klien diaktifkan.');

        return Response::redirect($this->page->back($request, '/admin/clients'), 303);
    }

    public function regenerateSecret(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $client = $this->find($request);
        $back = '/admin/clients/' . rawurlencode($client->clientId);
        if ($client->type !== 'confidential') {
            Flash::add('err', 'Klien public tidak punya secret.');

            return Response::redirect($back, 303);
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->clients->setSecretHash($client->id, $this->hasher->hash($secret));
        $this->audit->record('client.secret_regenerated', $actor->id, null, $client->id, ['before' => ['secret_set' => true], 'after' => ['secret_set' => true]]);
        UiSession::set('one_time_secret', ['for' => $client->name, 'secret' => $secret]);
        Flash::add('ok', 'Secret baru dibuat; secret lama tidak berlaku lagi.');

        return Response::redirect($back, 303);
    }

    /** Kirim logout token uji (sid acak, sub = admin) ke backchannel_logout_uri lewat HttpClient (allowlist). */
    public function testLogout(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $client = $this->find($request);
        $back = '/admin/clients/' . rawurlencode($client->clientId);
        if ($client->backchannelLogoutUri === null || $client->backchannelLogoutUri === '') {
            Flash::add('err', 'Klien belum punya back-channel logout URI.');

            return Response::redirect($back, 303);
        }
        $token = $this->issuer->logoutToken($client, $actor->uuid, 'test-' . bin2hex(random_bytes(8)));
        $res = $this->http->request(
            'POST',
            $client->backchannelLogoutUri,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cache-Control' => 'no-store'],
            http_build_query(['logout_token' => $token]),
        );
        $ok = $res->error === null && in_array($res->status, [200, 204], true);
        $this->audit->record('client.test_logout', $actor->id, null, $client->id, ['status' => $res->error ?? $res->status]);
        Flash::add($ok ? 'ok' : 'err', $ok ? 'Logout token uji diterima klien (HTTP ' . $res->status . ').' : 'Pengiriman gagal: ' . ($res->error ?? 'HTTP ' . $res->status));

        return Response::redirect($back, 303);
    }

    private function find(Request $request): Client
    {
        $id = (string) $request->attribute('id');
        $client = $id !== '' && strlen($id) <= 80 ? $this->clients->findByClientId($id) : null;
        if ($client === null) {
            throw new HttpException(404);
        }

        return $client;
    }

    /** @return array<string,mixed> */
    private function view(Client $c): array
    {
        return [
            'id' => $c->clientId, 'name' => $c->name, 'type' => $c->type, 'grants' => $c->allowedGrants,
            'scopes' => preg_split('/\s+/', $c->allowedScopes, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'access_ttl' => $c->accessTokenTtl, 'refresh_ttl' => $c->refreshTokenTtl,
            'redirect_login' => $this->uriList($c, 'login'), 'redirect_logout' => $this->uriList($c, 'post_logout'),
            'backchannel' => $c->backchannelLogoutUri ?? '', 'active' => $c->isEnabled,
        ];
    }

    /** @return list<string> */
    private function uriList(Client $c, string $kind): array
    {
        return array_map(static fn ($u): string => $u->uri, $this->uris->listForClient($c->id, $kind));
    }

    /**
     * @param list<string> $login
     * @param list<string> $logout
     * @return array<string,mixed> tanpa secret
     */
    private function snapshot(Client $c, array $login, array $logout): array
    {
        return [
            'client_id' => $c->clientId, 'name' => $c->name, 'type' => $c->type, 'grants' => $c->allowedGrants,
            'scopes' => $c->allowedScopes, 'access_ttl' => $c->accessTokenTtl, 'refresh_ttl' => $c->refreshTokenTtl,
            'backchannel' => $c->backchannelLogoutUri, 'redirect_login' => $login, 'redirect_logout' => $logout,
        ];
    }

    /**
     * Isian form untuk ditampilkan ulang saat validasi gagal.
     *
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function echo(array $b): array
    {
        $lines = static fn (mixed $v): array => preg_split('/\R/', is_string($v) ? $v : '', -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'name' => is_string($b['name'] ?? null) ? $b['name'] : '', 'type' => is_string($b['type'] ?? null) ? $b['type'] : 'confidential',
            'grants' => array_values(array_filter(is_array($b['grants'] ?? null) ? $b['grants'] : [], 'is_string')),
            'scopes' => $lines(str_replace(' ', "\n", is_string($b['scopes'] ?? null) ? $b['scopes'] : '')),
            'access_ttl' => is_string($b['access_ttl'] ?? null) ? $b['access_ttl'] : '900',
            'refresh_ttl' => is_string($b['refresh_ttl'] ?? null) ? $b['refresh_ttl'] : '2592000',
            'redirect_login' => $lines($b['redirect_login'] ?? ''), 'redirect_logout' => $lines($b['redirect_logout'] ?? ''),
            'backchannel' => is_string($b['backchannel'] ?? null) ? $b['backchannel'] : '', 'active' => true, 'id' => '',
        ];
    }
}
