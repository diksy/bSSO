<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Admin\AdminPolicy;
use App\Http\Cookie;
use App\Http\HttpException;
use App\Http\Middleware\Csrf;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Container;
use App\Support\UiSession;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    protected function setUp(): void
    {
        UiSession::fake();
    }

    protected function tearDown(): void
    {
        UiSession::reset();
    }

    public function testRequestFromArrays(): void
    {
        $r = Request::fromArrays(
            ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/token?x=1', 'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_AUTHORIZATION' => 'Bearer abc.def-1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
            ['x' => '1', 'arr' => ['a']],
            ['grant_type' => 'authorization_code'],
            ['__Host-bsso' => 'v'],
        );
        self::assertSame('POST', $r->method);
        self::assertSame('/token', $r->path);
        self::assertSame('10.0.0.5', $r->ip());
        self::assertSame('abc.def-1', $r->bearerToken());
        self::assertSame('authorization_code', $r->input('grant_type'));
        self::assertSame('1', $r->input('x'));
        self::assertNull($r->input('arr'));
        self::assertSame('d', $r->input('zz', 'd'));
        self::assertSame('v', $r->cookies['__Host-bsso']);
        self::assertSame('application/x-www-form-urlencoded', $r->headers['content-type']);
        self::assertSame(5, $r->withAttribute('a', 5)->attribute('a'));
        self::assertNull($r->attribute('a'));
    }

    public function testResponseHelpers(): void
    {
        $j = Response::json(['a' => '/x'], 201);
        self::assertSame('{"a":"/x"}', $j->body);
        self::assertSame(201, $j->status);
        $r = Response::redirect('/login', 303)->withHeader('x-a', '1')->withHeader('X-A', '2')->withCookie(new Cookie('n', 'v'));
        self::assertSame('/login', $r->headers['Location']);
        self::assertSame(['Location' => '/login', 'X-A' => '2'], $r->headers);
        self::assertCount(1, $r->cookies);
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect("/a\r\nSet-Cookie: x=1");
    }

    public function testRouterMatchAnd404And405(): void
    {
        $router = new Router();
        $router->add('GET', '/federation/{slug}/start', [\stdClass::class, 'a'], ['M']);
        $router->add('POST', '/login', [\stdClass::class, 'b']);
        $m = $router->match(new Request('GET', '/federation/google%20x/start/'));
        self::assertSame(['slug' => 'google x'], $m->params);
        self::assertSame(['M'], $m->middleware);
        foreach ([['GET', '/login', 405], ['GET', '/nope', 404], ['DELETE', '/federation/a/start', 405]] as [$method, $path, $code]) {
            try {
                $router->match(new Request($method, $path));
                self::fail('harus melempar');
            } catch (HttpException $e) {
                self::assertSame($code, $e->status);
            }
        }
    }

    public function testSecurityHeaders(): void
    {
        $res = (new SecurityHeaders())->process(new Request('GET', '/'), static fn (): Response => Response::html('x'));
        self::assertStringContainsString("frame-ancestors 'none'", $res->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('unsafe-inline', $res->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        self::assertSame('no-referrer', $res->headers['Referrer-Policy']);
        self::assertSame('no-store', $res->headers['Cache-Control']);
        self::assertArrayHasKey('Strict-Transport-Security', $res->headers);
        $kept = (new SecurityHeaders())->process(
            new Request('GET', '/jwks'),
            static fn (): Response => Response::json([])->withHeader('cache-control', 'max-age=3600'),
        );
        self::assertSame(['max-age=3600'], [$kept->headers['cache-control']]);
        self::assertArrayNotHasKey('Cache-Control', $kept->headers);
    }

    public function testCsrfMemblokirPostTanpaTokenDanMeloloskanTokenBenar(): void
    {
        $ok = static fn (): Response => Response::html('ok');
        $csrf = new Csrf();
        self::assertSame(200, $csrf->process(new Request('GET', '/login'), $ok)->status);

        foreach ([[], ['_csrf' => 'salah']] as $body) {
            try {
                $csrf->process(new Request('POST', '/login', [], $body), $ok);
                self::fail('harus 419');
            } catch (HttpException $e) {
                self::assertSame(419, $e->status);
            }
        }
        $token = Csrf::currentToken();
        self::assertSame($token, Csrf::currentToken());
        self::assertSame(64, strlen($token));
        self::assertSame(200, $csrf->process(new Request('POST', '/login', [], ['_csrf' => $token]), $ok)->status);
        self::assertSame(200, $csrf->process(new Request('POST', '/login', [], [], ['x-csrf-token' => $token]), $ok)->status);
        self::assertStringContainsString($token, csrf_field());
    }

    public function testCsrfTanpaSesiTidakMeloloskanTokenKosong(): void
    {
        UiSession::reset();
        $_COOKIE = [];
        $this->expectException(HttpException::class);
        (new Csrf())->process(new Request('POST', '/login', [], ['_csrf' => '']), static fn (): Response => Response::html('x'));
    }

    public function testCsrfMengecualikanEndpointKlienDanCallbackFederasi(): void
    {
        $ok = static fn (): Response => Response::html('ok');
        $csrf = new Csrf();
        foreach (['/token', '/introspect', '/revoke', '/userinfo', '/token/', '/federation/apple/callback'] as $path) {
            self::assertSame(200, $csrf->process(new Request('POST', $path), $ok)->status, $path);
        }
        // Tidak dikecualikan: rute lain yang mirip.
        foreach (['/tokens', '/token/x', '/federation/apple/start', '/federation/apple/callback/x', '/federation/apple/confirm-link', '/account', '/admin/stepup', '/logout', '/federation//callback'] as $path) {
            try {
                $csrf->process(new Request('POST', $path), $ok);
                self::fail("harus 419: $path");
            } catch (HttpException $e) {
                self::assertSame(419, $e->status, $path);
            }
        }
        // GET callback bukan POST: aman karena method GET; POST callback dikecualikan hanya untuk POST.
        self::assertFalse(Csrf::isExempt(new Request('PUT', '/federation/apple/callback')));
    }

    public function testContainerMakeDanArgumenRuteTerakhir(): void
    {
        $c = new Container();
        $c->set('x', static fn (): array => ['n' => 1]);
        $n = 0;
        $c->set(\App\Admin\AdminPolicy::class, static function () use (&$n): AdminPolicy {
            $n++;

            return new AdminPolicy();
        });
        self::assertSame($c->get(AdminPolicy::class), $c->get(AdminPolicy::class));
        self::assertSame(1, $n);

        $c->set(\App\Repository\AdminRoleRepository::class, static fn (): object => (new \ReflectionClass(\App\Repository\AdminRoleRepository::class))->newInstanceWithoutConstructor());
        // Dependensi opsional (idle/audit) tidak dibutuhkan untuk menguji pengikatan argumen rute.
        foreach ([\App\Repository\SettingRepository::class, \App\Support\Clock::class, \App\Session\SessionTerminator::class, \App\Session\SsoSessionManager::class, \App\Support\Audit::class] as $optional) {
            $c->set($optional, null);
        }
        $mw = $c->make(RequireAdmin::class, ['users', 'manage']);
        self::assertSame('users', $mw->module);
        self::assertSame('manage', $mw->need);
        self::assertNotSame($mw, $c->make(RequireAdmin::class, ['users', 'manage']));
    }

    public function testUrutanMiddlewareAdminSebelumRecentAuthDiRoutes(): void
    {
        $router = new Router();
        require __DIR__ . '/../../routes.php';
        $routes = new \ReflectionProperty($router, 'routes');
        $count = 0;
        /** @var list<array{method: string, regex: string, middleware: list<mixed>}> $all */
        $all = $routes->getValue($router);
        foreach ($all as $route) {
            $names = array_map(static fn ($e): string => is_array($e) ? (string) $e[0] : (string) $e, $route['middleware']);
            $recent = array_search(RequireRecentAuth::class, $names, true);
            if ($recent === false) {
                continue;
            }
            $count++;
            $admin = array_search(RequireAdmin::class, $names, true);
            self::assertNotFalse($admin, $route['regex']);
            self::assertLessThan($recent, $admin, $route['regex']);
            self::assertSame('POST', $route['method']);
            self::assertSame(\App\Http\Middleware\RequireSsoSession::class, $names[0]);
        }
        self::assertGreaterThan(10, $count);
        self::assertContains(Middleware::class, class_implements(RequireRecentAuth::class) ?: []);
    }
}
