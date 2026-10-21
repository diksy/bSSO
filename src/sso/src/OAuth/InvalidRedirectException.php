<?php

declare(strict_types=1);

namespace App\OAuth;

/** redirect_uri/client tidak valid: tampilkan halaman error, JANGAN redirect. Pesan = jenis ('client'|'redirect_uri'). */
final class InvalidRedirectException extends \RuntimeException
{
}
