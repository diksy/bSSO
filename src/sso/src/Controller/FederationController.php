<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class FederationController
{
    // Stub kontrak: 501 sampai modul pemilik mengisi badan.

    public function start(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function callback(Request $request): Response
    {
        throw new HttpException(501);
    }

    /**
     * POST form_post (Apple): bebas CSRF middleware; slug non-form_post 405; simpan `user` via
     * FederationStateRepository::mergePending lalu 303 ke GET callback (state, code/error saja).
     */
    public function formPostCallback(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function confirmForm(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function confirmSubmit(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function emailForm(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function emailSubmit(Request $request): Response
    {
        throw new HttpException(501);
    }

    public function verifyEmail(Request $request): Response
    {
        throw new HttpException(501);
    }
}
