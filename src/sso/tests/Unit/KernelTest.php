<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ErrorController;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Container;
use App\Support\Logger;
use App\Support\UiSession;
use App\Support\View;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        UiSession::fake();
        $this->logDir = sys_get_temp_dir() . '/bsso-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        UiSession::reset();
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->logDir);
    }

    private function kernel(Router $router): Kernel
    {
        $c = new Container();
        $c->set(View::class, static fn (): View => new View(__DIR__ . '/../../views'));

        return new Kernel($c, $router, new Logger($this->logDir));
    }

    public function testDispatchParameterRuteDanMiddlewareBerurutan(): void
    {
        $router = new Router();
        $router->add('GET', '/hello/{name}', [\Tests\Fixtures\KernelTestController::class, 'hi'], [\Tests\Fixtures\KernelTestA::class, [\Tests\Fixtures\KernelTestB::class, 'b-arg']]);
        $res = $this->kernel($router)->handle(new Request('GET', '/hello/dunia'));
        self::assertSame('A>B(b-arg)>dunia', $res->body);
        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
    }

    public function testPostTanpaCsrfMenjadi419DenganHeaderKeamanan(): void
    {
        $router = new Router();
        $router->add('POST', '/login', [\Tests\Fixtures\KernelTestController::class, 'hi']);
        $res = $this->kernel($router)->handle(new Request('POST', '/login'));
        self::assertSame(419, $res->status);
        self::assertStringContainsString('Formulir kedaluwarsa', $res->body);
        self::assertArrayHasKey('Content-Security-Policy', $res->headers);
    }

    public function testEndpointOauthMengecualikanCsrf(): void
    {
        $router = new Router();
        $router->add('POST', '/token', [\Tests\Fixtures\KernelTestController::class, 'hi']);
        $router->add('POST', '/federation/{slug}/callback', [\Tests\Fixtures\KernelTestController::class, 'hi']);
        $k = $this->kernel($router);
        self::assertSame(200, $k->handle(new Request('POST', '/token'))->status);
        self::assertSame(200, $k->handle(new Request('POST', '/federation/apple/callback'))->status);
    }

    public function test404Dan405(): void
    {
        $router = new Router();
        $router->add('GET', '/a', [\Tests\Fixtures\KernelTestController::class, 'hi']);
        $k = $this->kernel($router);
        self::assertSame(404, $k->handle(new Request('GET', '/b'))->status);
        $r = $k->handle(new Request('GET', '/token'));
        self::assertSame(404, $r->status);
        $router->add('POST', '/token', [\Tests\Fixtures\KernelTestController::class, 'hi']);
        $r = $k->handle(new Request('GET', '/token'));
        self::assertSame(405, $r->status);
        self::assertSame('{"error":"method_not_allowed"}', $r->body);
    }

    public function testExceptionTakTerdugaMenjadi500TanpaKebocoranDanDilog(): void
    {
        $router = new Router();
        $router->add('GET', '/boom', [\Tests\Fixtures\KernelTestController::class, 'boom']);
        $res = $this->kernel($router)->handle(new Request('GET', '/boom'));
        self::assertSame(500, $res->status);
        self::assertStringNotContainsString('rahasia-internal', $res->body);
        $log = (string) file_get_contents((glob($this->logDir . '/app-*.log') ?: [''])[0]);
        self::assertStringContainsString('rahasia-internal', $log);
        self::assertStringNotContainsString('#0', $log);
    }

    public function testErrorControllerKinds(): void
    {
        $c = new ErrorController(new View(__DIR__ . '/../../views'));
        self::assertSame(400, $c->show((new Request('GET', '/'))->withAttribute('kind', 'state'))->status);
        self::assertSame(404, $c->show((new Request('GET', '/'))->withAttribute('kind', 'zzz'))->status);
        self::assertSame(500, $c->forStatus(501)->status);
    }
}
