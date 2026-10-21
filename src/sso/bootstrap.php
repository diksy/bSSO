<?php

declare(strict_types=1);

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
use App\Session\LocalSessionTerminator;
use App\Session\SessionTerminator;
use App\Support\Clock;
use App\Support\Container;
use App\Support\Database;
use App\Support\Mailer;
use App\Support\PhpMailer;
use App\Support\SystemClock;

/**
 * Bootstrap: membangun container (array factory, lazy). Tidak menjalankan request;
 * public_html/index.php memanggil Kernel. Modul fondasi (T1) menambah error handler,
 * logging, dan pemuatan config/*.php; modul lain menambah factory service-nya di sini.
 */

define('SSO_ROOT', __DIR__);

require SSO_ROOT . '/vendor/autoload.php';

$container = new Container();

$container->set('config.database', static fn (): array => (array) require SSO_ROOT . '/config/database.php');
$container->set(Clock::class, static fn (): Clock => new SystemClock());
$container->set(Database::class, static fn (Container $c): Database => new Database((array) $c->get('config.database')));
$container->set(Mailer::class, static fn (): Mailer => new PhpMailer());
$container->set(SessionTerminator::class, static fn (): SessionTerminator => new LocalSessionTerminator());

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
$container->set(Kernel::class, static fn (): Kernel => new Kernel());

return $container;
