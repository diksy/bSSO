<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\ClientAuthenticator;
use App\OAuth\JsonResponses;
use App\OAuth\OAuthException;
use App\OAuth\RefreshTokenService;

/**
 * POST /revoke (RFC 7009): mencabut refresh token (seluruh family) milik klien pemanggil. Token tak dikenal atau
 * milik klien lain tetap dijawab 200 kosong. Access token tidak dapat dicabut (kedaluwarsa singkat; /introspect cek sesi).
 */
final class RevokeController
{
    public function __construct(private readonly ClientAuthenticator $auth, private readonly RefreshTokenService $refresh)
    {
    }

    public function revoke(Request $request): Response
    {
        try {
            $client = $this->auth->authenticate($request);
        } catch (OAuthException $e) {
            return JsonResponses::error($e);
        }
        $token = $request->body['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return JsonResponses::error(new OAuthException('invalid_request', 'Parameter token wajib'));
        }
        $this->refresh->revoke($token, $client);

        return (new Response(200))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
