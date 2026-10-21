<?php

declare(strict_types=1);

use App\Federation\ProviderContext;
use App\Federation\ProviderRegistry;
use App\Http\Kernel;
use App\Http\Router;
use App\Repository\AdminRoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\AuthCodeRepository;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\FederationStateRepository;
use App\Repository\IdentityProviderRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\LogoutNotificationRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\SessionClientRepository;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Security\Crypto;
use App\Security\KeyManager;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SignedTokens;
use App\OAuth\TokenIssuer;
use App\Session\BackchannelNotifier;
use App\Session\LogoutService;
use App\Support\CronRunner;
use App\Support\Gc;
use App\Support\HttpClient;
use App\Support\UrlAllowlist;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Urls;
use App\Session\SessionTerminator;
use App\Support\Clock;
use App\Support\Container;
use App\Support\Database;
use App\Support\ErrorHandler;
use App\Support\Logger;
use App\Support\View;
use App\Support\Mailer;
use App\Support\PhpMailer;
use App\Support\SystemClock;

/**
 * Bootstrap: membangun container (array factory, lazy). Tidak menjalankan request;
 * public_html/index.php memanggil Kernel. Modul lain menambah factory service-nya di sini.
 */

if (!defined('SSO_ROOT')) {
    define('SSO_ROOT', __DIR__);
}

require SSO_ROOT . '/vendor/autoload.php';

$container = new Container();

$container->set('config.app', static function (): array {
    $file = SSO_ROOT . '/config/app.php';

    return is_file($file) ? (array) require $file : [];
});
$container->set('config.secrets', static function (): array {
    $file = SSO_ROOT . '/config/secrets.php';
    if (!is_file($file)) {
        throw new RuntimeException('config/secrets.php tidak ditemukan');
    }

    return (array) require $file;
});
$container->set(Logger::class, static fn (): Logger => new Logger(SSO_ROOT . '/storage/logs'));
$container->set(View::class, static fn (): View => new View(SSO_ROOT . '/views'));
$container->set('config.database', static fn (): array => (array) require SSO_ROOT . '/config/database.php');
$container->set(Clock::class, static fn (): Clock => new SystemClock());
$container->set(Database::class, static fn (Container $c): Database => new Database((array) $c->get('config.database')));
$container->set(Mailer::class, static fn (Container $c): Mailer => new PhpMailer((string) (($c->get('config.app'))['mail_from'] ?? '')));
$container->set(
    SessionTerminator::class,
    static fn (Container $c): SessionTerminator => $c->get(LogoutService::class),
);
$container->set(UrlAllowlist::class, static fn (): UrlAllowlist => new UrlAllowlist());
$container->set(HttpClient::class, static fn (Container $c): HttpClient => new HttpClient($c->get(UrlAllowlist::class)));
$container->set(
    TokenIssuer::class,
    static fn (Container $c): TokenIssuer => new TokenIssuer($c->get(KeyManager::class), $c->get(Urls::class), $c->get(Clock::class)),
);
$container->set(
    BackchannelNotifier::class,
    static fn (Container $c): BackchannelNotifier => new BackchannelNotifier(
        $c->get(LogoutNotificationRepository::class),
        $c->get(SessionClientRepository::class),
        $c->get(ClientRepository::class),
        $c->get(UserRepository::class),
        $c->get(TokenIssuer::class),
        $c->get(HttpClient::class),
        $c->get(Clock::class),
        $c->get(Audit::class),
    ),
);
$container->set(
    LogoutService::class,
    static fn (Container $c): LogoutService => new LogoutService(
        $c->get(SsoSessionRepository::class),
        $c->get(RefreshTokenRepository::class),
        $c->get(BackchannelNotifier::class),
        $c->get(Database::class),
        $c->get(Clock::class),
        $c->get(Audit::class),
        $c->get(Logger::class),
    ),
);
$container->set(
    CronRunner::class,
    static fn (Container $c): CronRunner => new CronRunner(
        $c->get(BackchannelNotifier::class),
        $c->get(KeyManager::class),
        new Gc(),
        $c->get(Logger::class),
    ),
);
$container->set(
    Crypto::class,
    static function (Container $c): Crypto {
        $secrets = (array) $c->get('config.secrets');

        return new Crypto((array) ($secrets['keyring'] ?? []), (string) ($secrets['hmac_key'] ?? ''));
    },
);
$container->set(
    KeyManager::class,
    static function (Container $c): KeyManager {
        $dir = (($c->get('config.app'))['key_dir'] ?? '') !== '' ? (string) $c->get('config.app')['key_dir'] : SSO_ROOT . '/keys';

        return new KeyManager(
            $c->get(SigningKeyRepository::class),
            $c->get(SettingRepository::class),
            $c->get(Database::class),
            $c->get(Clock::class),
            $c->get(Audit::class),
            $dir,
        );
    },
);
$container->set(
    ProviderContext::class,
    static fn (Container $c): ProviderContext => new ProviderContext(
        $c->get(HttpClient::class),
        $c->get(Crypto::class),
        $c->get(Clock::class),
        $c->get(Urls::class),
        SSO_ROOT . '/storage/cache',
    ),
);
$container->set(
    ProviderRegistry::class,
    static fn (Container $c): ProviderRegistry => new ProviderRegistry($c->get(IdentityProviderRepository::class), $c->get(ProviderContext::class)),
);
$container->set(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());
$container->set(
    RateLimiter::class,
    static fn (Container $c): RateLimiter => new RateLimiter($c->get(LoginAttemptRepository::class), $c->get(Clock::class)),
);
$container->set(
    SsoSessionManager::class,
    static fn (Container $c): SsoSessionManager => new SsoSessionManager($c->get(SsoSessionRepository::class), $c->get(Clock::class)),
);
$container->set(
    SignedTokens::class,
    static fn (Container $c): SignedTokens => new SignedTokens((string) (($c->get('config.secrets'))['hmac_key'] ?? ''), $c->get(Clock::class)),
);
$container->set(
    Audit::class,
    static fn (Container $c): Audit => new Audit($c->get(AuditLogRepository::class), $c->get(Clock::class)),
);
$container->set(Urls::class, static fn (Container $c): Urls => new Urls((string) (($c->get('config.app'))['base_url'] ?? '')));

foreach ([
    UserRepository::class, IdentityProviderRepository::class, UserIdentityRepository::class,
    FederationStateRepository::class, ClientRepository::class, ClientRedirectUriRepository::class,
    SsoSessionRepository::class, SessionClientRepository::class, AuthCodeRepository::class,
    RefreshTokenRepository::class, LogoutNotificationRepository::class, SigningKeyRepository::class,
    AdminRoleRepository::class, SettingRepository::class, LoginAttemptRepository::class,
    AuditLogRepository::class,
] as $repository) {
    $container->set(
        $repository,
        static fn (Container $c): object => new $repository($c->get(Database::class)),
    );
}

$container->set(Router::class, static function (): Router {
    $router = new Router();
    require SSO_ROOT . '/routes.php';

    return $router;
});
$container->set(Kernel::class, static fn (Container $c): Kernel => new Kernel($c, $c->get(Router::class), $c->get(Logger::class)));

if (PHP_SAPI !== 'cli' || getenv('SSO_ERROR_HANDLER') === '1') {
    ErrorHandler::register($container->get(Logger::class), (bool) (($container->get('config.app'))['debug'] ?? false));
}

return $container;
