<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Response;

final class View
{
    private const LAYOUTS = ['auth', 'account', 'admin', 'plain'];

    public function __construct(private readonly string $viewsPath)
    {
    }

    /**
     * Layout: 'auth' | 'account' | 'admin' | 'plain'. Variabel layout: $title, $user, $flashes,
     * $oneTimeSecret; admin: $nav, $module, $perm, $role. View tidak membaca $_SESSION.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $view, array $data = [], string $layout = 'auth', int $status = 200): Response
    {
        if (preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', $view) !== 1 || !in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException('View atau layout tidak valid');
        }
        $data['flashes'] ??= Flash::pull();
        if ($layout === 'admin') {
            $data['oneTimeSecret'] ??= UiSession::pull('one_time_secret');
        }
        unset($data['content'], $data['inner']);

        $data['content'] = $this->capture('/' . $view . '.php', $data);
        $data['inner'] = $this->capture('/layout/' . $layout . '.php', $data);

        return Response::html($this->capture('/layout/base.php', $data), $status);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $file, array $data): string
    {
        $path = $this->viewsPath . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('View tidak ditemukan: ' . $file);
        }
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__path, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__path;
            })($path, $data);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
