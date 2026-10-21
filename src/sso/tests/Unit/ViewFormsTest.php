<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class ViewFormsTest extends TestCase
{
    public function testActionFormDiViewCocokDenganRute(): void
    {
        $router = new Router();
        require __DIR__ . '/../../routes.php';
        $files = glob(__DIR__ . '/../../views/*/*.php') ?: [];
        $checked = 0;
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // ambil <form ... method=".." action=".."> dan lambda $act pada user_detail
            preg_match_all('/<form[^>]*?method="(get|post)"[^>]*?action="([^"]+)"/i', $src, $m, PREG_SET_ORDER);
            foreach ($m as [, $method, $action]) {
                if (str_contains($action, "' . \$a . '")) {
                    preg_match_all("/\\\$act\\('([a-z-]+)'/", $src, $names);
                    foreach ($names[1] as $n) {
                        $router->match(new Request('POST', '/admin/users/x/' . $n));
                    }
                    $checked++;
                    continue;
                }
                $ok = false;
                if ($action === '/admin/maintenance/<?= e($a) ?>') {
                    preg_match_all("/\\['([a-z-]+)', '/", $src, $names);
                    foreach ($names[1] as $n) {
                        $router->match(new Request('POST', '/admin/maintenance/' . $n));
                    }
                    $checked++;
                    continue;
                }
                foreach (['x', ''] as $fill) { // '' untuk segmen opsional (mis. create vs edit)
                    $path = preg_replace('/<\?=.*?\?>/s', $fill, $action) ?? $action;
                    $path = str_replace("' . \$a . '", 'x', $path);
                    $path = preg_replace("/' \. .*? \. '/", 'x', $path) ?? $path;
                    try {
                        $router->match(new Request(strtoupper($method), $path));
                        $ok = true;
                        break;
                    } catch (\Throwable) {
                    }
                }
                self::assertTrue($ok, basename($file) . ': ' . strtoupper($method) . ' ' . $action . ' tidak cocok rute');
                $checked++;
            }
        }
        self::assertGreaterThan(20, $checked);
    }

    public function testHtaccessMengecualikanWellKnown(): void
    {
        $ht = (string) file_get_contents(__DIR__ . '/../../../public_html/.htaccess');
        self::assertMatchesRegularExpression('#RewriteCond %\{REQUEST_URI\} !\^/\\\\\.well-known/\s+RewriteRule \(\^\|/\)\\\\\. - \[F\]#', $ht);
    }
}
