<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\SafePath;
use App\Support\UiSession;
use App\Support\View;

final class LoginController
{
    private const GENERIC = 'Email atau kata sandi salah.';

    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SsoSessionManager $sessions,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly ?IdentityProviderRepository $providers = null,
    ) {
    }

    public function form(Request $request): Response
    {
        $next = SafePath::clean($request->input('return'));
        if ($this->sessions->current($request) !== null) {
            return Response::redirect($next);
        }

        return $this->render($next, '', null);
    }

    public function submit(Request $request): Response
    {
        $next = SafePath::clean($request->input('next'));
        $email = UserRepository::normalizeEmail((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $ip = $request->ip();

        if (strlen($email) > 255 || strlen($password) > 1024) {
            return $this->render($next, '', self::GENERIC, 422);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            // Bukan email: jangan sentuh limiter (cegah injeksi identifier seperti 'ip:<IP>'); waktu disamakan.
            $this->hasher->verify($password, $this->hasher->dummyHash());
            $this->audit->record('login.failure', null, null);

            return $this->render($next, $email, self::GENERIC, 401);
        }
        // Atomik: percobaan dicatat sebagai gagal sebelum diverifikasi; request serentak tak bisa melewati batas.
        $ipAttempt = $this->limiter->beginIp($ip);
        if ($ipAttempt === null) {
            return $this->render($next, $email, 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }
        $attempt = $this->limiter->begin($email, $ip);
        if ($attempt === null) {
            $this->limiter->releaseIp($ipAttempt);

            return $this->render($next, $email, 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }

        $user = $this->users->findByEmail($email);
        // Selalu menjalankan verifikasi (hash tiruan bila user tidak ada) agar waktu respons seragam.
        $hash = $user === null ? $this->hasher->dummyHash() : ($user->passwordHash ?? $this->hasher->dummyHash());
        $matches = $this->hasher->verify($password, $hash);

        if (!$matches || $user === null || $user->passwordHash === null) {
            $this->audit->record('login.failure', null, $user?->id);

            return $this->render($next, $email, self::GENERIC, 401);
        }
        if ($user->status === 'suspended') {
            $this->audit->record('login.suspended', null, $user->id);

            return $this->render($next, $email, self::GENERIC, 401);
        }
        $this->limiter->markSuccess($attempt);
        $this->limiter->releaseIp($ipAttempt);
        if ($user->status !== 'active' || $user->emailVerifiedAt === null) {
            return $this->render($next, $email, 'Email belum diverifikasi. Buka tautan verifikasi yang dikirim ke email Anda.', 403);
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }
        $remember = $request->input('remember') === '1';

        // Ganti id sesi UI agar sesi yang dikenal sebelum login tidak bisa dipakai ulang (session fixation).
        UiSession::regenerate();

        if ($user->mfaEnabledAt !== null) {
            UiSession::set('pre_mfa', [
                'user_id' => $user->id,
                'next' => $next,
                'remember' => $remember,
                'at' => $this->clock->now()->getTimestamp(),
            ]);

            return Response::redirect('/login/mfa', 303);
        }

        $cookie = $this->sessions->start($user->id, 'password', false, $request, $remember);
        $this->audit->record('login.success', $user->id, $user->id, null, ['method' => 'password']);

        return Response::redirect($next, 303)->withCookie($cookie);
    }

    private function render(string $next, string $email, ?string $error, int $status = 200): Response
    {
        $providers = [];
        foreach ($this->providers?->listEnabled() ?? [] as $p) {
            $providers[] = ['slug' => $p->slug, 'name' => $p->displayName];
        }
        return $this->view->render('auth/login', [
            'title' => 'Masuk',
            'error' => $error,
            'next' => $next,
            'email' => $email,
            'providers' => $providers,
        ], 'auth', $status);
    }
}
