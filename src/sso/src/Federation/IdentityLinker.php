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

final class IdentityLinker
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserIdentityRepository $identities,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Audit $audit,
    ) {
    }

    /**
     * Lima cabang requirements §5 (satu transaksi). Penautan otomatis lewat email HANYA bila email terverifikasi DAN
     * provider trust_email; selain itu NeedsConfirmation (login metode lama) atau NeedsEmail (verifikasi lewat tautan).
     * Akun pra-daftar tak terverifikasi tidak pernah ditautkan begitu saja (anti pra-pembajakan).
     *
     * @throws FederationException
     */
    public function resolve(NormalizedProfile $profile, IdentityProvider $provider, FederationState $state): LinkResult
    {
        $profile = ProfileGuard::check($profile);

        try {
            return $this->db->transaction(fn (): LinkResult => $this->decide($profile, $provider, $state));
        } catch (\PDOException) {
            // Balapan UNIQUE (email atau identitas) -> pesan generik, pengguna mengulang.
            throw new FederationException('Penautan akun gagal, silakan coba lagi');
        }
    }

    private function decide(NormalizedProfile $profile, IdentityProvider $provider, FederationState $state): LinkResult
    {
        $now = $this->clock->now();
        $identity = $this->identities->findByProviderUser($provider->id, $profile->providerUserId);

        // Cabang 2 (didahulukan atas cabang 1 agar identitas milik akun lain tak pernah menukar sesi saat menautkan).
        if ($state->linkToUserId !== null) {
            $target = $this->users->findByIdForUpdate($state->linkToUserId);
            if ($target === null || $target->status !== 'active') {
                throw new FederationException('Akun tujuan tidak valid');
            }
            if ($identity !== null) {
                if ($identity->userId !== $target->id) {
                    throw new FederationException('Akun penyedia ini sudah tertaut ke akun lain');
                }
                $this->identities->touchLogin($identity->id, $now, $profile->email, $profile->emailVerified);

                return new LinkResult(LinkOutcome::LinkedFromAccount, $target);
            }
            if ($this->identities->findByUserAndProvider($target->id, $provider->id) !== null) {
                throw new FederationException('Akun Anda sudah menautkan akun lain dari penyedia ini');
            }
            $this->link($target, $provider, $profile, 'identity.linked', ['mode' => 'account']);

            return new LinkResult(LinkOutcome::LinkedFromAccount, $target);
        }

        // Cabang 1
        if ($identity !== null) {
            $user = $this->users->findById($identity->userId);
            if ($user === null) {
                throw new FederationException('Akun tertaut tidak ditemukan');
            }
            $this->identities->touchLogin($identity->id, $now, $profile->email, $profile->emailVerified);

            return new LinkResult(LinkOutcome::Login, $user);
        }

        $existing = $profile->email === null ? null : $this->users->findByEmail($profile->email);
        $trusted = $profile->emailVerified && $provider->trustEmail; // emailVerified sudah false bila email kosong (ProfileGuard)

        if ($existing !== null) {
            if ($existing->status === 'suspended') {
                throw new FederationException('Akun tidak dapat digunakan');
            }
            // Cabang 3
            if ($trusted) {
                if ($existing->emailVerifiedAt === null) {
                    // Pra-daftar tak terverifikasi: pemilik email sah (terbukti provider) mengambil alih; password lama dibuang.
                    if (!$this->users->claimUnverifiedByFederation($existing->id, $now)) {
                        throw new FederationException('Penautan akun gagal, silakan coba lagi');
                    }
                    $existing = $this->users->findById($existing->id) ?? $existing;
                }
                $this->link($existing, $provider, $profile, 'identity.auto_linked', ['via' => 'trusted_email']);

                return new LinkResult(LinkOutcome::AutoLinked, $existing);
            }

            // Cabang 4
            return new LinkResult(LinkOutcome::NeedsConfirmation, $existing);
        }

        // Cabang 5
        $email = $profile->email;
        if ($trusted && $email !== null) {
            $user = $this->users->create($email, null, $profile->name, 'active');
            $this->users->markEmailVerified($user->id, $now);
            $user = $this->users->findById($user->id) ?? $user;
            $this->link($user, $provider, $profile, 'identity.created', ['via' => 'federation']);

            return new LinkResult(LinkOutcome::Created, $user);
        }

        return new LinkResult(LinkOutcome::NeedsEmail);
    }

    /** @param array<string,mixed> $context */
    private function link(User $user, IdentityProvider $provider, NormalizedProfile $profile, string $event, array $context): void
    {
        $this->identities->create(
            $user->id,
            $provider->id,
            $profile->providerUserId,
            $profile->email,
            $profile->emailVerified,
            $profile->raw,
            $this->clock->now(),
        );
        $this->audit->record($event, $user->id, $user->id, null, ['provider' => $provider->slug] + $context);
    }
}
