<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Flash;
use App\Support\Markdown;
use App\Support\UiSession;
use App\Support\View;
use PHPUnit\Framework\TestCase;

final class ViewMarkdownTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';

    protected function setUp(): void
    {
        UiSession::fake();
    }

    protected function tearDown(): void
    {
        UiSession::reset();
    }

    public function testMarkdownBintangGandaDiUrlTidakMasukHref(): void
    {
        $html = (new Markdown())->toHtml('[a **b**](https://a.test/**x**/y) dan **tebal**');
        self::assertStringContainsString('href="https://a.test/**x**/y"', $html);
        self::assertStringNotContainsString('href="https://a.test/<strong>', $html);
        self::assertStringContainsString('>a <strong>b</strong></a>', $html);
        self::assertStringContainsString('<strong>tebal</strong>', $html);
    }

    public function testMarkdownEscapeFirstDanHanyaLinkHttp(): void
    {
        $html = (new Markdown())->toHtml("# Judul <script>\n\n<img src=x onerror=alert(1)> **tebal** `<b>`\n\n[ok](https://a.test/x?a=1&b=2) [js](javascript:alert(1)) [rel](/x)\n\n- satu\n- dua\n\n```php\n<?php echo 1;\n```");
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringContainsString('<strong>tebal</strong>', $html);
        self::assertStringContainsString('<code>&lt;b&gt;</code>', $html);
        self::assertStringContainsString('<a href="https://a.test/x?a=1&amp;b=2"', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('href="/x"', $html);
        self::assertStringContainsString('<ul><li>satu</li><li>dua</li></ul>', $html);
        self::assertStringContainsString('&lt;?php echo 1;', $html);
    }

    public function testPanduanIntegrasiTerender(): void
    {
        $html = (new Markdown())->toHtml((string) file_get_contents(__DIR__ . '/../../content/panduan-integrasi.md'));
        self::assertStringContainsString('<h1', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testViewRenderTigaLapisDanEscape(): void
    {
        Flash::add('ok', '<b>sukses</b>');
        $res = (new View(self::VIEWS))->render('auth/error', [
            'title' => 'T<', 'code' => 'c', 'heading' => '<h>', 'text' => '"x"',
        ]);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('<title>T&lt; · BikininSSO</title>', $res->body);
        self::assertStringContainsString('&lt;b&gt;sukses&lt;/b&gt;', $res->body);
        self::assertStringContainsString('&lt;h&gt;', $res->body);
        self::assertStringContainsString('/assets/css/app.css', $res->body);
        self::assertStringNotContainsString('demo', strtolower($res->body));
        self::assertSame([], Flash::pull());
    }

    public function testViewMenolakNamaTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new View(self::VIEWS))->render('../bootstrap');
    }

    public function testViewTidakMemuatInlineScriptAtauStyleDanDemo(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $file) {
            $src = (string) file_get_contents((string) $file);
            $n++;
            self::assertDoesNotMatchRegularExpression('/<script|\sstyle=|\son[a-z]+=|\$_SESSION|\/demo|demo12345|role-switch|expire-stepup/i', $src, (string) $file);
        }
        self::assertGreaterThan(30, $n);
    }

    public function testLayoutAdminMenampilkanSecretSekaliSaja(): void
    {
        UiSession::set('one_time_secret', ['for' => 'Klien A', 'secret' => 's3cr3t<>']);
        $data = ['title' => 'Admin', 'user' => ['email' => 'a@b.test'], 'nav' => ['clients' => ['label' => 'Klien', 'url' => '/admin/clients', 'level' => 'read']],
            'module' => 'clients', 'perm' => 'read', 'role' => 'auditor'];
        $view = new View(self::VIEWS);
        $first = $view->render('admin/forbidden', $data + ['moduleName' => 'Klien'], 'admin');
        self::assertStringContainsString('s3cr3t&lt;&gt;', $first->body);
        self::assertStringContainsString('hanya dapat membaca', $first->body);
        $second = $view->render('admin/forbidden', $data + ['moduleName' => 'Klien'], 'admin');
        self::assertStringNotContainsString('s3cr3t', $second->body);
    }
}
