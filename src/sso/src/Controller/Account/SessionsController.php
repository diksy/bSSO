<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Repository\SsoSessionRepository;
use App\Session\SessionTerminator;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Flash;

/** Sesi aktif milik user yang sedang login: lihat, cabut satu, keluar dari semua perangkat. Otorisasi: sesi harus milik user. */
final class SessionsController
{
    public function __construct(
        private readonly AccountPage $page,
        private readonly SsoSessionRepository $sessions,
        private readonly SessionTerminator $terminator,
        private readonly SsoSessionManager $manager,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        [$user, $current] = $this->context($request);
        $fmt = static fn (\DateTimeImmutable $t): string => $t->format('Y-m-d H:i') . ' UTC';
        $rows = [];
        foreach ($this->sessions->listActiveForUser($user->id, $this->clock->now()) as $s) {
            $rows[] = [
                'id' => $s->sid,
                'device' => self::device($s->userAgent),
                'current' => $s->sid === $current->sid,
                'ip' => $s->ipAddress ?? '-',
                'started' => $fmt($s->createdAt),
                'last' => $fmt($s->lastSeenAt),
            ];
        }

        return $this->page->render($request, 'account/sessions', 'Sesi aktif', 'sessions', ['sessions' => $rows]);
    }

    public function revokeAll(Request $request): Response
    {
        [$user] = $this->context($request);
        // Semua perangkat, termasuk sesi ini (arsitektur/requirements §6).
        $this->terminator->deferDispatch();
        $this->terminator->endAllForUser($user->id, null, 'user_logout_all');
        $this->audit->record('session.revoke_all', $user->id, $user->id);

        return Response::redirect('/logged-out', 303)->withCookie($this->manager->clearCookie());
    }

    public function revoke(Request $request): Response
    {
        [$user, $current] = $this->context($request);
        $sid = (string) $request->attribute('sid');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sid) !== 1) {
            throw new HttpException(404);
        }
        $target = $this->sessions->findBySid($sid);
        // Bukan milik user ini → 404 yang sama dengan sesi tak ada (tidak membocorkan keberadaan).
        if ($target === null || $target->userId !== $user->id || $target->revokedAt !== null) {
            throw new HttpException(404);
        }
        $this->terminator->deferDispatch();
        $this->terminator->endSession($sid, 'user_revoke');
        $this->audit->record('session.revoke', $user->id, $user->id, null, ['sid' => $sid]);
        if ($sid === $current->sid) {
            return Response::redirect('/logged-out', 303)->withCookie($this->manager->clearCookie());
        }
        Flash::add('ok', 'Sesi dicabut.');

        return Response::redirect('/account/sessions', 303);
    }

    /** @return array{0: User, 1: SsoSession} */
    private function context(Request $request): array
    {
        $user = $request->attribute('user');
        $session = $request->attribute('sso_session');
        if (!$user instanceof User || !$session instanceof SsoSession) {
            throw new \LogicException('RequireSsoSession wajib');
        }

        return [$user, $session];
    }

    private static function device(?string $ua): string
    {
        $ua = (string) $ua;
        $browser = 'Peramban';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $label) {
            if (str_contains($ua, $needle)) {
                $browser = $label;
                break;
            }
        }
        $os = 'perangkat tak dikenal';
        foreach (['Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Windows' => 'Windows', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $needle => $label) {
            if (str_contains($ua, $needle)) {
                $os = $label;
                break;
            }
        }

        return $browser . ' di ' . $os;
    }
}
