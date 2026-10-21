<?php

declare(strict_types=1);

namespace App\Session;

use App\Http\Cookie;
use App\Http\Request;
use App\Repository\Record\SsoSession;

final class SsoSessionManager
{
    public function start(int $userId, string $authMethod, bool $mfaPassed, Request $request): Cookie
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function current(Request $request): ?SsoSession
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** Ganti id sesi setelah MFA. */
    public function markMfaPassed(SsoSession $session): Cookie
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function touch(SsoSession $session): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function clearCookie(): Cookie
    {
        throw new \LogicException('belum diimplementasi');
    }
}
