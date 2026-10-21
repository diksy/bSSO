<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Http\Response;

/** Respons JSON endpoint OAuth: selalu Cache-Control no-store + Pragma no-cache. */
final class JsonResponses
{
    /** @param array<string,mixed> $data */
    public static function ok(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    public static function error(OAuthException $e): Response
    {
        $response = self::ok(['error' => $e->error, 'error_description' => $e->errorDescription], $e->status);
        if ($e->status === 401 && $e->error === 'invalid_client') {
            $response = $response->withHeader('WWW-Authenticate', 'Basic realm="bikinin-sso", charset="UTF-8"');
        }

        return $response;
    }

    public static function bearerError(string $error, string $description, int $status): Response
    {
        $header = 'Bearer realm="bikinin-sso", error="' . $error . '", error_description="' . str_replace(['"', '\\'], '', $description) . '"';

        return self::ok(['error' => $error, 'error_description' => $description], $status)->withHeader('WWW-Authenticate', $header);
    }
}
