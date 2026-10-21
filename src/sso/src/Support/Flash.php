<?php

declare(strict_types=1);

namespace App\Support;

/** Pesan flash sekali tampil di UiSession. Tipe: ok | err | info | warn. */
final class Flash
{
    private const KEY = 'flash';

    public static function add(string $type, string $message): void
    {
        $type = in_array($type, ['ok', 'err', 'info', 'warn'], true) ? $type : 'info';
        /** @var list<array{type: string, message: string}> $all */
        $all = (array) UiSession::get(self::KEY, []);
        $all[] = ['type' => $type, 'message' => $message];
        UiSession::set(self::KEY, $all);
    }

    /** @return list<array{type: string, message: string}> */
    public static function pull(): array
    {
        /** @var list<array{type: string, message: string}> $all */
        $all = (array) UiSession::pull(self::KEY, []);

        return $all;
    }
}
