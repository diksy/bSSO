<?php

declare(strict_types=1);

namespace App\OAuth;

final class Pkce
{
    /** code_verifier: 43-128 karakter [A-Za-z0-9-._~] (RFC 7636 4.1). */
    public static function validVerifier(string $verifier): bool
    {
        return preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) === 1;
    }

    /** code_challenge S256 = BASE64URL(SHA256(verifier)), 43 karakter. */
    public static function validChallenge(string $challenge): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1;
    }

    public function verifyS256(string $verifier, string $challenge): bool
    {
        if (!self::validVerifier($verifier) || !self::validChallenge($challenge)) {
            return false;
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($expected, $challenge);
    }
}
