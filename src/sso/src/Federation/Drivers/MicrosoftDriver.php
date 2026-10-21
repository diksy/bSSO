<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOidcProvider;
use App\Federation\FederationException;
use App\Federation\NormalizedProfile;

/**
 * Microsoft (OIDC, endpoint common): iss berisi tenant ID sehingga dicocokkan dengan pola
 * https://login.microsoftonline.com/{tid}/v2.0 dan {tid} harus sama dengan klaim tid. provider_user_id = tid.oid.
 * Klaim email Microsoft TIDAK dijamin terverifikasi, jadi emailVerified selalu false.
 */
final class MicrosoftDriver extends AbstractOidcProvider
{
    private const GUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    protected function issuerMatches(string $iss, array $claims): bool
    {
        $tid = $claims['tid'] ?? null;
        if (!is_string($tid) || preg_match('/^' . self::GUID . '$/', $tid) !== 1) {
            return false;
        }

        return hash_equals('https://login.microsoftonline.com/' . $tid . '/v2.0', $iss);
    }

    protected function mapProfile(array $data): NormalizedProfile
    {
        $tid = Flag::str($data['tid'] ?? null);
        $oid = Flag::str($data['oid'] ?? null);
        if ($tid === null || $oid === null) {
            throw new FederationException('id_token Microsoft tanpa tid/oid');
        }

        return new NormalizedProfile(
            $tid . '.' . $oid,
            Flag::str($data['email'] ?? null),
            false,
            Flag::str($data['name'] ?? null),
            null,
            ['tid' => $tid, 'oid' => $oid],
        );
    }
}
