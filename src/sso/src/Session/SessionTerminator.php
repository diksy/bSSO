<?php

declare(strict_types=1);

namespace App\Session;

interface SessionTerminator
{
    public function endSession(string $sid, string $reason): void;

    /** Tunda pengiriman back-channel sampai respons terkirim ke browser (aman dipanggil sebelum endSession/endAllForUser). */
    public function deferDispatch(): void;

    public function endAllForUser(int $userId, ?string $exceptSid, string $reason): void;
}
