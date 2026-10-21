<?php

declare(strict_types=1);

namespace App\OAuth;

final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $errorDescription,
        public readonly int $status = 400,
    ) {
        parent::__construct($errorDescription, $status);
    }
}
