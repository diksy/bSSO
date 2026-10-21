<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\AccessTokenVerifier;
use App\OAuth\JsonResponses;
use App\OAuth\Scopes;
use App\OAuth\TokenIssuer;

/** GET/POST /userinfo: Bearer access token (header Authorization saja), claim sesuai scope. */
final class UserInfoController
{
    public function __construct(private readonly AccessTokenVerifier $verifier)
    {
    }

    public function show(Request $request): Response
    {
        $jwt = $request->bearerToken();
        if ($jwt === null) {
            return JsonResponses::bearerError('invalid_request', 'Access token (Bearer) wajib', 401);
        }
        $verified = $this->verifier->verify($jwt);
        $user = $verified['user'] ?? null;
        $scope = (string) ($verified['claims']['scope'] ?? '');
        if ($verified === null || $user === null) {
            return JsonResponses::bearerError('invalid_token', 'Access token tidak valid', 401);
        }
        if (!Scopes::has($scope, 'openid')) {
            return JsonResponses::bearerError('insufficient_scope', 'Scope openid dibutuhkan', 403);
        }

        return JsonResponses::ok(['sub' => $user->uuid] + TokenIssuer::profileClaims($user, $scope));
    }
}
