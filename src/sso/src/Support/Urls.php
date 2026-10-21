<?php

declare(strict_types=1);

namespace App\Support;

/** URL absolut untuk tautan di email; basis dari config/app.php['base_url'] (bukan dari header Host). */
final class Urls
{
    public function __construct(private readonly string $base = '')
    {
    }

    public function available(): bool
    {
        return str_starts_with($this->base, 'https://') || str_starts_with($this->base, 'http://localhost');
    }

    public function abs(string $path): string
    {
        return rtrim($this->base, '/') . '/' . ltrim($path, '/');
    }
}
