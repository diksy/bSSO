<?php

declare(strict_types=1);

namespace App\Session;

use App\Repository\Record\SsoSession;

final class BackchannelNotifier
{
    public function queue(SsoSession $session): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** curl_multi, backoff 1/5/15/60 menit, berhenti setelah 5 percobaan. @return int jumlah terkirim */
    public function dispatchPending(int $limit = 50): int
    {
        throw new \LogicException('belum diimplementasi');
    }
}
