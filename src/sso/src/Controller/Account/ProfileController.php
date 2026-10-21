<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class ProfileController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function show(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function save(Request $request): Response
    {
        throw new HttpException(501);
    }
}
