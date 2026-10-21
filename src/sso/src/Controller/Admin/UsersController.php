<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class UsersController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function index(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function show(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function setStatus(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function unlink(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function resetMfa(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function forcePasswordReset(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function revokeSession(Request $request): Response
    {
        throw new HttpException(501);
    }
}
