<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Federation\Drivers\AppleDriver;
use App\Federation\Drivers\DiscordDriver;
use App\Federation\Drivers\FacebookDriver;
use App\Federation\Drivers\GitHubDriver;
use App\Federation\Drivers\GitLabDriver;
use App\Federation\Drivers\GoogleDriver;
use App\Federation\Drivers\LinkedInDriver;
use App\Federation\Drivers\MicrosoftDriver;
use App\Federation\Drivers\XDriver;
use App\Federation\FederationException;
use App\Federation\FederationState;
use App\Federation\NormalizedProfile;
use App\Federation\ProviderContext;
use App\Repository\Record\IdentityProvider;
use App\Support\HttpClient;
use App\Support\HttpResult;
use App\Support\UrlAllowlist;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Tests\Support\FedStack;

/** mapProfile per driver dengan fixture payload (tanpa jaringan). */
final class FederationDriversTest extends TestCase
{
    /** @param array<string,mixed> $payload */
    private function map(string $class, array $payload): NormalizedProfile
    {
        $driver = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($driver, 'mapProfile');

        return $m->invoke($driver, $payload);
    }

    /** @param array<string,mixed> $extra */
    private function config(string $driver, array $extra = [], ?string $secretEnc = null, string $proto = 'oidc'): IdentityProvider
    {
        return new IdentityProvider(1, 'p', 'P', $proto, $driver, 'cid', $secretEnc, null, null, null, 'https://api.github.com/user', 'x', false, $extra, true, 0);
    }

    public function testGoogle(): void
    {
        $p = $this->map(GoogleDriver::class, ['sub' => '1', 'email' => 'a@x.test', 'email_verified' => true, 'name' => 'A', 'picture' => 'https://p/x']);
        self::assertSame(['1', 'a@x.test', true, 'A'], [$p->providerUserId, $p->email, $p->emailVerified, $p->name]);
        self::assertFalse($this->map(GoogleDriver::class, ['sub' => '1', 'email' => 'a@x.test', 'email_verified' => false])->emailVerified);
        self::assertFalse($this->map(GoogleDriver::class, ['sub' => '1', 'email' => 'a@x.test'])->emailVerified, 'klaim hilang = tak terverifikasi');
        self::assertFalse($this->map(GoogleDriver::class, ['sub' => '1', 'email' => 'a@x.test', 'email_verified' => 1])->emailVerified);
        $n = $this->map(GoogleDriver::class, ['sub' => '1', 'email_verified' => true]);
        self::assertNull($n->email);
        self::assertFalse($n->emailVerified);
    }

    public function testGitLabDanLinkedIn(): void
    {
        $g = $this->map(GitLabDriver::class, ['sub' => '9', 'email' => 'g@x.test', 'email_verified' => true, 'nickname' => 'gl']);
        self::assertTrue($g->emailVerified);
        self::assertSame('gl', $g->name);
        self::assertFalse($this->map(GitLabDriver::class, ['sub' => '9', 'email' => 'g@x.test'])->emailVerified);

        $l = $this->map(LinkedInDriver::class, ['sub' => 'L', 'email' => 'l@x.test', 'email_verified' => 'true', 'name' => 'Li']);
        self::assertTrue($l->emailVerified);
        self::assertFalse($this->map(LinkedInDriver::class, ['sub' => 'L', 'email' => 'l@x.test', 'email_verified' => 'false'])->emailVerified);
        self::assertFalse($this->map(LinkedInDriver::class, ['sub' => 'L', 'email' => 'l@x.test', 'email_verified' => 'yes'])->emailVerified);
    }

    public function testMicrosoftTidakPernahTerverifikasiDanIdGabungan(): void
    {
        $tid = '9188040d-6c67-4c5b-b112-36a304b66dad';
        $p = $this->map(MicrosoftDriver::class, ['sub' => 's', 'tid' => $tid, 'oid' => 'oid-1', 'email' => 'm@x.test', 'email_verified' => true, 'name' => 'M']);
        self::assertSame($tid . '.oid-1', $p->providerUserId);
        self::assertSame('m@x.test', $p->email);
        self::assertFalse($p->emailVerified);
        $this->expectException(FederationException::class);
        $this->map(MicrosoftDriver::class, ['sub' => 's', 'tid' => $tid]);
    }

    public function testMicrosoftIssuerPolaTenant(): void
    {
        $f = new FedStack();
        $driver = new MicrosoftDriver($this->config('MicrosoftDriver'), $f->ctx);
        $m = new \ReflectionMethod($driver, 'issuerMatches');
        $tid = '9188040d-6c67-4c5b-b112-36a304b66dad';
        $ok = static fn (string $iss, array $c): bool => $m->invoke($driver, $iss, $c);
        self::assertTrue($ok("https://login.microsoftonline.com/$tid/v2.0", ['tid' => $tid]));
        self::assertFalse($ok("https://login.microsoftonline.com/$tid/v2.0", ['tid' => '11111111-1111-1111-1111-111111111111']));
        self::assertFalse($ok('https://login.microsoftonline.com/common/v2.0', ['tid' => 'common']));
        self::assertFalse($ok("https://evil.test/$tid/v2.0", ['tid' => $tid]));
        self::assertFalse($ok("https://login.microsoftonline.com/$tid/v2.0", []));
    }

    public function testApple(): void
    {
        $p = $this->map(AppleDriver::class, ['sub' => 'a.1', 'email' => 'x@privaterelay.appleid.com', 'email_verified' => 'true', 'is_private_email' => 'true']);
        self::assertTrue($p->emailVerified);
        self::assertTrue($p->raw['is_private_email']);
        self::assertTrue($this->map(AppleDriver::class, ['sub' => 'a.1', 'email' => 'a@x.test', 'email_verified' => true])->emailVerified);
        self::assertFalse($this->map(AppleDriver::class, ['sub' => 'a.1', 'email' => 'a@x.test', 'email_verified' => 'false'])->emailVerified);
        self::assertFalse($this->map(AppleDriver::class, ['sub' => 'a.1', 'email' => 'a@x.test'])->emailVerified);
        self::assertNull($this->map(AppleDriver::class, ['sub' => 'a.1'])->email);
    }

    public function testAppleNamaDariStatePendingHanyaLoginPertama(): void
    {
        $driver = (new \ReflectionClass(AppleDriver::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($driver, 'mapProfileWithState');
        $claims = ['sub' => 'a.1', 'email' => 'a@x.test', 'email_verified' => true];
        $with = $m->invoke($driver, $claims, new FederationState(1, 'n', null, ['user' => ['name' => ['firstName' => 'Budi', 'lastName' => 'S']]], null));
        $without = $m->invoke($driver, $claims, new FederationState(1, 'n', null, [], null));
        self::assertSame('Budi S', $with->name);
        self::assertNull($without->name);
    }

    public function testAppleClientSecretEs256DanFormPost(): void
    {
        $f = new FedStack();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $pub = openssl_pkey_get_details($key)['key'];
        $cfg = $this->config('AppleDriver', ['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567'], $f->s->crypto->encrypt($pem));
        $driver = new AppleDriver($cfg, $f->ctx);
        $m = new \ReflectionMethod($driver, 'clientSecret');
        $jwt = $m->invoke($driver);
        JWT::$timestamp = $f->s->clock->now()->getTimestamp();
        try {
            $claims = (array) JWT::decode($jwt, new Key($pub, 'ES256'));
        } finally {
            JWT::$timestamp = null;
        }
        self::assertSame('TEAM123456', $claims['iss']);
        self::assertSame('cid', $claims['sub']);
        self::assertSame('https://appleid.apple.com', $claims['aud']);
        self::assertSame($f->s->clock->now()->getTimestamp() + 300, $claims['exp']);
        self::assertSame('KEY1234567', json_decode((string) JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true)['kid']);
        $ap = new \ReflectionMethod($driver, 'authorizeParams');
        self::assertSame(['response_mode' => 'form_post'], $ap->invoke($driver));

        $bad = new AppleDriver($this->config('AppleDriver', [], $f->s->crypto->encrypt($pem)), $f->ctx);
        $this->expectException(FederationException::class);
        $m->invoke($bad);
    }

    public function testGitHubHanyaEmailPrimaryDanVerified(): void
    {
        $emails = [
            ['email' => 'old@x.test', 'primary' => false, 'verified' => true],
            ['email' => 'main@x.test', 'primary' => true, 'verified' => true],
        ];
        $p = $this->map(GitHubDriver::class, ['id' => 42, 'login' => 'octo', 'avatar_url' => 'https://a/x', '_emails' => $emails, 'email' => 'public@x.test']);
        self::assertSame('42', $p->providerUserId);
        self::assertSame('main@x.test', $p->email);
        self::assertTrue($p->emailVerified);
        self::assertSame('octo', $p->name);

        $unv = $this->map(GitHubDriver::class, ['id' => 42, 'email' => 'public@x.test', '_emails' => [['email' => 'u@x.test', 'primary' => true, 'verified' => false]]]);
        self::assertNull($unv->email, 'email publik /user dan primary tak terverifikasi diabaikan');
        self::assertFalse($unv->emailVerified);
        self::assertNull($this->map(GitHubDriver::class, ['id' => 42])->email);
        self::assertNull($this->map(GitHubDriver::class, ['id' => 42, '_emails' => [['email' => 'u@x.test', 'primary' => true, 'verified' => 'true']]])->email);
    }

    /**
     * @param list<HttpResult> $answers
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private function githubFetch(array $answers): array
    {
        $f = new FedStack();
        $urls = [];
        $transport = static function (array $reqs) use (&$answers, &$urls): array {
            $out = [];
            foreach ($reqs as $k => $r) {
                $urls[] = $r['url'];
                $out[$k] = array_shift($answers);
            }

            return $out;
        };
        $ctx = new ProviderContext(new HttpClient(new UrlAllowlist(static fn (): array => ['93.184.216.34']), $transport), $f->s->crypto, $f->s->clock, $f->s->urls, null);
        $d = new GitHubDriver($this->config('GitHubDriver', [], null, 'oauth2'), $ctx);
        $m = new \ReflectionMethod($d, 'fetchUserinfo');

        return [$m->invoke($d, 'tok'), $urls];
    }

    public function testGitHubFetchMemanggilEndpointEmailsDariKode(): void
    {
        [$u, $urls] = $this->githubFetch([
            new HttpResult(200, '{"id":7,"login":"o"}'),
            new HttpResult(200, '[{"email":"m@x.test","primary":true,"verified":true}]'),
        ]);
        self::assertSame(['https://api.github.com/user', 'https://api.github.com/user/emails'], $urls);
        self::assertSame('m@x.test', $this->map(GitHubDriver::class, $u)->email);
    }

    public function testGitHubFetchEmailGagalTidakMenggagalkanLogin(): void
    {
        [$u] = $this->githubFetch([new HttpResult(200, '{"id":7,"login":"o","email":"p@x.test"}'), new HttpResult(403, '')]);
        $p = $this->map(GitHubDriver::class, $u);
        self::assertNull($p->email);
        self::assertFalse($p->emailVerified);
    }

    public function testFacebookTidakPernahTerverifikasi(): void
    {
        $p = $this->map(FacebookDriver::class, ['id' => '1', 'name' => 'F', 'email' => 'f@x.test', 'picture' => ['data' => ['url' => 'https://p/f.jpg']]]);
        self::assertSame('f@x.test', $p->email);
        self::assertFalse($p->emailVerified);
        self::assertSame('https://p/f.jpg', $p->avatarUrl);
        $n = $this->map(FacebookDriver::class, ['id' => '1', 'name' => 'F']);
        self::assertNull($n->email);
        self::assertNull($n->avatarUrl);
    }

    public function testDiscord(): void
    {
        $base = ['id' => '80351110224678912', 'username' => 'nelly', 'global_name' => 'Nelly', 'avatar' => '8342729096ea3675442027381ff50dfe', 'email' => 'n@x.test'];
        $v = $this->map(DiscordDriver::class, $base + ['verified' => true]);
        self::assertTrue($v->emailVerified);
        self::assertSame('Nelly', $v->name);
        self::assertSame('https://cdn.discordapp.com/avatars/80351110224678912/8342729096ea3675442027381ff50dfe.png', $v->avatarUrl);
        self::assertFalse($this->map(DiscordDriver::class, $base + ['verified' => false])->emailVerified);
        self::assertFalse($this->map(DiscordDriver::class, $base)->emailVerified);
        self::assertFalse($this->map(DiscordDriver::class, $base + ['verified' => 'true'])->emailVerified);
        self::assertNull($this->map(DiscordDriver::class, ['id' => '5', 'avatar' => '../../x', 'verified' => true])->avatarUrl);
        $n = $this->map(DiscordDriver::class, ['id' => '5', 'username' => 'u', 'verified' => true]);
        self::assertNull($n->email);
        self::assertFalse($n->emailVerified);
    }

    public function testX(): void
    {
        $p = $this->map(XDriver::class, ['data' => ['id' => '2244994945', 'name' => 'X Dev', 'username' => 'xdev', 'profile_image_url' => 'https://pbs/x.jpg']]);
        self::assertSame('2244994945', $p->providerUserId);
        self::assertNull($p->email);
        self::assertFalse($p->emailVerified);
        self::assertSame('X Dev', $p->name);
        self::assertSame('', $this->map(XDriver::class, ['data' => ['username' => 'a']])->providerUserId, 'id kosong ditolak ProfileGuard');
    }
}
