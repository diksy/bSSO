<?php

declare(strict_types=1);

namespace App\Controller;

use App\Federation\FederationException;
use App\Federation\IdentityLinkCompleter;
use App\Federation\IdentityLinker;
use App\Federation\LinkOutcome;
use App\Federation\NormalizedProfile;
use App\Federation\ProviderRegistry;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FederationStateRepository;
use App\Repository\IdentityProviderRepository;
use App\Repository\Record\IdentityProvider;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\SafePath;
use App\Support\UiSession;
use App\Support\Urls;
use App\Support\View;

/**
 * Federasi provider eksternal (F5). Keamanan utama: state acak 256-bit disimpan sebagai hash (10 menit, sekali pakai) dan
 * diikat ke browser lewat sesi UI; nonce + PKCE; URL hanya dari konfigurasi provider; user ber-MFA SELALU melewati /login/mfa.
 */
final class FederationController
{
    private const STATE_TTL = 600;
    private const FLOW_TTL = 600;
    private const EMAIL_TTL = 86400;
    private const MAX_BOUND = 5;
    private const KIND = '_kind';
    private const KIND_EMAIL = 'email_verify';
    private const GENERIC_FAIL = 'Masuk dengan penyedia eksternal gagal. Silakan coba lagi.';

    public function __construct(
        private readonly View $view,
        private readonly ProviderRegistry $registry,
        private readonly IdentityProviderRepository $providers,
        private readonly FederationStateRepository $states,
        private readonly IdentityLinker $linker,
        private readonly IdentityLinkCompleter $completer,
        private readonly UserRepository $users,
        private readonly SsoSessionManager $sessions,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly Mailer $mailer,
        private readonly Urls $urls,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function start(Request $request): Response
    {
        $slug = $this->slug($request);
        $config = $this->config($slug);
        if (!$this->limiter->hit('fedstart:' . $request->ip(), $request->ip(), 30)) {
            throw new HttpException(429);
        }

        $linkTo = null;
        if ($request->input('link') === '1') {
            $linkTo = $this->consumeLinkIntent($request, $slug);
            if ($linkTo === null) {
                Flash::add('err', 'Permintaan menautkan akun tidak valid. Mulai lagi dari halaman Akun tertaut.');

                return Response::redirect('/account/linked', 303);
            }
        }

        $state = bin2hex(random_bytes(32));
        $nonce = $config->protocol === 'oidc' ? bin2hex(random_bytes(16)) : null;
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        try {
            $url = $this->registry->get($slug)->authorizationUrl($state, $nonce, $challenge);
        } catch (FederationException $e) {
            $this->logger->error('Federasi start gagal: ' . $slug);

            return $this->fail($slug, 'start_failed', null, $linkTo !== null ? '/account/linked' : '/login');
        }

        $hash = hash('sha256', $state);
        $this->states->create(
            $hash,
            $config->id,
            $nonce,
            $verifier,
            ['return' => SafePath::clean($request->input('return'))],
            $linkTo,
            $this->clock->now()->modify('+' . self::STATE_TTL . ' seconds'),
        );
        // Pengikat browser: hash state hanya valid pada browser yang memulai alur (anti login-CSRF).
        $bound = array_values(array_filter((array) UiSession::get('fed_states', []), 'is_string'));
        $bound[] = $hash;
        UiSession::set('fed_states', array_slice($bound, -self::MAX_BOUND));

        return Response::redirect($url, 302)->withHeader('Cache-Control', 'no-store');
    }

    public function callback(Request $request): Response
    {
        $slug = $this->slug($request);
        $config = $this->config($slug);
        $stateParam = $request->input('state');
        if ($stateParam === null || preg_match('/^[0-9a-f]{64}$/', $stateParam) !== 1) {
            return $this->fail($slug, 'state_format');
        }
        $hash = hash('sha256', $stateParam);

        $bound = array_values(array_filter((array) UiSession::get('fed_states', []), 'is_string'));
        if (!in_array($hash, $bound, true)) {
            return $this->fail($slug, 'state_not_bound');
        }
        UiSession::set('fed_states', array_values(array_diff($bound, [$hash])));

        $state = $this->states->consume($hash); // sekali pakai: replay -> null
        if ($state === null) {
            return $this->fail($slug, 'state_expired_or_used');
        }
        $back = $state->linkToUserId !== null ? '/account/linked' : '/login';
        if ($state->providerId !== $config->id || isset($state->pendingRequest[self::KIND])) {
            return $this->fail($slug, 'state_mismatch', null, $back);
        }
        if ($state->linkToUserId !== null) {
            $current = $this->sessions->current($request);
            if ($current === null || $current->userId !== $state->linkToUserId) {
                return $this->fail($slug, 'link_session_mismatch', null, '/login');
            }
        }

        $params = [];
        foreach (['code', 'error'] as $key) {
            if (is_string($request->query[$key] ?? null)) {
                $params[$key] = $request->query[$key];
            }
        }

        try {
            $profile = $this->registry->get($slug)->handleCallback($params, $state);
            $result = $this->linker->resolve($profile, $config, $state);
        } catch (FederationException $e) {
            return $this->fail($slug, 'rejected', $state->linkToUserId, $back);
        }

        $next = SafePath::clean(is_string($state->pendingRequest['return'] ?? null) ? $state->pendingRequest['return'] : null);

        switch ($result->outcome) {
            case LinkOutcome::LinkedFromAccount:
                Flash::add('ok', $config->displayName . ' berhasil ditautkan.');

                return Response::redirect('/account/linked', 303);
            case LinkOutcome::Login:
            case LinkOutcome::AutoLinked:
            case LinkOutcome::Created:
                return $result->user === null ? $this->fail($slug, 'no_user') : $this->login($request, $result->user, $slug, $next, null);
            case LinkOutcome::NeedsConfirmation:
                if ($result->user === null) {
                    return $this->fail($slug, 'no_user');
                }
                UiSession::set('fed_confirm', $this->flow($config, $profile, $next, $result->user->id));
                $this->audit->record('federation.needs_confirmation', null, $result->user->id, null, ['provider' => $slug]);

                return Response::redirect('/federation/' . rawurlencode($slug) . '/confirm-link', 303);
            case LinkOutcome::NeedsEmail:
                UiSession::set('fed_email', $this->flow($config, $profile, $next, null));

                return Response::redirect('/federation/' . rawurlencode($slug) . '/complete-email', 303);
        }
    }

    /**
     * POST form_post (Apple): bebas CSRF middleware; slug non-form_post 405; simpan `user` via
     * FederationStateRepository::mergePending lalu 303 ke GET callback (state, code/error saja).
     */
    public function formPostCallback(Request $request): Response
    {
        $slug = $this->slug($request);
        $config = $this->config($slug);
        if (!$this->registry->usesFormPost($config)) {
            throw new HttpException(405);
        }
        $state = $request->body['state'] ?? null;
        if (!is_string($state) || preg_match('/^[0-9a-f]{64}$/', $state) !== 1) {
            throw new HttpException(400);
        }

        $user = $request->body['user'] ?? null;
        if (is_string($user) && $user !== '' && strlen($user) <= 4096) {
            $name = self::appleName($user);
            if ($name !== []) {
                // Data tak tepercaya: hanya nama tampilan, dan hanya ke state yang ada & belum kedaluwarsa.
                $this->states->mergePending(hash('sha256', $state), ['user' => ['name' => $name]]);
            }
        }

        $query = ['state' => $state];
        $code = $request->body['code'] ?? null;
        $error = $request->body['error'] ?? null;
        if (is_string($code) && $code !== '' && strlen($code) <= 2048) {
            $query['code'] = $code;
        } elseif (is_string($error) && preg_match('/^[a-z_]{1,40}$/', $error) === 1) {
            $query['error'] = $error;
        }

        return Response::redirect(
            '/federation/' . rawurlencode($slug) . '/callback?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            303,
        )->withHeader('Cache-Control', 'no-store');
    }

    public function confirmForm(Request $request): Response
    {
        $slug = $this->slug($request);
        $flow = $this->activeFlow('fed_confirm', $slug);
        $target = $flow === null ? null : $this->users->findById((int) $flow['user_id']);
        if ($flow === null || $target === null || $target->status !== 'active') {
            return Response::redirect('/login', 303);
        }
        if ($target->passwordHash === null) {
            Flash::add('warn', 'Akun dengan email ini dibuat lewat penyedia lain. Masuk dengan cara sebelumnya, lalu tautkan dari halaman Akun tertaut.');
            UiSession::forget('fed_confirm');

            return Response::redirect('/login', 303);
        }

        return $this->renderConfirm($this->config($slug), $target, null);
    }

    public function confirmSubmit(Request $request): Response
    {
        $slug = $this->slug($request);
        $config = $this->config($slug);
        $flow = $this->activeFlow('fed_confirm', $slug);
        $target = $flow === null ? null : $this->users->findById((int) $flow['user_id']);
        if ($flow === null || $target === null || $target->status !== 'active' || $target->passwordHash === null) {
            return Response::redirect('/login', 303);
        }
        $key = 'link:' . $target->id;
        $ip = $request->ip();
        if ($this->limiter->blocked($key) || ($target->email !== null && $this->limiter->loginBlocked($target->email, $ip))) {
            return $this->renderConfirm($config, $target, 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }
        $password = (string) $request->input('password', '');
        if (strlen($password) > 1024 || !$this->hasher->verify($password, $target->passwordHash)) {
            $this->limiter->record($key, $ip, false);
            $this->audit->record('federation.confirm_failure', null, $target->id, null, ['provider' => $slug]);

            return $this->renderConfirm($config, $target, 'Kata sandi salah.', 401);
        }
        $this->limiter->record($key, $ip, true);

        $profile = NormalizedProfile::fromArray((array) $flow['profile']);
        $next = SafePath::clean(is_string($flow['next'] ?? null) ? $flow['next'] : null);
        UiSession::forget('fed_confirm');

        // Pemilik terbukti lewat password. Bila MFA aktif, tautan baru ditulis SETELAH TOTP lulus (di MfaController).
        $link = ['provider_id' => $config->id, 'profile' => $profile->toArray()];
        if ($target->mfaEnabledAt !== null) {
            return $this->login($request, $target, $slug, $next, $link);
        }
        try {
            $this->completer->link($target->id, $config, $profile);
        } catch (FederationException) {
            return $this->fail($slug, 'confirm_rejected');
        }

        return $this->login($request, $target, $slug, $next, null);
    }

    public function emailForm(Request $request): Response
    {
        $slug = $this->slug($request);
        if ($this->activeFlow('fed_email', $slug) === null) {
            return Response::redirect('/login', 303);
        }

        return $this->renderEmail($this->config($slug), null);
    }

    public function emailSubmit(Request $request): Response
    {
        $slug = $this->slug($request);
        $config = $this->config($slug);
        $flow = $this->activeFlow('fed_email', $slug);
        if ($flow === null) {
            return Response::redirect('/login', 303);
        }
        $email = UserRepository::normalizeEmail((string) $request->input('email', ''));
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->renderEmail($config, 'Alamat email tidak valid.', 422);
        }
        $ip = $request->ip();
        if (
            !$this->limiter->hit('fedmail:' . $ip, $ip, 5)
            || !$this->limiter->hit('fedmail-to:' . $email, $ip, 3, '-1 hour')
        ) {
            return $this->renderEmail($config, 'Terlalu banyak percobaan. Coba lagi nanti.', 429);
        }

        $profile = NormalizedProfile::fromArray((array) $flow['profile']);
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            // Tanpa membocorkan keberadaan akun: respons sama; pemilik email diberi tahu lewat email.
            $this->send($email, 'Permintaan menautkan akun ' . $config->displayName, "Seseorang mencoba mendaftar dengan akun {$config->displayName} memakai email ini.\n\nJika itu Anda, masuk dengan cara biasa lalu tautkan akun dari halaman Akun tertaut. Jika bukan Anda, abaikan email ini.");
            $this->audit->record('federation.email_taken', null, $existing->id, null, ['provider' => $slug]);
        } else {
            $raw = json_encode($profile->raw);
            $pendingProfile = $profile->toArray();
            if ($raw === false || strlen($raw) > 8192) {
                $pendingProfile['raw'] = [];
            }
            $token = bin2hex(random_bytes(32));
            $this->states->create(
                hash('sha256', $token),
                $config->id,
                null,
                null,
                [self::KIND => self::KIND_EMAIL, 'email' => $email, 'profile' => $pendingProfile, 'return' => $flow['next']],
                null,
                $this->clock->now()->modify('+' . self::EMAIL_TTL . ' seconds'),
            );
            $link = $this->urls->abs('/federation/' . rawurlencode($slug) . '/verify-email?token=' . $token);
            $this->send($email, 'Verifikasi email BikininSSO', "Seseorang (mungkin Anda) masuk dengan akun {$config->displayName} dan memakai email ini. Buka tautan berikut hanya jika Anda memang memulai proses itu dengan akun {$config->displayName} milik Anda, untuk memverifikasi email dan mengaktifkan akun (berlaku 24 jam):\n\n" . $link . "\n\nAbaikan email ini jika Anda tidak memintanya.");
            $this->audit->record('federation.email_requested', null, null, null, ['provider' => $slug]);
        }
        UiSession::forget('fed_email');

        return $this->view->render('auth/verify_email', ['title' => 'Cek email Anda', 'email' => $email]);
    }

    public function verifyEmail(Request $request): Response
    {
        $slug = $this->slug($request);
        $config = $this->config($slug);
        $token = (string) $request->input('token', '');
        $state = preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $this->states->consume(hash('sha256', $token)) : null;
        $pending = $state === null ? [] : $state->pendingRequest;
        if (
            $state === null || ($pending[self::KIND] ?? null) !== self::KIND_EMAIL || $state->providerId !== $config->id
            || !is_string($pending['email'] ?? null) || !is_array($pending['profile'] ?? null)
        ) {
            Flash::add('err', 'Tautan verifikasi tidak valid atau sudah kedaluwarsa.');

            return Response::redirect('/login', 303);
        }
        try {
            $user = $this->completer->createVerifiedUser($config, NormalizedProfile::fromArray($pending['profile']), $pending['email']);
        } catch (FederationException) {
            Flash::add('err', 'Akun tidak dapat dibuat. Email mungkin sudah terdaftar; masuk dengan cara biasa lalu tautkan dari halaman Akun tertaut.');

            return Response::redirect('/login', 303);
        }
        // Sengaja tanpa login otomatis: tautan bisa dibuka di perangkat lain; pengguna masuk lewat penyedia (MFA tetap berlaku).
        $this->audit->record('email.verified', $user->id, $user->id, null, ['provider' => $slug]);
        Flash::add('ok', 'Email terverifikasi. Silakan masuk dengan ' . $config->displayName . '.');

        return Response::redirect('/login', 303);
    }

    /**
     * Lanjutkan login. User ber-MFA TIDAK mendapat sesi SSO di sini: pre_mfa lalu /login/mfa (sesi dibuat dengan mfaPassed=true
     * hanya setelah TOTP/kode cadangan lulus). Tautan tertunda ($link) ditulis oleh MfaController setelah lulus.
     *
     * @param ?array{provider_id: int, profile: array<string,mixed>} $link
     */
    private function login(Request $request, User $user, string $slug, string $next, ?array $link): Response
    {
        if ($user->status !== 'active') {
            $this->audit->record('federation.login_blocked', null, $user->id, null, ['provider' => $slug, 'status' => $user->status]);

            return $this->fail($slug, 'account_unavailable');
        }
        UiSession::regenerate();
        if ($user->mfaEnabledAt !== null) {
            $pre = [
                'user_id' => $user->id,
                'next' => $next,
                'remember' => false,
                'at' => $this->clock->now()->getTimestamp(),
                'method' => $slug,
            ];
            if ($link !== null) {
                $pre['link'] = $link;
            }
            UiSession::set('pre_mfa', $pre);

            return Response::redirect('/login/mfa', 303);
        }
        $cookie = $this->sessions->start($user->id, $slug, false, $request);
        $this->audit->record('login.success', $user->id, $user->id, null, ['method' => $slug]);

        return Response::redirect($next, 303)->withCookie($cookie);
    }

    /** @return array<string,mixed> */
    private function flow(IdentityProvider $config, NormalizedProfile $profile, string $next, ?int $userId): array
    {
        $raw = json_encode($profile->raw);
        $data = $profile->toArray();
        if ($raw === false || strlen($raw) > 8192) {
            $data['raw'] = [];
        }

        return [
            'provider_id' => $config->id,
            'profile' => $data,
            'next' => $next,
            'user_id' => $userId,
            'at' => $this->clock->now()->getTimestamp(),
        ];
    }

    /** @return ?array<string,mixed> alur sementara untuk slug ini yang masih segar */
    private function activeFlow(string $key, string $slug): ?array
    {
        $flow = UiSession::get($key);
        $config = $this->providers->findBySlug($slug);
        if (
            !is_array($flow) || $config === null || ($flow['provider_id'] ?? null) !== $config->id || !is_array($flow['profile'] ?? null)
            || !is_int($flow['at'] ?? null) || $this->clock->now()->getTimestamp() - $flow['at'] > self::FLOW_TTL
        ) {
            UiSession::forget($key);

            return null;
        }

        return $flow;
    }

    /** Niat menautkan dibuat POST /account/linked/{slug}/link (CSRF terverifikasi) dan hanya berlaku sekali. */
    private function consumeLinkIntent(Request $request, string $slug): ?int
    {
        $intent = UiSession::pull('fed_link');
        $session = $this->sessions->current($request);
        if (
            !is_array($intent) || $session === null || ($intent['slug'] ?? null) !== $slug || ($intent['user_id'] ?? null) !== $session->userId
            || !is_int($intent['at'] ?? null) || $this->clock->now()->getTimestamp() - $intent['at'] > 300
        ) {
            return null;
        }

        return $session->userId;
    }

    private function fail(string $slug, string $reason, ?int $userId = null, string $to = '/login'): Response
    {
        $this->audit->record('federation.failure', null, $userId, null, ['provider' => $slug, 'reason' => $reason]);
        Flash::add('err', self::GENERIC_FAIL);

        return Response::redirect($to, 303);
    }

    private function config(string $slug): IdentityProvider
    {
        try {
            return $this->registry->config($slug);
        } catch (FederationException) {
            throw new HttpException(404);
        }
    }

    private function slug(Request $request): string
    {
        $slug = $request->attribute('slug');
        if (!is_string($slug) || preg_match('/^[a-z0-9_-]{1,30}$/', $slug) !== 1) {
            throw new HttpException(404);
        }

        return $slug;
    }

    private function send(string $to, string $subject, string $text): void
    {
        if (!$this->urls->available()) {
            $this->logger->error('base_url belum dikonfigurasi; email federasi tidak dikirim');

            return;
        }
        try {
            $this->mailer->send($to, $subject, $text);
        } catch (\Throwable $e) {
            $this->logger->error('Pengiriman email federasi gagal: ' . $e::class);
        }
    }

    private function renderConfirm(IdentityProvider $config, User $target, ?string $error, int $status = 200): Response
    {
        return $this->view->render('auth/confirm_link', [
            'title' => 'Konfirmasi tautan akun',
            'provider' => ['name' => $config->displayName, 'slug' => $config->slug],
            'target' => ['email' => $target->email ?? ''],
            'error' => $error,
        ], 'auth', $status);
    }

    private function renderEmail(IdentityProvider $config, ?string $error, int $status = 200): Response
    {
        return $this->view->render('auth/complete_email', [
            'title' => 'Lengkapi email',
            'provider' => ['name' => $config->displayName, 'slug' => $config->slug],
            'error' => $error,
        ], 'auth', $status);
    }

    /** @return array<string,string> hanya firstName/lastName (maks 100 karakter) dari JSON `user` Apple */
    private static function appleName(string $json): array
    {
        $data = json_decode($json, true);
        $name = is_array($data) && is_array($data['name'] ?? null) ? $data['name'] : [];
        $out = [];
        foreach (['firstName', 'lastName'] as $k) {
            if (is_string($name[$k] ?? null)) {
                $v = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name[$k]) ?? '');
                if ($v !== '') {
                    $out[$k] = mb_substr($v, 0, 100);
                }
            }
        }

        return $out;
    }
}
