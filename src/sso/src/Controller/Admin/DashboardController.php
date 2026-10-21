<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use App\Repository\ClientRepository;
use App\Repository\LogoutNotificationRepository;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Http\Request;
use App\Http\Response;
use App\Security\KeyManager;
use App\Support\Clock;

final class DashboardController
{
    public function __construct(
        private readonly AdminPage $page,
        private readonly AuditLogRepository $audit,
        private readonly SsoSessionRepository $sessions,
        private readonly LogoutNotificationRepository $notifications,
        private readonly SigningKeyRepository $keys,
        private readonly SettingRepository $settings,
        private readonly ClientRepository $clients,
        private readonly UserRepository $users,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        $now = $this->clock->now();
        $since = $now->modify('-24 hours');
        $fmt = static fn (\DateTimeImmutable $t): string => $t->format('Y-m-d H:i');

        $reuse = [];
        foreach ($this->audit->recentByEvent('token.reuse_detected', 10) as $e) {
            $reuse[] = [
                'time' => $fmt($e->createdAt),
                'client' => $e->clientId === null ? '–' : ($this->clients->findById($e->clientId)->name ?? '#' . $e->clientId),
                'user' => $e->userId === null ? '–' : ($this->users->findById($e->userId)->email ?? '#' . $e->userId),
            ];
        }
        $failed = [];
        foreach ($this->notifications->listPermanentFailures(10) as $n) {
            $failed[] = [
                'time' => $fmt($n->nextTryAt),
                'client' => $this->clients->findById($n->clientId)->name ?? '#' . $n->clientId,
                'attempts' => $n->attempts,
            ];
        }

        return $this->page->render($request, 'admin/dashboard', 'Dasbor', 'dashboard', ['d' => [
            'login_ok' => $this->audit->countEventSince('login.success', $since),
            'login_fail' => $this->audit->countEventSince('login.failure', $since),
            'active_sessions' => $this->sessions->countActive($now),
            'days_to_rotation' => $this->daysToRotation($now),
            'reuse_events' => $reuse,
            'failed_logout' => $failed,
        ]]);
    }

    private function daysToRotation(\DateTimeImmutable $now): int
    {
        $active = $this->keys->findActive();
        $days = max(1, $this->settings->int('key.rotation_days', KeyManager::DEFAULT_ROTATION_DAYS));
        if ($active === null) {
            return 0;
        }
        $due = ($active->activatedAt ?? $active->createdAt)->modify('+' . $days . ' days');

        return max(0, (int) ceil(($due->getTimestamp() - $now->getTimestamp()) / 86400));
    }
}
