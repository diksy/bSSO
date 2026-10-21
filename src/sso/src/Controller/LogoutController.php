<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\TokenIssuer;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;
use App\Repository\Record\SsoSession;
use App\Repository\SessionClientRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Security\KeyManager;
use App\Session\LogoutService;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\View;

/**
 * RP-initiated logout. Tujuan redirect HANYA dari baris client_redirect_uris(kind=post_logout) milik klien yang
 * dibuktikan id_token_hint (exact match); tanpa hint valid tidak pernah ada redirect (anti open redirect).
 */
final class LogoutController
{
    public function __construct(
        private readonly SsoSessionManager $sessions,
        private readonly SsoSessionRepository $sessionRepo,
        private readonly SessionClientRepository $sessionClients,
        private readonly UserRepository $users,
        private readonly ClientRepository $clients,
        private readonly ClientRedirectUriRepository $uris,
        private readonly KeyManager $keys,
        private readonly TokenIssuer $issuer,
        private readonly LogoutService $logout,
        private readonly Audit $audit,
        private readonly View $view,
    ) {
    }

    public function confirm(Request $request): Response
    {
        $hint = $this->verifyHint((string) $request->input('id_token_hint', ''), $request->input('client_id'));
        if ($hint !== null) {
            return $this->rpInitiated($request, $hint['session'], $hint['client']);
        }

        // Tanpa hint valid: tanya konfirmasi bila ada sesi di browser ini; tanpa redirect apa pun.
        $current = $this->sessions->current($request);
        if ($current === null) {
            return $this->loggedOut();
        }
        $user = $this->users->findById($current->userId);

        return $this->view->render('auth/logout_confirm', ['title' => 'Keluar', 'user' => $user !== null], 'auth')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Logout oleh pengguna dari browser (form konfirmasi, dilindungi CSRF). */
    public function submit(Request $request): Response
    {
        $current = $this->sessions->current($request);
        if ($current !== null) {
            $this->logout->deferDispatch();
            $this->logout->endSession($current->sid, 'user_logout');
        }

        return Response::redirect('/logged-out', 303)->withCookie($this->sessions->clearCookie());
    }

    public function done(Request $request): Response
    {
        return $this->loggedOut();
    }

    private function rpInitiated(Request $request, SsoSession $session, Client $client): Response
    {
        $redirect = $this->postLogoutTarget($request, $client);
        $this->logout->deferDispatch();
        $this->logout->endSession($session->sid, 'rp_logout');
        $this->audit->record('logout.rp_initiated', $session->userId, $session->userId, $client->id);

        $response = $redirect !== null ? Response::redirect($redirect, 302)->withHeader('Cache-Control', 'no-store') : $this->loggedOut();
        $current = $this->sessions->current($request);
        // Cookie dihapus bila sesi browser ini adalah sesi yang diakhiri.
        if ($current === null || $current->sid === $session->sid) {
            $response = $response->withCookie($this->sessions->clearCookie());
        }

        return $response;
    }

    /** Exact match (byte per byte) terhadap post_logout milik klien; state ditambahkan di akhir. */
    private function postLogoutTarget(Request $request, Client $client): ?string
    {
        $uri = $request->input('post_logout_redirect_uri');
        if ($uri === null || $uri === '' || strlen($uri) > 2000 || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            return null;
        }
        if (!$this->uris->exists($client->id, $uri, 'post_logout')) {
            return null;
        }
        $state = $request->input('state');
        if ($state === null || $state === '') {
            return $uri;
        }
        if (strlen($state) > 512 || preg_match('/[\x00-\x1f\x7f]/', $state) === 1) {
            return $uri;
        }
        $fragment = strpos($uri, '#');
        $base = $fragment === false ? $uri : substr($uri, 0, $fragment);
        $tail = $fragment === false ? '' : substr($uri, $fragment);

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(['state' => $state], '', '&', PHP_QUERY_RFC3986) . $tail;
    }

    /**
     * id_token_hint: RS256 + kid (KeyManager::verify), typ JWT (bukan at+jwt/logout+jwt), iss kita, exp boleh lewat.
     * Sesi harus ada, sub cocok, dan klien (aud) pernah login di sesi itu.
     *
     * @return ?array{session: SsoSession, client: Client}
     */
    private function verifyHint(string $jwt, ?string $clientIdParam): ?array
    {
        if ($jwt === '' || strlen($jwt) > 8192) {
            return null;
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($header) || ($header['typ'] ?? 'JWT') !== 'JWT') {
            return null;
        }
        try {
            $claims = $this->keys->verify($jwt, true);
        } catch (\Throwable) {
            return null;
        }
        $aud = $claims['aud'] ?? null;
        $aud = is_array($aud) && count($aud) === 1 ? $aud[0] : $aud;
        $sid = $claims['sid'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (
            ($claims['iss'] ?? null) !== $this->issuer->issuer() || !is_string($aud) || !is_string($sid) || !is_string($sub)
            || ($clientIdParam !== null && $clientIdParam !== '' && !hash_equals($aud, $clientIdParam))
        ) {
            return null;
        }
        $client = $this->clients->findByClientId($aud);
        $session = $this->sessionRepo->findBySid($sid);
        $user = $session === null ? null : $this->users->findById($session->userId);
        if ($client === null || !$client->isEnabled || $session === null || $user === null || !hash_equals($user->uuid, $sub)) {
            return null;
        }
        $known = false;
        foreach ($this->sessionClients->listForSession($sid) as $sc) {
            $known = $known || $sc->clientId === $client->id;
        }

        return $known ? ['session' => $session, 'client' => $client] : null;
    }

    private function loggedOut(): Response
    {
        return $this->view->render('auth/logged_out', ['title' => 'Anda sudah keluar'], 'auth');
    }
}
