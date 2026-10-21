<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class IntrospectController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function introspect(Request $request): Response
    {
        throw new HttpException(501);
    }
}
