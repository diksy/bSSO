<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\IdentityProviderRepository;
use App\Repository\Record\User;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Flash;
use App\Support\UiSession;

/** Akun tertaut milik user yang sedang login (F7). Otorisasi: hanya identitas milik user itu sendiri. */
final class LinkedAccountsController
{
    public function __construct(
        private readonly AccountPage $page,
        private readonly IdentityProviderRepository $providers,
        private readonly UserIdentityRepository $identities,
        private readonly UserRepository $users,
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $linked = [];
        foreach ($this->identities->listForUser($user->id) as $identity) {
            $linked[$identity->providerId] = true;
        }
        $slugs = [];
        $list = [];
        foreach ($this->providers->listAll() as $p) {
            if ($p->isEnabled || isset($linked[$p->id])) {
                $list[] = ['slug' => $p->slug, 'name' => $p->displayName, 'active' => $p->isEnabled];
                if (isset($linked[$p->id])) {
                    $slugs[] = $p->slug;
                }
            }
        }

        return $this->page->render($request, 'account/linked', 'Akun tertaut', 'linked', ['providers' => $list, 'identities' => $slugs]);
    }

    /** POST (CSRF terverifikasi): catat niat menautkan lalu lanjut ke /federation/{slug}/start?link=1. */
    public function link(Request $request): Response
    {
        $user = $this->user($request);
        $provider = $this->providers->findBySlug($this->slug($request));
        if ($provider === null || !$provider->isEnabled) {
            throw new HttpException(404);
        }
        if ($this->identities->findByUserAndProvider($user->id, $provider->id) !== null) {
            Flash::add('info', $provider->displayName . ' sudah tertaut.');

            return Response::redirect('/account/linked', 303);
        }
        UiSession::set('fed_link', ['slug' => $provider->slug, 'user_id' => $user->id, 'at' => $this->clock->now()->getTimestamp()]);

        return Response::redirect('/federation/' . rawurlencode($provider->slug) . '/start?link=1', 303);
    }

    /** Memutus tautan; ditolak bila akun tak punya metode login lain (tanpa password dan tanpa tautan lain). */
    public function unlink(Request $request): Response
    {
        $user = $this->user($request);
        $provider = $this->providers->findBySlug($this->slug($request));
        if ($provider === null) {
            throw new HttpException(404);
        }
        $result = $this->db->transaction(function () use ($user, $provider): string {
            $fresh = $this->users->findByIdForUpdate($user->id);
            $identity = $this->identities->findByUserAndProvider($user->id, $provider->id);
            if ($fresh === null || $identity === null) {
                return 'missing';
            }
            $remaining = count($this->identities->listForUser($user->id)) - 1 + ($fresh->passwordHash !== null ? 1 : 0);
            if ($remaining < 1) {
                return 'last';
            }

            return $this->identities->deleteForUser($user->id, $identity->id) ? 'ok' : 'missing';
        });
        if ($result === 'ok') {
            $this->audit->record('identity.unlinked', $user->id, $user->id, null, ['provider' => $provider->slug]);
            Flash::add('ok', $provider->displayName . ' diputus.');
        } elseif ($result === 'last') {
            Flash::add('err', 'Tidak bisa memutus satu-satunya cara masuk. Atur kata sandi atau tautkan akun lain dulu.');
        } else {
            Flash::add('err', $provider->displayName . ' tidak tertaut ke akun Anda.');
        }

        return Response::redirect('/account/linked', 303);
    }

    private function user(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('RequireSsoSession wajib');
        }

        return $user;
    }

    private function slug(Request $request): string
    {
        $slug = $request->attribute('slug');
        if (!is_string($slug) || preg_match('/^[a-z0-9_-]{1,30}$/', $slug) !== 1) {
            throw new HttpException(404);
        }

        return $slug;
    }
}
