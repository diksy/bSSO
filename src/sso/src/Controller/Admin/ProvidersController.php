<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\ProviderConfig;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\IdentityProviderRepository;
use App\Repository\Record\IdentityProvider;
use App\Security\Crypto;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Flash;
use App\Support\HttpClient;
use App\Support\UrlAllowlist;
use App\Support\Urls;

/**
 * Provider eksternal (F8). Secret/.p8 write-only: terenkripsi (Crypto) di client_secret_enc, tidak pernah dikirim ke
 * peramban, tidak masuk audit/log. Kunci .p8 Apple ditempel sebagai teks PEM (bisa diputar ulang oleh step-up).
 */
final class ProvidersController
{
    public function __construct(
        private readonly AdminPage $page,
        private readonly IdentityProviderRepository $providers,
        private readonly Crypto $crypto,
        private readonly HttpClient $http,
        private readonly UrlAllowlist $allowlist,
        private readonly Urls $urls,
        private readonly Database $db,
        private readonly Audit $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $rows = [];
        foreach ($this->providers->listAll() as $p) {
            $rows[] = ['slug' => $p->slug, 'order' => $p->sortOrder, 'name' => $p->displayName, 'basis' => strtoupper($p->protocol), 'active' => $p->isEnabled];
        }

        return $this->page->render($request, 'admin/providers', 'Provider', 'providers', ['providers' => $rows]);
    }

    public function edit(Request $request): Response
    {
        return $this->renderForm($request, $this->find($request));
    }

    public function update(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $p = $this->find($request);
        $b = $request->body;
        $str = static fn (string $k): string => is_string($b[$k] ?? null) ? $b[$k] : '';
        try {
            $clientId = trim($str('client_id'));
            if (strlen($clientId) > 255 || preg_match('/[\x00-\x1f\x7f]/', $clientId) === 1) {
                throw new \InvalidArgumentException('Client ID tidak valid (maks. 255 karakter).');
            }
            $order = trim($str('order'));
            if (preg_match('/^-?\d{1,4}$/', $order) !== 1) {
                throw new \InvalidArgumentException('Urutan harus bilangan bulat.');
            }
            $extra = ProviderConfig::parseExtra($p->slug, $str('extra'));
            $urls = [];
            if ($p->slug === 'facebook') {
                $extra['graph_version'] ??= ProviderConfig::DEFAULT_GRAPH_VERSION;
                $urls = ProviderConfig::facebookUrls((string) $extra['graph_version']);
            }
            $secretEnc = null;
            if ($p->slug === 'apple') {
                $pem = $str('p8_pem');
                if (trim($pem) !== '') {
                    $secretEnc = $this->crypto->encrypt(ProviderConfig::validateP8($pem));
                }
            } else {
                $secret = trim($str('secret'));
                if (strlen($secret) > 512 || preg_match('/[\x00-\x1f\x7f]/', $secret) === 1) {
                    throw new \InvalidArgumentException('Secret tidak valid (maks. 512 karakter, tanpa karakter kontrol).');
                }
                if ($secret !== '') {
                    $secretEnc = $this->crypto->encrypt($secret);
                }
            }
            $enabled = ($b['active'] ?? null) === '1';
            if ($enabled) {
                $this->assertCanEnable($p, $clientId, $secretEnc !== null || $p->clientSecretEnc !== null, $extra);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->renderForm($request, $p, $e->getMessage(), 422);
        }
        $trust = ($b['trust_email'] ?? null) === '1';
        $before = $this->snapshot($p);
        $this->db->transaction(function () use ($p, $clientId, $secretEnc, $trust, $extra, $enabled, $order, $urls): void {
            $this->providers->updateSettings($p->id, $clientId, $secretEnc, $trust, $extra, $enabled, (int) $order, $urls);
        });
        $after = [
            'client_id' => $clientId, 'enabled' => $enabled, 'sort_order' => (int) $order, 'trust_email' => $trust,
            'extra_config' => $extra, 'credential_changed' => $secretEnc !== null,
        ];
        $this->audit->record('provider.update', $actor->id, null, null, ['provider' => $p->slug, 'before' => $before, 'after' => $after]);
        Flash::add('ok', 'Provider disimpan.');

        return Response::redirect($this->page->back($request, '/admin/providers/' . rawurlencode($p->slug)), 303);
    }

    public function toggle(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $p = $this->find($request);
        $back = $this->page->back($request, '/admin/providers');
        if (!$p->isEnabled) {
            try {
                $this->assertCanEnable($p, $p->clientId, $p->clientSecretEnc !== null, $p->extraConfig);
            } catch (\InvalidArgumentException $e) {
                Flash::add('err', $e->getMessage());

                return Response::redirect($back, 303);
            }
        }
        $this->providers->setEnabled($p->id, !$p->isEnabled);
        $this->audit->record('provider.toggle', $actor->id, null, null, [
            'provider' => $p->slug, 'before' => ['enabled' => $p->isEnabled], 'after' => ['enabled' => !$p->isEnabled],
        ]);
        Flash::add('ok', $p->isEnabled ? 'Provider dinonaktifkan.' : 'Provider diaktifkan.');

        return Response::redirect($back, 303);
    }

    /** Ambil discovery + JWKS (OIDC) atau cek endpoint (OAuth2) hanya lewat HttpClient/UrlAllowlist; hasil = ringkasan, bukan isi respons. */
    public function testConnection(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $p = $this->find($request);
        $back = '/admin/providers/' . rawurlencode($p->slug);
        [$ok, $msg] = $p->protocol === 'oidc' ? $this->testOidc($p) : $this->testOauth2($p);
        $this->audit->record('provider.test_connection', $actor->id, null, null, ['provider' => $p->slug, 'ok' => $ok]);
        Flash::add($ok ? 'ok' : 'err', $msg);

        return Response::redirect($back, 303);
    }

    /** @return array{bool, string} */
    private function testOidc(IdentityProvider $p): array
    {
        if ($p->discoveryUrl === null) {
            return [false, 'discovery_url belum dikonfigurasi.'];
        }
        $res = $this->http->request('GET', $p->discoveryUrl, ['Accept' => 'application/json']);
        if ($res->error !== null || $res->status !== 200) {
            return [false, 'Discovery gagal: ' . ($res->error ?? 'HTTP ' . $res->status)];
        }
        $doc = json_decode($res->body, true);
        $jwksUri = is_array($doc) && is_string($doc['jwks_uri'] ?? null) ? $doc['jwks_uri'] : null;
        if (!is_array($doc) || !is_string($doc['issuer'] ?? null) || $jwksUri === null) {
            return [false, 'Dokumen discovery tidak memuat issuer/jwks_uri.'];
        }
        $jwks = $this->http->request('GET', $jwksUri, ['Accept' => 'application/json']);
        if ($jwks->error !== null || $jwks->status !== 200) {
            return [false, 'JWKS gagal: ' . ($jwks->error ?? 'HTTP ' . $jwks->status)];
        }
        $set = json_decode($jwks->body, true);
        $n = is_array($set) && is_array($set['keys'] ?? null) ? count($set['keys']) : 0;

        return $n > 0 ? [true, "Discovery OK; JWKS berisi $n kunci."] : [false, 'JWKS tidak berisi kunci.'];
    }

    /** @return array{bool, string} */
    private function testOauth2(IdentityProvider $p): array
    {
        foreach ([$p->authorizeUrl, $p->tokenUrl, $p->userinfoUrl] as $url) {
            if ($url === null || !$this->allowlist->isAllowed($url)) {
                return [false, 'Endpoint tidak dikonfigurasi atau ditolak allowlist (wajib https ke host publik).'];
            }
        }
        $res = $this->http->request('GET', (string) $p->authorizeUrl);
        if ($res->error !== null) {
            return [false, 'Endpoint otorisasi tidak terjangkau: ' . $res->error];
        }

        return [true, 'Endpoint otorisasi terjangkau (HTTP ' . $res->status . ').'];
    }

    /**
     * @param array<string,mixed> $extra
     * @throws \InvalidArgumentException
     */
    private function assertCanEnable(IdentityProvider $p, string $clientId, bool $hasSecret, array $extra): void
    {
        if ($clientId === '' || !$hasSecret) {
            throw new \InvalidArgumentException('Isi client ID dan ' . ($p->slug === 'apple' ? 'kunci .p8' : 'secret') . ' sebelum mengaktifkan provider.');
        }
        if ($p->slug === 'apple' && (!isset($extra['team_id']) || !isset($extra['key_id']))) {
            throw new \InvalidArgumentException('Apple memerlukan team_id dan key_id di extra_config.');
        }
    }

    private function find(Request $request): IdentityProvider
    {
        $slug = (string) $request->attribute('slug');
        $p = preg_match('/^[a-z0-9_-]{1,30}$/', $slug) === 1 ? $this->providers->findBySlug($slug) : null;
        if ($p === null) {
            throw new HttpException(404);
        }

        return $p;
    }

    /** @return array<string,mixed> */
    private function snapshot(IdentityProvider $p): array
    {
        return [
            'client_id' => $p->clientId, 'enabled' => $p->isEnabled, 'sort_order' => $p->sortOrder,
            'trust_email' => $p->trustEmail, 'extra_config' => $p->extraConfig, 'credential_changed' => false,
        ];
    }

    private function renderForm(Request $request, IdentityProvider $p, ?string $error = null, int $status = 200): Response
    {
        $b = $request->method === 'POST' ? $request->body : [];
        $str = static fn (string $k, string $d): string => is_string($b[$k] ?? null) ? $b[$k] : $d;
        $extraJson = $p->extraConfig === [] ? '' : (string) json_encode($p->extraConfig, JSON_UNESCAPED_SLASHES);
        $pr = [
            'slug' => $p->slug,
            'active' => $b === [] ? $p->isEnabled : ($b['active'] ?? null) === '1',
            'order' => $str('order', (string) $p->sortOrder),
            'client_id' => $str('client_id', $p->clientId),
            'secret_set' => $p->clientSecretEnc !== null,
            'trust_email' => $b === [] ? $p->trustEmail : ($b['trust_email'] ?? null) === '1',
            'extra' => $str('extra', $extraJson),
        ];
        $callback = $this->urls->available() ? $this->urls->abs('/federation/' . rawurlencode($p->slug) . '/callback') : '(base_url belum dikonfigurasi)';

        return $this->page->render($request, 'admin/provider_form', 'Provider: ' . $p->displayName, 'providers', [
            'pr' => $pr, 'callback' => $callback, 'error' => $error,
        ], $status);
    }
}
