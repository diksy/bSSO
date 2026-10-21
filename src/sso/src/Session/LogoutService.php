<?php

declare(strict_types=1);

namespace App\Session;

use App\Repository\RefreshTokenRepository;
use App\Repository\SsoSessionRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Logger;

/**
 * Satu pintu semua pemicu logout: dalam satu transaksi cabut sesi + refresh token milik sesi + antrekan notifikasi
 * back-channel; setelah commit, notifikasi dikirim (atau ditunda sampai respons terkirim lewat deferDispatch()).
 */
final class LogoutService implements SessionTerminator
{
    private bool $deferred = false;
    private bool $scheduled = false;
    /** @var list<string> */
    private array $pendingSids = [];
    /** @var \Closure(callable): void */
    private \Closure $scheduler;

    /** @param ?\Closure(callable): void $scheduler pengganti register_shutdown_function (tes) */
    public function __construct(
        private readonly SsoSessionRepository $sessions,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly BackchannelNotifier $notifier,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly Logger $logger,
        ?\Closure $scheduler = null,
    ) {
        $this->scheduler = $scheduler ?? static function (callable $fn): void {
            register_shutdown_function($fn);
        };
    }

    /** Kirim notifikasi setelah respons dikirim ke browser (PHP-FPM: fastcgi_finish_request). */
    public function deferDispatch(): void
    {
        $this->deferred = true;
    }

    public function endSession(string $sid, string $reason): void
    {
        $ended = $this->db->transaction(fn (): bool => $this->revokeOne($sid, $reason));
        if ($ended) {
            $this->dispatch([$sid]);
        }
    }

    public function endAllForUser(int $userId, ?string $exceptSid, string $reason): void
    {
        $ended = $this->db->transaction(function () use ($userId, $exceptSid, $reason): array {
            $done = [];
            foreach ($this->sessions->listUnrevokedSidsForUser($userId) as $sid) {
                if ($sid !== $exceptSid && $this->revokeOne($sid, $reason)) {
                    $done[] = $sid;
                }
            }

            return $done;
        });
        if ($ended !== []) {
            $this->dispatch($ended);
        }
    }

    private function revokeOne(string $sid, string $reason): bool
    {
        $session = $this->sessions->findBySid($sid);
        if ($session === null) {
            return false;
        }
        $now = $this->clock->now();
        // Atomik: hanya pemanggil yang benar-benar mencabut yang mengantrekan notifikasi.
        if (!$this->sessions->revokeIfActive($sid, $now)) {
            return false;
        }
        $this->refreshTokens->revokeBySession($sid, $now);
        $this->notifier->queue($session);
        $this->audit->record('session.ended', null, $session->userId, null, ['reason' => mb_substr($reason, 0, 50), 'sid' => $sid]);

        return true;
    }

    /** @param list<string> $sids */
    private function dispatch(array $sids): void
    {
        // Di dalam transaksi pemanggil: kirim hanya setelah transaksi itu selesai (tidak mengirim untuk data yang bisa di-rollback).
        if ($this->deferred || $this->db->pdo()->inTransaction()) {
            $this->pendingSids = array_merge($this->pendingSids, $sids);
            if (!$this->scheduled) {
                $this->scheduled = true;
                ($this->scheduler)(function (): void {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    $this->run($this->pendingSids);
                });
            }

            return;
        }
        $this->run($sids);
    }

    /** @param list<string> $sids */
    private function run(array $sids): void
    {
        try {
            $this->notifier->dispatchForSids($sids);
        } catch (\Throwable $e) {
            // Logout sudah sah; kegagalan kirim tidak boleh menggagalkan permintaan. Retry lewat cron.
            $this->logger->exception($e);
        }
    }
}
