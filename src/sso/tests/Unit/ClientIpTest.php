<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ClientIp;
use App\Http\Request;
use App\Support\Audit;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    /** @param array<mixed> $trusted */
    private function ip(string $remote, ?string $xff, array $trusted): string
    {
        $s = ['REMOTE_ADDR' => $remote];
        if ($xff !== null) {
            $s['HTTP_X_FORWARDED_FOR'] = $xff;
        }

        return Request::fromArrays($s, [], [], [], $trusted)->ip();
    }

    public function testDefaultIgnoresXff(): void
    {
        self::assertSame('198.51.100.1', $this->ip('198.51.100.1', '1.2.3.4', []));
    }

    public function testSpoofFromNonProxyIgnored(): void
    {
        self::assertSame('198.51.100.1', $this->ip('198.51.100.1', '1.2.3.4', ['10.0.0.0/8']));
    }

    public function testTrustedProxyUsesXff(): void
    {
        self::assertSame('203.0.113.7', $this->ip('10.0.0.5', '203.0.113.7', ['10.0.0.0/8']));
    }

    public function testMultiProxyChainAntiSpoof(): void
    {
        // klien memalsukan 1.1.1.1 di kiri; hop asli 203.0.113.7 ditambahkan proxy
        self::assertSame('203.0.113.7', $this->ip('10.0.0.5', '1.1.1.1, 203.0.113.7, 10.0.0.9', ['10.0.0.0/8']));
    }

    public function testIpv6AndCidr(): void
    {
        self::assertSame('2001:db8::5', $this->ip('2400:cb00::1', '2001:db8::5', ['2400:cb00::/32']));
        self::assertSame('2400:cb01::1', $this->ip('2400:cb01::1', '2001:db8::5', ['2400:cb00::/32']));
        self::assertSame('9.9.9.9', $this->ip('192.168.1.200', '9.9.9.9', ['192.168.1.128/25']));
        self::assertSame('192.168.1.100', $this->ip('192.168.1.100', '9.9.9.9', ['192.168.1.128/25']));
    }

    public function testMappedIpv4MatchesIpv4Cidr(): void
    {
        self::assertSame('203.0.113.7', $this->ip('::ffff:10.0.0.5', '203.0.113.7', ['10.0.0.0/8']));
        self::assertSame('203.0.113.7', $this->ip('10.0.0.5', '::ffff:203.0.113.7', ['10.0.0.0/8']));
        self::assertSame('198.51.100.1', $this->ip('::ffff:198.51.100.1', '1.2.3.4', ['10.0.0.0/8']));
    }

    public function testCidrExtremes(): void
    {
        self::assertSame('9.9.9.9', $this->ip('10.0.0.5', '9.9.9.9', ['10.0.0.5/32']));
        self::assertSame('10.0.0.6', $this->ip('10.0.0.6', '9.9.9.9', ['10.0.0.5/32']));
        // /0 mempercayai semua hop, jadi fail-closed ke REMOTE_ADDR
        self::assertSame('10.0.0.6', $this->ip('10.0.0.6', '9.9.9.9', ['0.0.0.0/0']));
    }

    public function testAllHopsTrustedFailsClosedToRemote(): void
    {
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', '10.0.0.7, 10.0.0.9', ['10.0.0.0/8']));
    }

    public function testGarbageFallsBackToRemote(): void
    {
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', 'garbage', ['10.0.0.5']));
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', '1.2.3.4, bukan-ip', ['10.0.0.5']));
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', '', ['10.0.0.5']));
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', '10.0.0.5', ['10.0.0.5']));
    }

    public function testInvalidConfigEntriesIgnored(): void
    {
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', '1.2.3.4', ['x', '10.0.0.0/99', '10.0.0.0/8/1', '']));
        self::assertSame('10.0.0.5', $this->ip('10.0.0.5', '1.2.3.4', [123, null]));
    }

    public function testXRealIpNotTrusted(): void
    {
        $r = Request::fromArrays(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_REAL_IP' => '1.2.3.4'], [], [], [], ['10.0.0.5']);
        self::assertSame('10.0.0.5', $r->ip());
    }

    public function testAuditUsesSameIpAsRequest(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.7'];
        $trusted = ['10.0.0.0/8'];
        $backup = $_SERVER;
        $_SERVER = $server;
        try {
            $m = new \ReflectionMethod(Audit::class, 'ip');
            $audit = (new \ReflectionClass(Audit::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(Audit::class, 'trustedProxies'))->setValue($audit, $trusted);
            (new \ReflectionProperty(Audit::class, 'ip'))->setValue($audit, null);
            $expected = Request::fromArrays($server, [], [], [], $trusted)->ip();
            self::assertSame('203.0.113.7', $expected);
            self::assertSame($expected, PHP_SAPI === 'cli' ? ClientIp::resolve($_SERVER, $trusted) : $m->invoke($audit));
        } finally {
            $_SERVER = $backup;
        }
    }
}
