<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Urls;

final class DiscoveryController
{
    public function __construct(private readonly Urls $urls)
    {
    }

    public function show(Request $request): Response
    {
        // Issuer berasal dari konfigurasi, bukan header Host.
        if (!$this->urls->available()) {
            throw new HttpException(500);
        }
        $u = fn (string $path): string => $this->urls->abs($path);

        return Response::json([
            'issuer' => rtrim($this->urls->abs('/'), '/'),
            'authorization_endpoint' => $u('/authorize'),
            'token_endpoint' => $u('/token'),
            'userinfo_endpoint' => $u('/userinfo'),
            'jwks_uri' => $u('/jwks'),
            'introspection_endpoint' => $u('/introspect'),
            'revocation_endpoint' => $u('/revoke'),
            'end_session_endpoint' => $u('/logout'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email', 'offline_access'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'sid', 'amr', 'email', 'email_verified', 'name', 'picture'],
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
        ])->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
