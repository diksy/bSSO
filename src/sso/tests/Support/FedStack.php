<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Controller\Account\AccountPage;
use App\Controller\Account\LinkedAccountsController;
use App\Controller\FederationController;
use App\Controller\MfaController;
use App\Federation\IdentityLinkCompleter;
use App\Federation\IdentityLinker;
use App\Federation\ProviderContext;
use App\Federation\ProviderRegistry;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FederationStateRepository;
use App\Repository\IdentityProviderRepository;
use App\Repository\Record\User;
use App\Repository\UserIdentityRepository;
use App\Support\HttpClient;
use App\Support\UrlAllowlist;

/** Rangkaian federasi di atas Stack (SQLite) dengan IdP palsu; tanpa jaringan. */
final class FedStack
{
    public Stack $s;
    public FakeIdp $idp;
    public ProviderContext $ctx;
    public ProviderRegistry $registry;
    public IdentityProviderRepository $providers;
    public UserIdentityRepository $identities;
    public FederationStateRepository $states;
    public IdentityLinker $linker;
    public IdentityLinkCompleter $completer;
    public FederationController $fed;
    public LinkedAccountsController $linked;
    public MfaController $mfa;

    /** @param array<string,string> $dnsOverride host => ip */
    public function __construct(array $dnsOverride = [])
    {
        $this->s = $s = new Stack();
        $this->idp = new FakeIdp();
        $allow = new UrlAllowlist(static fn (string $h): array => [$dnsOverride[$h] ?? '93.184.216.34']);
        $this->ctx = new ProviderContext(new HttpClient($allow, $this->idp->transport()), $s->crypto, $s->clock, $s->urls, null);
        $this->providers = new IdentityProviderRepository($s->db);
        $this->identities = new UserIdentityRepository($s->db);
        $this->states = new FederationStateRepository($s->db, $s->clock);
        $this->registry = new ProviderRegistry($this->providers, $this->ctx, [
            'FakeOidcDriver' => FakeOidcDriver::class,
            'FakeOAuth2Driver' => FakeOAuth2Driver::class,
            'AppleDriver' => FakeOidcDriver::class,
        ]);
        $this->linker = new IdentityLinker($s->users, $this->identities, $s->db, $s->clock, $s->audit);
        $this->completer = new IdentityLinkCompleter($this->identities, $s->users, $s->db, $s->clock, $s->audit);
        $this->fed = new FederationController(
            $s->view,
            $this->registry,
            $this->providers,
            $this->states,
            $this->linker,
            $this->completer,
            $s->users,
            $s->manager,
            $s->hasher,
            $s->limiter,
            $s->mailer,
            $s->urls,
            $s->audit,
            $s->clock,
            $s->logger,
        );
        $this->linked = new LinkedAccountsController(
            new AccountPage($s->view, $s->roles),
            $this->providers,
            $this->identities,
            $s->users,
            $s->db,
            $s->audit,
            $s->clock,
        );
        $this->mfa = new MfaController(
            $s->view,
            $s->users,
            $s->totp,
            $s->crypto,
            $s->backup,
            $s->limiter,
            $s->manager,
            $s->audit,
            $s->clock,
            $this->completer,
            $this->providers,
        );
        $this->addProvider('fakeoidc', 'oidc', 'FakeOidcDriver', true);
        $this->addProvider('untrusted', 'oidc', 'FakeOidcDriver', false);
        $this->addProvider('fakeoauth', 'oauth2', 'FakeOAuth2Driver', false);
    }

    public function addProvider(string $slug, string $protocol, string $driver, bool $trust, bool $enabled = true, ?string $discovery = FakeIdp::DISCOVERY): int
    {
        $oauth = $protocol === 'oauth2';
        $this->s->db->pdo()->prepare(
            'INSERT INTO identity_providers (slug, display_name, protocol, driver, client_id, client_secret_enc, discovery_url, authorize_url, '
            . 'token_url, userinfo_url, scopes, trust_email, extra_config, is_enabled, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        )->execute([
            $slug, ucfirst($slug), $protocol, $driver, 'cid', $this->s->crypto->encrypt('shh-secret'),
            $oauth ? null : $discovery, $oauth ? FakeIdp::AUTHORIZE : null, $oauth ? FakeIdp::TOKEN : null,
            $oauth ? FakeIdp::USERINFO : null, 'openid email', $trust ? 1 : 0, null, $enabled ? 1 : 0, 0,
        ]);

        return (int) $this->s->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public function req(string $method, string $path, string $slug, array $query = [], array $body = [], ?string $cookie = null): Request
    {
        $r = new Request($method, $path, $query, $body, ['user-agent' => 'PHPUnit'], $cookie === null ? [] : ['__Host-bsso' => $cookie], [], '198.51.100.7');

        return $r->withAttribute('slug', $slug);
    }

    /**
     * @param array<string,mixed> $query
     * @return array{Response, string, string} respons start, state, nonce
     */
    public function start(string $slug, array $query = [], ?string $cookie = null): array
    {
        $res = $this->fed->start($this->req('GET', "/federation/$slug/start", $slug, $query, [], $cookie));
        $loc = $res->headers['Location'] ?? '';
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);

        return [$res, (string) ($q['state'] ?? ''), (string) ($q['nonce'] ?? '')];
    }

    /**
     * Start lalu callback; IdP membalas id_token dengan klaim $override (nonce otomatis dari start kecuali $nonce diberikan).
     *
     * @param array<string,mixed> $override
     * @param array<string,mixed> $startQuery
     */
    public function oidcLogin(string $slug, array $override = [], ?string $nonce = null, ?string $cookie = null, array $startQuery = []): Response
    {
        [, $state, $n] = $this->start($slug, $startQuery, $cookie);
        $this->idp->idToken = $this->idp->makeIdToken($nonce ?? $n, $override);

        return $this->fed->callback($this->req('GET', "/federation/$slug/callback", $slug, ['state' => $state, 'code' => 'code-1'], [], $cookie));
    }

    public function makeUser(string $email = 'ani@example.test', ?string $password = 'kata-sandi-panjang-1', string $status = 'active', bool $verified = true): User
    {
        $u = $this->s->users->create($email, $password === null ? null : $this->s->hasher->hash($password), 'Ani', $status);
        if ($verified) {
            $this->s->users->markEmailVerified($u->id, $this->s->clock->now());
        }

        return $this->s->users->findById($u->id) ?? $u;
    }

    public function cookieOf(Response $res): ?string
    {
        foreach ($res->cookies as $c) {
            if ($c->name === '__Host-bsso') {
                return $c->value;
            }
        }

        return null;
    }

    public function count(string $table): int
    {
        return (int) $this->s->db->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
