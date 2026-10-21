<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class MaintenanceController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function index(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function gc(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function retryLogout(Request $request): Response
    {
        throw new HttpException(501);
    }

    /** Hanya KeyManager::rotateIfDue() (bukan rotasi paksa). */
    public function rotateKeys(Request $request): Response
    {
        throw new HttpException(501);
    }
}
