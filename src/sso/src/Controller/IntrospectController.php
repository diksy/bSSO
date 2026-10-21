<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\AccessTokenVerifier;
use App\OAuth\ClientAuthenticator;
use App\OAuth\JsonResponses;
use App\OAuth\OAuthException;
use App\OAuth\RefreshTokenService;
use App\OAuth\TokenIssuer;
use App\Repository\Record\Client;
use App\Repository\UserRepository;

/** POST /introspect (RFC 7662): hanya klien confidential; hanya token milik klien pemanggil, selain itu {"active":false}. */
final class IntrospectController
{
    public function __construct(
        private readonly ClientAuthenticator $auth,
        private readonly AccessTokenVerifier $verifier,
        private readonly RefreshTokenService $refresh,
        private readonly UserRepository $users,
        private readonly TokenIssuer $issuer,
    ) {
    }

    public function introspect(Request $request): Response
    {
        try {
            $client = $this->auth->authenticate($request, true);
        } catch (OAuthException $e) {
            return JsonResponses::error($e);
        }
        $token = $request->body['token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 8192) {
            return JsonResponses::error(new OAuthException('invalid_request', 'Parameter token wajib'));
        }
        $hint = $request->body['token_type_hint'] ?? null;

        $result = null;
        if ($hint !== 'refresh_token' && substr_count($token, '.') === 2) {
            $result = $this->accessToken($token, $client);
        }
        $result ??= $this->refreshToken($token, $client);

        return JsonResponses::ok($result ?? ['active' => false]);
    }

    /** @return array<string,mixed>|null */
    private function accessToken(string $jwt, Client $client): ?array
    {
        $verified = $this->verifier->verify($jwt);
        if ($verified === null || ($verified['claims']['client_id'] ?? null) !== $client->clientId) {
            return null;
        }
        $c = $verified['claims'];
        $out = [
            'active' => true,
            'scope' => $c['scope'],
            'client_id' => $c['client_id'],
            'token_type' => 'Bearer',
            'exp' => $c['exp'],
            'iat' => $c['iat'] ?? null,
            'sub' => $c['sub'],
            'aud' => $c['aud'] ?? null,
            'iss' => $c['iss'],
            'jti' => $c['jti'] ?? null,
            'sid' => $c['sid'] ?? null,
        ];
        if ($verified['user'] !== null && $verified['user']->email !== null) {
            $out['username'] = $verified['user']->email;
        }

        return array_filter($out, static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string,mixed>|null */
    private function refreshToken(string $token, Client $client): ?array
    {
        $rt = $this->refresh->inspect($token, $client);
        if ($rt === null) {
            return null;
        }
        $user = $this->users->findById($rt->userId);

        return array_filter([
            'active' => true,
            'scope' => $rt->scope,
            'client_id' => $client->clientId,
            'token_type' => 'refresh_token',
            'exp' => $rt->expiresAt->getTimestamp(),
            'iat' => $rt->issuedAt->getTimestamp(),
            'sub' => $user?->uuid,
            'iss' => $this->issuer->issuer(),
            'sid' => $rt->sessionSid,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
