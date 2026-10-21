<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Security\BackupCodes;
use App\Security\Crypto;
use App\Security\RateLimiter;
use App\Security\Totp;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\SafePath;
use App\Support\UiSession;
use App\Support\View;

/** Tantangan MFA saat login (/login/mfa): TOTP atau kode cadangan; sesi SSO baru dibuat hanya setelah lulus. */
final class MfaController
{
    private const PRE_MFA_TTL = 300;
    private const GENERIC = 'Kode tidak valid.';

    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly Totp $totp,
        private readonly Crypto $crypto,
        private readonly BackupCodes $backup,
        private readonly RateLimiter $limiter,
        private readonly SsoSessionManager $sessions,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function form(Request $request): Response
    {
        if ($this->pending() === null) {
            return Response::redirect('/login');
        }

        return $this->render(null);
    }

    public function submit(Request $request): Response
    {
        $pre = $this->pending();
        if ($pre === null) {
            return Response::redirect('/login', 303);
        }
        $user = $this->users->findById($pre['user_id']);
        if ($user === null || $user->status !== 'active' || $user->mfaEnabledAt === null || $user->mfaSecretEnc === null) {
            UiSession::forget('pre_mfa');

            return Response::redirect('/login', 303);
        }
        $key = 'mfa:' . $user->id;
        if ($this->limiter->blocked($key)) {
            return $this->render('Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }
        $code = trim((string) $request->input('code', ''));
        if ($code === '' || strlen($code) > 32) {
            return $this->render(self::GENERIC, 422);
        }

        $method = $this->check($user, $code);
        if ($method === null) {
            $this->limiter->record($key, $request->ip(), false);
            $this->audit->record('mfa.failure', $user->id, $user->id, null, ['stage' => 'login']);

            return $this->render(self::GENERIC, 401);
        }
        $this->limiter->record($key, $request->ip(), true);

        UiSession::forget('pre_mfa');
        UiSession::regenerate();
        $cookie = $this->sessions->start($user->id, $pre['method'], true, $request, $pre['remember']);
        $this->audit->record('login.success', $user->id, $user->id, null, ['method' => $pre['method'], 'mfa' => $method]);

        return Response::redirect($pre['next'], 303)->withCookie($cookie);
    }

    /** @return 'totp'|'backup'|null */
    private function check(User $user, string $code): ?string
    {
        if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '') === 1) {
            $step = $this->totp->verify($this->crypto->decrypt((string) $user->mfaSecretEnc), $code, $user->mfaLastStep);

            return $step !== null && $this->users->claimMfaStep($user->id, $step) ? 'totp' : null;
        }

        return $this->backup->consume($user, $code) ? 'backup' : null;
    }

    /** @return array{user_id: int, next: string, remember: bool, method: string}|null */
    private function pending(): ?array
    {
        $pre = UiSession::get('pre_mfa');
        if (!is_array($pre) || !is_int($pre['user_id'] ?? null) || !is_int($pre['at'] ?? null)) {
            return null;
        }
        if ($this->clock->now()->getTimestamp() - $pre['at'] > self::PRE_MFA_TTL) {
            UiSession::forget('pre_mfa');

            return null;
        }

        return [
            'user_id' => $pre['user_id'],
            'next' => SafePath::clean(is_string($pre['next'] ?? null) ? $pre['next'] : null),
            'remember' => ($pre['remember'] ?? false) === true,
            'method' => is_string($pre['method'] ?? null) ? $pre['method'] : 'password',
        ];
    }

    private function render(?string $error, int $status = 200): Response
    {
        return $this->view->render('auth/mfa', ['title' => 'Verifikasi dua langkah', 'error' => $error], 'auth', $status);
    }
}
