<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Response;

final class View
{
    /**
     * Layout: 'auth' | 'account' | 'admin' | 'plain'. Variabel layout: $title, $user, $flashes,
     * $oneTimeSecret; admin: $nav, $module, $perm, $role. View tidak membaca $_SESSION.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $view, array $data = [], string $layout = 'auth', int $status = 200): Response
    {
        throw new \LogicException('belum diimplementasi');
    }
}
