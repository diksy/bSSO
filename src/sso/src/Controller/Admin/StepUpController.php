<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminIdle;
use App\Admin\PendingActionStore;
use App\Http\HttpException;
use App\Http\Kernel;
use App\Http\Middleware\Csrf;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repository\AdminRoleRepository;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Security\Crypto;
use App\Security\RateLimiter;
use App\Security\Totp;
use App\Session\SessionTerminator;
use App\Session\SsoSessionManager;
use App\Support\Audit;

/**
 * Verifikasi MFA ulang (TOTP). Setelah lulus: sesi diganti id dan ditandai mfa_passed_at baru, lalu aksi tertunda
 * (jika sah) dijalankan ulang lewat Kernel::handle() dengan token CSRF baru, tepat sekali.
 */
final class StepUpController
{
    private const GENERIC = 'Kode tidak valid.';

    public function __construct(
        private readonly AdminPage $page,
        private readonly PendingActionStore $pending,
        private readonly AdminRoleRepository $roles,
        private readonly UserRepository $users,
        private readonly Totp $totp,
        private readonly Crypto $crypto,
        private readonly RateLimiter $limiter,
        private readonly SsoSessionManager $sessions,
        private readonly Audit $audit,
        private readonly Kernel $kernel,
        private readonly Router $router,
        private readonly AdminIdle $idle,
        private readonly SessionTerminator $terminator,
    ) {
    }

    public function form(Request $request): Response
    {
        $user = $this->page->actor($request);
        $role = $this->roles->findByUserId($user->id)->role ?? throw new HttpException(403);
        if ($this->idle->isMarkedIdle($this->page->session($request))) {
            return $this->forceLogin($request, $user->id);
        }
        if ($user->mfaEnabledAt === null) {
            return Response::redirect('/account/mfa');
        }

        return $this->render($request, $role, null);
    }

    public function submit(Request $request): Response
    {
        $user = $this->page->actor($request);
        $session = $this->page->session($request);
        $role = $this->roles->findByUserId($user->id)->role ?? throw new HttpException(403);
        if ($this->idle->isMarkedIdle($session)) {
            return $this->forceLogin($request, $user->id);
        }
        if ($user->mfaEnabledAt === null || $user->mfaSecretEnc === null) {
            return Response::redirect('/account/mfa', 303);
        }
        $key = 'mfa:' . $user->id;
        $attempt = $this->limiter->begin($key, $request->ip());
        if ($attempt === null) {
            return $this->render($request, $role, 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }
        $code = trim((string) $request->input('code', ''));
        if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '') !== 1 || !$this->verify($user, $code)) {
            $this->audit->record('mfa.failure', $user->id, $user->id, null, ['stage' => 'stepup']);

            return $this->render($request, $role, self::GENERIC, 401);
        }
        $this->limiter->markSuccess($attempt);

        $action = $this->pending->take($session); // sekali pakai: sudah dihapus dari sesi UI
        $cookie = $this->sessions->markMfaPassed($session);
        $this->idle->markActive($session);
        $this->audit->record('stepup.success', $user->id, $user->id, null, $action === null ? [] : ['path' => $action['path']]);

        if ($action === null || !$this->replayable($action['method'], $action['path'])) {
            return Response::redirect('/admin', 303)->withCookie($cookie);
        }
        $replay = new Request(
            $action['method'],
            $action['path'],
            [],
            $action['body'] + ['_csrf' => Csrf::rotate()],
            array_intersect_key($request->headers, ['user-agent' => true, 'accept-language' => true]),
            [SsoSessionManager::COOKIE => $cookie->value],
            [],
            $request->ip(),
        );

        return $this->kernel->handle($replay)->withCookie($cookie);
    }

    /** Admin idle melewati batas lalu membuka /admin/stepup: sesi dicabut (tidak boleh menghidupkan lagi lewat MFA). */
    private function forceLogin(Request $request, int $userId): Response
    {
        $session = $this->page->session($request);
        $this->terminator->endSession($session->sid, 'admin_idle');
        $this->audit->record('admin.idle_logout', $userId, $userId);

        return Response::redirect('/login', 303)->withCookie($this->sessions->clearCookie());
    }

    /** Hanya rute POST /admin/* yang benar-benar dipasangi RequireRecentAuth yang boleh dijalankan ulang. */
    private function replayable(string $method, string $path): bool
    {
        if (!PendingActionStore::pathAllowed($method, $path)) {
            return false;
        }
        try {
            $match = $this->router->match(new Request($method, $path));
        } catch (HttpException) {
            return false;
        }
        foreach ($match->middleware as $entry) {
            $class = is_array($entry) ? ($entry[0] ?? null) : $entry;
            if ($class === RequireRecentAuth::class) {
                return true;
            }
        }

        return false;
    }

    private function verify(User $user, string $code): bool
    {
        $step = $this->totp->verify($this->crypto->decrypt((string) $user->mfaSecretEnc), $code, $user->mfaLastStep);

        return $step !== null && $this->users->claimMfaStep($user->id, $step);
    }

    private function render(Request $request, string $role, ?string $error, int $status = 200): Response
    {
        $pending = $this->pending->peek($this->page->session($request));

        return $this->page->render($request, 'admin/stepup', 'Verifikasi MFA ulang', 'dashboard', [
            'role' => $role, 'perm' => 'manage', 'error' => $error, 'pending' => $pending,
        ], $status);
    }
}
