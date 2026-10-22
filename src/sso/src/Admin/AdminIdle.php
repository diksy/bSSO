<?php

declare(strict_types=1);

namespace App\Admin;

use App\Repository\Record\SsoSession;
use App\Repository\SettingRepository;
use App\Support\Clock;
use App\Support\UiSession;

/**
 * Idle admin dihitung dari aktivitas ADMIN terakhir (penanda di sesi UI, terikat sid), bukan sso_sessions.last_seen_at
 * yang ikut tersentuh akses /account. Tanpa penanda (belum pernah membuka area admin di sesi ini) dipakai last_seen_at.
 */
final class AdminIdle
{
    public const DEFAULT_MINUTES = 15;
    private const KEY = 'admin_active';

    public function __construct(private readonly ?SettingRepository $settings, private readonly ?Clock $clock)
    {
    }

    public function isIdle(SsoSession $session): bool
    {
        $minutes = $this->settings?->int('admin.idle_minutes', self::DEFAULT_MINUTES) ?? self::DEFAULT_MINUTES;
        $minutes = max(1, min(240, $minutes));
        $now = $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $mark = UiSession::get(self::KEY);
        $last = is_array($mark) && ($mark['sid'] ?? null) === $session->sid && is_int($mark['at'] ?? null)
            ? $mark['at']
            : $session->lastSeenAt->getTimestamp();

        return $last + $minutes * 60 <= $now->getTimestamp();
    }

    /** Hanya true bila ada penanda aktivitas admin untuk sesi ini yang sudah basi (dipakai /admin/stepup). */
    public function isMarkedIdle(SsoSession $session): bool
    {
        $mark = UiSession::get(self::KEY);

        return is_array($mark) && ($mark['sid'] ?? null) === $session->sid && $this->isIdle($session);
    }

    public function markActive(SsoSession $session): void
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        UiSession::set(self::KEY, ['sid' => $session->sid, 'at' => $now->getTimestamp()]);
    }
}
