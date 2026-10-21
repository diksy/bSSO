<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\Response;

final class View
{
    /** Layout: auth | account | admin | plain */
    public static function render(string $view, array $data = [], string $layout = 'auth', int $status = 200): Response
    {
        $content = self::capture('/' . $view . '.php', $data);
        $data['content'] = $content;
        $inner = self::capture('/layout/' . $layout . '.php', $data);
        $data['inner'] = $inner;
        $html = self::capture('/layout/base.php', $data);
        return new Response($html, $status);
    }

    private static function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require SSO_ROOT . '/views' . $file;
        return (string) ob_get_clean();
    }
}
