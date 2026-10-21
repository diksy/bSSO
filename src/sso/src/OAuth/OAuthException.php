<?php

declare(strict_types=1);

namespace App\OAuth;

final class OAuthException extends \RuntimeException
{
    /**
     * @param string|null $redirectUri bila diisi (hanya setelah redirect_uri tervalidasi), /authorize me-redirect error ke sini
     */
    public function __construct(
        public readonly string $error,
        public readonly string $errorDescription,
        public readonly int $status = 400,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($errorDescription, $status);
    }
}
