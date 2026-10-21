<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class SessionsController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function show(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function revokeAll(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function revoke(Request $request): Response
    {
        throw new HttpException(501);
    }
}
