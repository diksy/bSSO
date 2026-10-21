<?php

declare(strict_types=1);

namespace App\Support;

final class Markdown
{
    /** Escape dulu; subset heading/list/code/link http(s) saja. */
    public function toHtml(string $markdown): string
    {
        throw new \LogicException('belum diimplementasi');
    }
}
