<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ConfigLoader;
use App\Support\DotEnv;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $touched = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bsso_cfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        foreach ($this->touched as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    /** @param array<string,string> $env */
    private function loader(array $env): ConfigLoader
    {
        return new ConfigLoader($this->dir, static fn (string $k): string|false => $env[$k] ?? false);
    }

    public function testParserBasics(): void
    {
        $v = DotEnv::parse(<<<'ENV'
# komentar
A=1
export B=dua
C="x y\n\"q\" \\ #bukan"
D='lit\n #ok'
E=nilai # komentar
F=a#b
G="tutup" # kom
H=

BAD LINE
1X=salah
I="tidak ditutup
J=v1:abc==
ENV);
        self::assertSame('1', $v['A']);
        self::assertSame('dua', $v['B']);
        self::assertSame("x y\n\"q\" \\ #bukan", $v['C']);
        self::assertSame('lit\n #ok', $v['D']);
        self::assertSame('nilai', $v['E']);
        self::assertSame('a#b', $v['F']);
        self::assertSame('tutup', $v['G']);
        self::assertSame('', $v['H']);
        self::assertSame('v1:abc==', $v['J']);
        self::assertArrayNotHasKey('1X', $v);
        self::assertArrayNotHasKey('I', $v);
        self::assertCount(9, $v);
    }

    public function testApplyDoesNotOverrideExisting(): void
    {
        $this->touched = ['BSSO_T_EXIST', 'BSSO_T_NEW'];
        putenv('BSSO_T_EXIST=server');
        DotEnv::apply(['BSSO_T_EXIST' => 'file', 'BSSO_T_NEW' => 'baru']);
        self::assertSame('server', getenv('BSSO_T_EXIST'));
        self::assertSame('baru', getenv('BSSO_T_NEW'));
        self::assertSame('baru', $_ENV['BSSO_T_NEW']);
        self::assertSame('baru', $_SERVER['BSSO_T_NEW']);
    }

    public function testLoadMissingFileIsNoop(): void
    {
        self::assertSame(0, DotEnv::load($this->dir . '/tidak-ada.env'));
    }

    public function testDefaultsWithoutAnyFile(): void
    {
        $db = $this->loader([])->database();
        self::assertSame(['host' => '127.0.0.1', 'port' => 3306, 'name' => 'bikinin_sso', 'user' => '', 'pass' => ''], $db);
        $app = $this->loader([])->app();
        self::assertFalse($app['debug']);
        self::assertSame([], $app['trusted_proxies']);
    }

    public function testPrecedenceEnvOverFilePerKey(): void
    {
        file_put_contents($this->dir . '/database.php', "<?php return ['host' => 'filehost', 'port' => 3307, 'name' => 'filedb'];");
        file_put_contents($this->dir . '/app.php', "<?php return ['debug' => true, 'base_url' => 'https://file'];");
        $db = $this->loader(['DB_HOST' => 'envhost', 'DB_PORT' => '3310'])->database();
        self::assertSame('envhost', $db['host']);
        self::assertSame(3310, $db['port']);
        self::assertSame('filedb', $db['name']);
        $app = $this->loader(['APP_DEBUG' => 'off'])->app();
        self::assertFalse($app['debug']);
        self::assertSame('https://file', $app['base_url']);
    }

    public function testEmptyEnvDoesNotOverride(): void
    {
        file_put_contents($this->dir . '/app.php', "<?php return ['base_url' => 'https://file', 'trusted_proxies' => ['1.2.3.4']];");
        $app = $this->loader(['APP_BASE_URL' => '', 'TRUSTED_PROXIES' => ' '])->app();
        self::assertSame('https://file', $app['base_url']);
        self::assertSame(['1.2.3.4'], $app['trusted_proxies']);
    }

    public function testTrustedProxiesAndDebugParsing(): void
    {
        $app = $this->loader(['TRUSTED_PROXIES' => ' 10.0.0.0/8, ,127.0.0.1 ,', 'APP_DEBUG' => 'Yes'])->app();
        self::assertSame(['10.0.0.0/8', '127.0.0.1'], $app['trusted_proxies']);
        self::assertTrue($app['debug']);
    }

    public function testSecretsFromEnv(): void
    {
        $s = $this->loader([
            'HMAC_KEY' => 'h==',
            'ENCRYPTION_KEY_CURRENT' => 'v2',
            'ENCRYPTION_KEYS' => 'v1:aaa==, v2:bbb=',
        ])->secrets();
        self::assertSame('h==', $s['hmac_key']);
        self::assertSame('v2', $s['keyring']['current']);
        self::assertSame(['v1' => 'aaa==', 'v2' => 'bbb='], $s['keyring']['keys']);
    }

    public function testSecretsMergePerKeyWithFile(): void
    {
        file_put_contents($this->dir . '/secrets.php', "<?php return ['hmac_key' => 'fh', 'keyring' => ['current' => 'v1', 'keys' => ['v1' => 'fk']]];");
        $s = $this->loader(['HMAC_KEY' => 'eh'])->secrets();
        self::assertSame('eh', $s['hmac_key']);
        self::assertSame(['v1' => 'fk'], $s['keyring']['keys']);
        self::assertSame('fh', $this->loader([])->secrets()['hmac_key']);
    }

    /** @return array<string,array{array<string,string>}> */
    public static function incompleteSecrets(): array
    {
        return [
            'kosong' => [[]],
            'tanpa hmac' => [['ENCRYPTION_KEY_CURRENT' => 'v1', 'ENCRYPTION_KEYS' => 'v1:a']],
            'tanpa keyring' => [['HMAC_KEY' => 'h']],
            'current tak ada' => [['HMAC_KEY' => 'h', 'ENCRYPTION_KEY_CURRENT' => 'v9', 'ENCRYPTION_KEYS' => 'v1:a']],
            'kunci kosong' => [['HMAC_KEY' => 'h', 'ENCRYPTION_KEY_CURRENT' => 'v1', 'ENCRYPTION_KEYS' => 'v1:']],
        ];
    }

    /** @param array<string,string> $env */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteSecrets')]
    public function testSecretsFailClosed(array $env): void
    {
        try {
            $this->loader($env)->secrets();
            self::fail('harus melempar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HMAC_KEY', $e->getMessage());
            self::assertStringContainsString('config/secrets.php', $e->getMessage());
        }
    }
}
