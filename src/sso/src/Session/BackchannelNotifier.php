<?php

declare(strict_types=1);

namespace App\Session;

use App\OAuth\TokenIssuer;
use App\Repository\ClientRepository;
use App\Repository\LogoutNotificationRepository;
use App\Repository\Record\LogoutNotification;
use App\Repository\Record\SsoSession;
use App\Repository\SessionClientRepository;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\HttpClient;

/**
 * OIDC Back-Channel Logout: antrean di logout_notifications, pengiriman paralel (3 s per klien), retry via cron.
 * Tujuan = clients.backchannel_logout_uri, selalu lewat HttpClient (allowlist https + anti-SSRF).
 */
final class BackchannelNotifier
{
    /** Jeda setelah kegagalan ke-1..4 (menit); kegagalan ke-5 = gagal permanen. */
    private const BACKOFF_MINUTES = [1, 5, 15, 60];
    private const LEASE_SECONDS = 120;
    private const TIMEOUT = 3;

    public function __construct(
        private readonly LogoutNotificationRepository $notifications,
        private readonly SessionClientRepository $sessionClients,
        private readonly ClientRepository $clients,
        private readonly UserRepository $users,
        private readonly TokenIssuer $issuer,
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly Audit $audit,
    ) {
    }

    /** Antrekan satu baris per klien di sesi yang punya backchannel_logout_uri (dipanggil sekali saat sesi dicabut). */
    public function queue(SsoSession $session): void
    {
        $user = $this->users->findById($session->userId);
        if ($user === null) {
            return;
        }
        $now = $this->clock->now();
        foreach ($this->sessionClients->listForSession($session->sid) as $sc) {
            $client = $this->clients->findById($sc->clientId);
            if ($client === null || $client->backchannelLogoutUri === null || $client->backchannelLogoutUri === '') {
                continue;
            }
            $this->notifications->insert($client->id, $session->sid, $user->uuid, $now);
        }
    }

    /** @return int jumlah terkirim */
    public function dispatchPending(int $limit = 50): int
    {
        $now = $this->clock->now();
        $claimed = [];
        foreach ($this->notifications->listPending($now, $limit) as $n) {
            // Lease: dua pemroses serentak (cron + request) tidak mengirim ganda.
            if ($this->notifications->claim($n->id, $n->nextTryAt, $now->modify('+' . self::LEASE_SECONDS . ' seconds'))) {
                $claimed[] = $n;
            }
        }

        return $this->send($claimed);
    }

    /**
     * Kirim notifikasi sesi yang baru diantrekan (tanpa menunggu jadwal retry).
     *
     * @param list<string> $sids
     */
    public function dispatchForSids(array $sids): int
    {
        $now = $this->clock->now();
        $claimed = [];
        foreach ($this->notifications->listPending($now, 500) as $n) {
            if (
                in_array($n->sid, $sids, true)
                && $this->notifications->claim($n->id, $n->nextTryAt, $now->modify('+' . self::LEASE_SECONDS . ' seconds'))
            ) {
                $claimed[] = $n;
            }
        }

        return $this->send($claimed);
    }

    /** @param list<LogoutNotification> $claimed */
    private function send(array $claimed): int
    {
        /** @var array<string,array{method: string, url: string, headers?: array<string,string>, body?: ?string}> $requests */
        $requests = [];
        $byKey = [];
        foreach ($claimed as $n) {
            $client = $this->clients->findById($n->clientId);
            if ($client === null || !$client->isEnabled || $client->backchannelLogoutUri === null || $client->backchannelLogoutUri === '') {
                $this->notifications->markPermanentFailure($n->id, 'no_endpoint');
                continue;
            }
            $key = (string) $n->id;
            $byKey[$key] = [$n, $client];
            $requests[$key] = [
                'method' => 'POST',
                'url' => $client->backchannelLogoutUri,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Cache-Control' => 'no-store'],
                // Token baru tiap percobaan (exp +2 menit).
                'body' => http_build_query(['logout_token' => $this->issuer->logoutToken($client, $n->userUuid, $n->sid)]),
            ];
        }
        if ($requests === []) {
            return 0;
        }

        $delivered = 0;
        foreach ($this->http->postMany($requests, self::TIMEOUT) as $key => $result) {
            [$n, $client] = $byKey[$key];
            $now = $this->clock->now();
            if ($result->error === null && ($result->status === 200 || $result->status === 204)) {
                $this->notifications->markDelivered($n->id, $now);
                $delivered++;
                continue;
            }
            // Hanya kode status/galat singkat yang disimpan: tanpa isi respons atau token.
            $error = $result->error ?? ('http_' . $result->status);
            $attempts = $n->attempts + 1;
            $next = $attempts <= count(self::BACKOFF_MINUTES)
                ? $now->modify('+' . self::BACKOFF_MINUTES[$attempts - 1] . ' minutes')
                : $now;
            $this->notifications->markFailed($n->id, $error, $next);
            if ($attempts >= LogoutNotificationRepository::MAX_ATTEMPTS) {
                $this->audit->record('logout.notify_failed', null, null, $client->id, ['attempts' => $attempts, 'error' => $error]);
            }
        }

        return $delivered;
    }
}
