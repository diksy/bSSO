<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Admin\AdminPolicy;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AdminRoleRepository;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Repository\SettingRepository;
use App\Session\SessionTerminator;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;

/**
 * Setelah RequireSsoSession. Role dari admin_roles; tanpa MFA aktif -> /account/mfa; level < need -> 403;
 * idle melebihi settings admin.idle_minutes (bawaan 15) -> sesi dicabut dan login ulang. Atribut: admin_role.
 */
final class RequireAdmin implements Middleware
{
    public const DEFAULT_IDLE_MINUTES = 15;

    public function __construct(
        private readonly AdminPolicy $policy,
        private readonly AdminRoleRepository $roles,
        public readonly string $module,
        public readonly string $need,
        private readonly ?SettingRepository $settings = null,
        private readonly ?Clock $clock = null,
        private readonly ?SessionTerminator $terminator = null,
        private readonly ?SsoSessionManager $sessions = null,
        private readonly ?Audit $audit = null,
    ) {
    }

    public function process(Request $r, callable $next): Response
    {
        $session = $r->attribute('sso_session');
        $user = $r->attribute('user');
        if (!$session instanceof SsoSession || !$user instanceof User) {
            throw new \LogicException('RequireSsoSession wajib dijalankan sebelum RequireAdmin');
        }
        $record = $this->roles->findByUserId($user->id);
        if ($record === null) {
            $this->audit?->record('admin.denied', $user->id, null, null, ['module' => $this->module, 'reason' => 'not_admin']);
            throw new HttpException(403);
        }
        if ($user->mfaEnabledAt === null) {
            return Response::redirect('/account/mfa', $r->method === 'GET' ? 302 : 303);
        }
        $level = $this->policy->level($record->role, $this->module);
        if (!AdminPolicy::satisfies($level, $this->need)) {
            $this->audit?->record('admin.denied', $user->id, null, null, [
                'module' => $this->module, 'need' => $this->need, 'role' => $record->role,
            ]);
            throw new HttpException(403);
        }
        if ($this->idle($session)) {
            return $this->forceLogin($r, $session, $user);
        }

        if ($session->mfaPassedAt === null) {
            // Sesi tidak pernah melewati MFA: wajib verifikasi lebih dulu.
            return Response::redirect('/admin/stepup', 303);
        }

        /** @var Response */
        return $next($r->withAttribute('admin_role', $record->role));
    }

    private function idle(SsoSession $session): bool
    {
        $minutes = $this->settings?->int('admin.idle_minutes', self::DEFAULT_IDLE_MINUTES) ?? self::DEFAULT_IDLE_MINUTES;
        $minutes = max(1, min(240, $minutes));
        $now = $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $session->lastSeenAt->getTimestamp() + $minutes * 60 <= $now->getTimestamp();
    }

    private function forceLogin(Request $r, SsoSession $session, User $user): Response
    {
        $this->terminator?->endSession($session->sid, 'admin_idle');
        $this->audit?->record('admin.idle_logout', $user->id, $user->id);
        $to = $r->method === 'GET'
            ? Response::redirect('/login?return=' . rawurlencode($r->path))
            : Response::redirect('/login', 303);

        return $this->sessions === null ? $to : $to->withCookie($this->sessions->clearCookie());
    }
}
