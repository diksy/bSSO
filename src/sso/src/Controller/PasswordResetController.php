<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class PasswordResetController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function forgotForm(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function forgotSubmit(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function resetForm(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function resetSubmit(Request $request): Response
    {
        throw new HttpException(501);
    }
}
