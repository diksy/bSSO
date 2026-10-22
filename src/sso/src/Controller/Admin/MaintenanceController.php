<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Repository\LogoutNotificationRepository;
use App\Security\KeyManager;
use App\Session\BackchannelNotifier;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Flash;
use App\Support\Gc;

/**
 * Pemeliharaan untuk hosting tanpa cron (maintenance:manage; rotate-keys + step-up). Gc::run() masih stub sampai T11:
 * LogicException ditampilkan sebagai "belum tersedia", bukan galat.
 */
final class MaintenanceController
{
    private const RETRY_BATCH = 50;
    private const RETRY_MAX_BATCHES = 10;

    public function __construct(
        private readonly AdminPage $page,
        private readonly Gc $gc,
        private readonly BackchannelNotifier $notifier,
        private readonly LogoutNotificationRepository $notifications,
        private readonly KeyManager $keys,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page->render($request, 'admin/maintenance', 'Pemeliharaan', 'maintenance', [
            'failed' => $this->notifications->countPermanentFailures(),
        ]);
    }

    public function gc(Request $request): Response
    {
        $actor = $this->page->actor($request);
        try {
            $deleted = $this->gc->run();
            $this->audit->record('maintenance.gc', $actor->id, null, null, ['after' => ['deleted' => $deleted]]);
            Flash::add('ok', 'GC selesai: ' . array_sum($deleted) . ' baris dihapus.');
        } catch (\LogicException) {
            Flash::add('warn', 'GC belum tersedia (menunggu modul pengerasan).');
        }

        return $this->back($request);
    }

    /** Hidupkan lagi notifikasi yang gagal permanen lalu kirim (maks. 10 x 50). */
    public function retryLogout(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $reset = $this->notifications->resetFailed($this->clock->now());
        $sent = 0;
        for ($i = 0; $i < self::RETRY_MAX_BATCHES; $i++) {
            $n = $this->notifier->dispatchPending(self::RETRY_BATCH);
            $sent += $n;
            if ($n < self::RETRY_BATCH) {
                break;
            }
        }
        $this->audit->record('maintenance.retry_logout', $actor->id, null, null, ['after' => ['reset' => $reset, 'delivered' => $sent]]);
        Flash::add('ok', "Notifikasi logout: $sent terkirim, $reset dijadwalkan ulang.");

        return $this->back($request);
    }

    /** Rotasi terjadwal saja (KeyManager::rotateIfDue), bukan rotasi paksa. */
    public function rotateKeys(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $result = $this->keys->rotateIfDue();
        $this->audit->record('key.rotate_scheduled', $actor->id, null, null, [
            'after' => $result === null
                ? ['rotated' => false]
                : ['rotated' => true, 'actions' => $result->actions, 'active_kid' => $result->activeKid, 'next_kid' => $result->nextKid],
        ]);
        Flash::add('ok', $result === null ? 'Belum jatuh tempo; tidak ada perubahan.' : 'Rotasi terjadwal dijalankan: ' . implode(', ', $result->actions));

        return $this->back($request);
    }

    private function back(Request $request): Response
    {
        return Response::redirect($this->page->back($request, '/admin/maintenance'), 303);
    }
}
