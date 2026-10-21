<?php

declare(strict_types=1);

namespace App\Federation;

/** Validasi/normalisasi akhir profil hasil driver: id wajib, email divalidasi, "verified" tanpa email dibatalkan. */
final class ProfileGuard
{
    public static function check(NormalizedProfile $p): NormalizedProfile
    {
        if ($p->providerUserId === '' || strlen($p->providerUserId) > 255) {
            throw new FederationException('Profil provider tidak memiliki id yang valid');
        }
        $email = $p->email === null ? null : mb_strtolower(trim($p->email));
        if ($email !== null && ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $email = null;
        }
        $name = $p->name === null ? null : trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $p->name) ?? '');
        $avatar = $p->avatarUrl !== null && strlen($p->avatarUrl) <= 500 && str_starts_with($p->avatarUrl, 'https://') ? $p->avatarUrl : null;

        return new NormalizedProfile(
            $p->providerUserId,
            $email,
            $email !== null && $p->emailVerified,
            $name === null || $name === '' ? null : mb_substr($name, 0, 150),
            $avatar,
            \App\Support\Audit::scrub($p->raw), // buang kunci password|secret|token|code sebelum disimpan
        );
    }
}
