<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class HomeController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function index(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function guide(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function guideMarkdown(Request $request): Response
    {
        throw new HttpException(501);
    }
}
