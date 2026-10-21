<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\IdentityProvider;
use App\Repository\Record\User;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;

/**
 * Menyelesaikan tautan yang sudah dibuktikan pemilik akun lokal (login metode lama [+ MFA]) pada cabang konfirmasi,
 * atau email yang terverifikasi lewat tautan. Satu-satunya jalur menulis identitas selain IdentityLinker.
 */
final class IdentityLinkCompleter
{
    public function __construct(
        private readonly UserIdentityRepository $identities,
        private readonly UserRepository $users,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Audit $audit,
    ) {
    }

    /** @throws FederationException bila identitas provider itu sudah milik akun lain / akun tak aktif */
    public function link(int $userId, IdentityProvider $provider, NormalizedProfile $profile, string $event = 'identity.linked_confirmed'): void
    {
        $profile = ProfileGuard::check($profile);
        try {
            $this->db->transaction(function () use ($userId, $provider, $profile, $event): void {
                $user = $this->users->findByIdForUpdate($userId);
                if ($user === null || $user->status !== 'active') {
                    throw new FederationException('Akun tujuan tidak valid');
                }
                $existing = $this->identities->findByProviderUser($provider->id, $profile->providerUserId);
                if ($existing !== null) {
                    if ($existing->userId !== $userId) {
                        throw new FederationException('Akun penyedia ini sudah tertaut ke akun lain');
                    }

                    return;
                }
                if ($this->identities->findByUserAndProvider($userId, $provider->id) !== null) {
                    throw new FederationException('Akun Anda sudah menautkan akun lain dari penyedia ini');
                }
                $this->identities->create(
                    $userId,
                    $provider->id,
                    $profile->providerUserId,
                    $profile->email,
                    $profile->emailVerified,
                    $profile->raw,
                    $this->clock->now(),
                );
                $this->audit->record($event, $userId, $userId, null, ['provider' => $provider->slug]);
            });
        } catch (\PDOException) {
            throw new FederationException('Penautan akun gagal, silakan coba lagi');
        }
    }

    /**
     * Buat akun baru SETELAH email terbukti lewat tautan (cabang 5 "lengkapi email"). Akun tidak dibuat sebelum itu,
     * sehingga tak ada akun pending yang bisa dibajak lewat pendaftaran email milik orang lain.
     *
     * @throws FederationException bila email sudah dipakai akun lain atau identitas provider sudah tertaut
     */
    public function createVerifiedUser(IdentityProvider $provider, NormalizedProfile $profile, string $email): User
    {
        $profile = ProfileGuard::check($profile);
        $email = UserRepository::normalizeEmail($email);
        try {
            return $this->db->transaction(function () use ($provider, $profile, $email): User {
                if ($this->users->findByEmail($email) !== null) {
                    throw new FederationException('Email sudah terdaftar');
                }
                if ($this->identities->findByProviderUser($provider->id, $profile->providerUserId) !== null) {
                    throw new FederationException('Akun penyedia ini sudah tertaut');
                }
                $now = $this->clock->now();
                $user = $this->users->create($email, null, $profile->name, 'active');
                $this->users->markEmailVerified($user->id, $now);
                $this->identities->create($user->id, $provider->id, $profile->providerUserId, $email, true, $profile->raw, $now);
                $this->audit->record('identity.created', $user->id, $user->id, null, ['provider' => $provider->slug, 'via' => 'email_link']);

                return $this->users->findById($user->id) ?? $user;
            });
        } catch (\PDOException) {
            throw new FederationException('Pembuatan akun gagal, silakan coba lagi');
        }
    }
}
