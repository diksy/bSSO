<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\AuthCodeService;
use App\OAuth\ClientAuthenticator;
use App\OAuth\JsonResponses;
use App\OAuth\OAuthException;
use App\OAuth\RefreshTokenService;
use App\OAuth\Scopes;
use App\OAuth\TokenIssuer;
use App\Repository\Record\Client;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Support\Audit;

/** POST /token: authorization_code, refresh_token, client_credentials. Respons selalu no-store. */
final class TokenController
{
    public function __construct(
        private readonly ClientAuthenticator $auth,
        private readonly AuthCodeService $codes,
        private readonly RefreshTokenService $refresh,
        private readonly TokenIssuer $issuer,
        private readonly UserRepository $users,
        private readonly SsoSessionRepository $sessions,
        private readonly Audit $audit,
    ) {
    }

    public function token(Request $request): Response
    {
        try {
            $client = $this->auth->authenticate($request);
            $grant = $request->body['grant_type'] ?? null;
            if (!is_string($grant) || $grant === '') {
                throw new OAuthException('invalid_request', 'grant_type wajib');
            }
            if (!in_array($grant, ['authorization_code', 'refresh_token', 'client_credentials'], true)) {
                throw new OAuthException('unsupported_grant_type', 'grant_type tidak didukung');
            }
            if (!in_array($grant, $client->allowedGrants, true)) {
                throw new OAuthException('unauthorized_client', 'Klien tidak boleh memakai grant ini');
            }

            return JsonResponses::ok(match ($grant) {
                'authorization_code' => $this->authorizationCode($request, $client),
                'refresh_token' => $this->refreshToken($request, $client),
                default => $this->clientCredentials($request, $client),
            });
        } catch (OAuthException $e) {
            return JsonResponses::error($e);
        }
    }

    /** @return array<string,mixed> */
    private function authorizationCode(Request $request, Client $client): array
    {
        $code = $this->param($request, 'code');
        $redirectUri = $this->param($request, 'redirect_uri');
        $verifier = $this->param($request, 'code_verifier');

        $record = $this->codes->redeem($code, $client, $redirectUri, $verifier);
        $user = $this->users->findById($record->userId);
        $session = $this->sessions->findBySid($record->sessionSid);
        if ($user === null || $session === null) {
            throw new OAuthException('invalid_grant', 'Authorization code tidak valid');
        }

        $out = [
            'access_token' => $this->issuer->accessToken($user, $client, $record->scope, $session->sid),
            'token_type' => 'Bearer',
            'expires_in' => $client->accessTokenTtl,
            'scope' => $record->scope,
        ];
        if (Scopes::has($record->scope, 'openid')) {
            $out['id_token'] = $this->issuer->idToken($user, $client, $session, $record->scope, $record->nonce);
        }
        if (Scopes::has($record->scope, 'offline_access') && in_array('refresh_token', $client->allowedGrants, true)) {
            $out['refresh_token'] = $this->refresh->issue($client, $user, $session->sid, $record->scope, null, $record->codeHash);
        }
        $this->audit->record('token.issued', $user->id, $user->id, $client->id, ['grant' => 'authorization_code']);

        return $out;
    }

    /** @return array<string,mixed> */
    private function refreshToken(Request $request, Client $client): array
    {
        $rotated = $this->refresh->rotate($this->param($request, 'refresh_token'), $client);
        $user = $this->users->findById($rotated->userId);
        $session = $rotated->sessionSid === null ? null : $this->sessions->findBySid($rotated->sessionSid);
        if ($user === null || $session === null) {
            throw new OAuthException('invalid_grant', 'Refresh token tidak valid');
        }

        $scope = $rotated->scope;
        $requested = $request->body['scope'] ?? null;
        if (is_string($requested) && trim($requested) !== '') {
            $narrowed = (new Scopes())->intersect($requested, $rotated->scope);
            if (count(Scopes::split($narrowed)) !== count(Scopes::split($requested))) {
                throw new OAuthException('invalid_scope', 'Scope melebihi scope refresh token');
            }
            $scope = $narrowed;
        }

        $out = [
            'access_token' => $this->issuer->accessToken($user, $client, $scope, $session->sid),
            'token_type' => 'Bearer',
            'expires_in' => $client->accessTokenTtl,
            'scope' => $scope,
            'refresh_token' => $rotated->newToken,
        ];
        if (Scopes::has($scope, 'openid')) {
            $out['id_token'] = $this->issuer->idToken($user, $client, $session, $scope, null);
        }
        $this->audit->record('token.refreshed', $user->id, $user->id, $client->id);

        return $out;
    }

    /** @return array<string,mixed> */
    private function clientCredentials(Request $request, Client $client): array
    {
        if ($client->type !== 'confidential') {
            throw new OAuthException('unauthorized_client', 'client_credentials hanya untuk klien confidential');
        }
        $requested = $request->body['scope'] ?? null;
        // Tanpa user: scope identitas (openid/offline_access) tidak berlaku.
        $allowed = implode(' ', array_diff(Scopes::split($client->allowedScopes), ['openid', 'offline_access']));
        if (is_string($requested) && trim($requested) !== '') {
            $scope = (new Scopes())->intersect($requested, $allowed);
            if ($scope === '' || count(Scopes::split($scope)) !== count(Scopes::split($requested))) {
                throw new OAuthException('invalid_scope', 'Scope tidak valid atau tidak diizinkan');
            }
        } else {
            $scope = $allowed;
        }
        $this->audit->record('token.issued', null, null, $client->id, ['grant' => 'client_credentials']);

        return [
            'access_token' => $this->issuer->accessToken(null, $client, $scope, null),
            'token_type' => 'Bearer',
            'expires_in' => $client->accessTokenTtl,
            'scope' => $scope,
        ];
    }

    private function param(Request $request, string $name): string
    {
        $v = $request->body[$name] ?? null;
        if (!is_string($v) || $v === '') {
            throw new OAuthException('invalid_request', 'Parameter ' . $name . ' wajib');
        }

        return $v;
    }
}
