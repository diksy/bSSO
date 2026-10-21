<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Admin\AdminPolicy;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Support\FrozenClock;
use App\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class ContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** @return list<class-string> */
    private function classes(): array
    {
        $out = [];
        $base = (string) realpath(self::ROOT . 'src');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || basename($path) === 'helpers.php') {
                continue;
            }
            $rel = substr($path, strlen($base) + 1, -4);
            $out[] = 'App\\' . str_replace('/', '\\', $rel);
        }

        return $out;
    }

    public function testSemuaClassKontrakTermuat(): void
    {
        $classes = $this->classes();
        self::assertGreaterThan(100, count($classes));
        foreach ($classes as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "Tidak termuat: $class",
            );
        }
    }

    public function testSetiapHandlerRuteAdaDanMenerimaRequest(): void
    {
        $source = (string) file_get_contents(self::ROOT . 'routes.php');
        preg_match_all("/add\('([A-Z]+)', '([^']+)', \[(\w+)::class, '(\w+)'\]/", $source, $routes, PREG_SET_ORDER);
        self::assertGreaterThan(80, count($routes));
        foreach ($routes as [, $method, $path, $class, $action]) {
            $fqcn = $this->resolveController($class);
            self::assertTrue(method_exists($fqcn, $action), "$method $path -> $class::$action tidak ada");
            $ref = new \ReflectionMethod($fqcn, $action);
            self::assertSame(Request::class, (string) $ref->getParameters()[0]->getType());
        }
    }

    private function resolveController(string $short): string
    {
        foreach (['App\\Controller\\', 'App\\Controller\\Account\\', 'App\\Controller\\Admin\\'] as $ns) {
            if (class_exists($ns . $short)) {
                return $ns . $short;
            }
        }
        self::fail("Controller tidak ditemukan: $short");
    }

    public function testControllerStubMelempar501(): void
    {
        $controller = new \App\Controller\LoginController();
        try {
            $controller->form(new Request('GET', '/login'));
            self::fail('Harus melempar HttpException');
        } catch (HttpException $e) {
            self::assertSame(501, $e->status);
        }
    }

    public function testRoutesTerdaftarDiRouter(): void
    {
        $router = new Router();
        require self::ROOT . 'routes.php';
        self::assertSame(\App\Controller\LoginController::class, $router->match(new Request('GET', '/login'))->handler[0]);
    }

    public function testMatriksAdminSesuaiRequirements(): void
    {
        $m = AdminPolicy::MATRIX;
        self::assertSame(['superadmin', 'operator', 'auditor'], array_keys($m));
        self::assertSame('manage', $m['superadmin']['roles']);
        self::assertSame('none', $m['operator']['roles']);
        self::assertSame('none', $m['auditor']['roles']);
        self::assertSame('none', $m['auditor']['maintenance']);
        self::assertSame('read', $m['operator']['audit']);
        self::assertSame('manage', $m['auditor']['audit']);
        self::assertSame('read', $m['auditor']['settings']);
    }

    public function testClock(): void
    {
        $c = new FrozenClock('2026-01-01 00:00:00');
        $c->advance('+2 days');
        self::assertSame('2026-01-03', $c->now()->format('Y-m-d'));
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    public function testHelperEscape(): void
    {
        self::assertSame('&lt;a&gt; &amp; &quot;x&quot; &#039;y&#039;', e('<a> & "x" \'y\''));
    }
}
