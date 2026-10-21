<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class ProvidersController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function index(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function edit(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function update(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function toggle(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function testConnection(Request $request): Response
    {
        throw new HttpException(501);
    }
}
