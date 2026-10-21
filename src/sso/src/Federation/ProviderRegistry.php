<?php

declare(strict_types=1);

namespace App\Federation;

use App\Federation\Drivers\AppleDriver;
use App\Federation\Drivers\DiscordDriver;
use App\Federation\Drivers\FacebookDriver;
use App\Federation\Drivers\GitHubDriver;
use App\Federation\Drivers\GitLabDriver;
use App\Federation\Drivers\GoogleDriver;
use App\Federation\Drivers\LinkedInDriver;
use App\Federation\Drivers\MicrosoftDriver;
use App\Federation\Drivers\XDriver;
use App\Repository\IdentityProviderRepository;
use App\Repository\Record\IdentityProvider;

final class ProviderRegistry
{
    /** Daftar putih nama driver (kolom identity_providers.driver) => class. Hanya ini yang boleh diinstansiasi. */
    public const DRIVERS = [
        'GoogleDriver' => GoogleDriver::class,
        'MicrosoftDriver' => MicrosoftDriver::class,
        'AppleDriver' => AppleDriver::class,
        'GitHubDriver' => GitHubDriver::class,
        'GitLabDriver' => GitLabDriver::class,
        'FacebookDriver' => FacebookDriver::class,
        'LinkedInDriver' => LinkedInDriver::class,
        'DiscordDriver' => DiscordDriver::class,
        'XDriver' => XDriver::class,
    ];

    /** Driver yang callback-nya berupa POST (response_mode=form_post). */
    public const FORM_POST_DRIVERS = ['AppleDriver'];

    /**
     * @param array<string,class-string<ProviderInterface>> $drivers daftar putih (parameter ini untuk tes; produksi memakai DRIVERS)
     */
    public function __construct(
        private readonly IdentityProviderRepository $providers,
        private readonly ProviderContext $ctx,
        private readonly array $drivers = self::DRIVERS,
    ) {
    }

    /** Daftar putih driver; JANGAN instansiasi class langsung dari string DB. */
    public function get(string $slug): ProviderInterface
    {
        $config = $this->config($slug);
        $class = $this->drivers[$config->driver] ?? null;
        if ($class === null || !is_subclass_of($class, ProviderInterface::class)) {
            throw new FederationException('Driver provider tidak dikenal');
        }
        $protocol = is_subclass_of($class, AbstractOidcProvider::class) ? 'oidc' : (is_subclass_of($class, AbstractOAuth2Provider::class) ? 'oauth2' : null);
        if ($protocol !== null && $protocol !== $config->protocol) {
            throw new FederationException('Protokol provider tidak sesuai driver');
        }

        return new $class($config, $this->ctx);
    }

    /** Konfigurasi provider yang AKTIF dengan driver terdaftar; selain itu FederationException. */
    public function config(string $slug): IdentityProvider
    {
        $config = preg_match('/^[a-z0-9_-]{1,30}$/', $slug) === 1 ? $this->providers->findBySlug($slug) : null;
        if ($config === null || !$config->isEnabled || !isset($this->drivers[$config->driver])) {
            throw new FederationException('Provider tidak tersedia');
        }

        return $config;
    }

    public function usesFormPost(IdentityProvider $config): bool
    {
        return in_array($config->driver, self::FORM_POST_DRIVERS, true);
    }
}
