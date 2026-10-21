<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOAuth2Provider;
use App\Federation\FederationException;
use App\Federation\NormalizedProfile;

/**
 * GitHub (OAuth2): email dari /user/emails, hanya entri primary DAN verified (email publik di /user tidak dipercaya).
 * Endpoint emails dari extra_config.emails_url atau konstanta kode (bukan input user).
 */
final class GitHubDriver extends AbstractOAuth2Provider
{
    private const EMAILS_URL = 'https://api.github.com/user/emails';

    protected function fetchUserinfo(string $accessToken): array
    {
        $user = parent::fetchUserinfo($accessToken);
        $url = $this->config->extraConfig['emails_url'] ?? self::EMAILS_URL;
        try {
            $user['_emails'] = $this->ctx->getJson(is_string($url) ? $url : self::EMAILS_URL, ['Authorization' => 'Bearer ' . $accessToken]);
        } catch (FederationException) {
            $user['_emails'] = []; // tanpa email terverifikasi; alur "lengkapi email" yang menangani
        }

        return $user;
    }

    protected function mapProfile(array $userinfo): NormalizedProfile
    {
        $email = null;
        foreach (is_array($userinfo['_emails'] ?? null) ? $userinfo['_emails'] : [] as $e) {
            if (is_array($e) && ($e['primary'] ?? null) === true && ($e['verified'] ?? null) === true && is_string($e['email'] ?? null)) {
                $email = $e['email'];
                break;
            }
        }

        return new NormalizedProfile(
            (string) Flag::str($userinfo['id'] ?? null),
            $email,
            $email !== null,
            Flag::str($userinfo['name'] ?? null) ?? Flag::str($userinfo['login'] ?? null),
            Flag::str($userinfo['avatar_url'] ?? null),
            ['id' => $userinfo['id'] ?? null, 'login' => $userinfo['login'] ?? null],
        );
    }
}
