<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Security\KeyManager;

final class JwksController
{
    public function __construct(private readonly KeyManager $keys)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json($this->keys->jwks())
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }
}
