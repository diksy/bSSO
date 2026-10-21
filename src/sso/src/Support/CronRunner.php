<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\KeyManager;
use App\Session\BackchannelNotifier;

/** Tugas berkala (bin/cron.php): retry logout back-channel, rotasi kunci terjadwal, GC. Satu tugas gagal tidak menghentikan yang lain. */
final class CronRunner
{
    private const NOTIFY_BATCH = 50;
    private const NOTIFY_MAX_BATCHES = 10;

    public function __construct(
        private readonly BackchannelNotifier $notifier,
        private readonly KeyManager $keys,
        private readonly Gc $gc,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string,string> ringkasan per tugas (tanpa data sensitif) */
    public function run(): array
    {
        $out = [];
        $out['logout_retry'] = $this->task(function (): string {
            $sent = 0;
            for ($i = 0; $i < self::NOTIFY_MAX_BATCHES; $i++) {
                $n = $this->notifier->dispatchPending(self::NOTIFY_BATCH);
                $sent += $n;
                if ($n < self::NOTIFY_BATCH) {
                    break;
                }
            }

            return 'terkirim ' . $sent;
        });
        $out['rotate_keys'] = $this->task(fn (): string => $this->keys->rotateIfDue() === null ? 'tidak ada' : 'dirotasi');
        $out['gc'] = $this->task(function (): string {
            $deleted = $this->gc->run();

            return 'dihapus ' . array_sum($deleted);
        });

        return $out;
    }

    /** @param callable(): string $fn */
    private function task(callable $fn): string
    {
        try {
            return $fn();
        } catch (\LogicException $e) {
            // Stub kontrak yang belum diisi modul pemiliknya (Gc: T11).
            $this->logger->exception($e);

            return 'dilewati';
        } catch (\Throwable $e) {
            $this->logger->exception($e);

            return 'gagal';
        }
    }
}
