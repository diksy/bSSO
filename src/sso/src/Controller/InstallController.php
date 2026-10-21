<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class InstallController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function form(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function submit(Request $request): Response
    {
        throw new HttpException(501);
    }
}
