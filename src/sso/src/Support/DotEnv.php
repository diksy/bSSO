<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parser file .env (tanpa dependency). Format: KEY=VALUE, "export KEY=VALUE", komentar '#',
 * nilai berkutip tunggal (literal) atau ganda (escape \n \r \t \" \\ \$).
 * Baris tak valid diabaikan; nilai tidak pernah dicetak atau dicatat.
 */
final class DotEnv
{
    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $vars = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^export\s+(.*)$/s', $line, $m) === 1) {
                $line = $m[1];
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/s', $line, $m) !== 1) {
                continue;
            }
            $value = self::value($m[2]);
            if ($value !== null) {
                $vars[$m[1]] = $value;
            }
        }

        return $vars;
    }

    private static function value(string $raw): ?string
    {
        if ($raw === '') {
            return '';
        }
        if ($raw[0] === "'") {
            $end = strpos($raw, "'", 1);
            if ($end === false || !self::tailOk(substr($raw, $end + 1))) {
                return null;
            }

            return substr($raw, 1, $end - 1);
        }
        if ($raw[0] === '"') {
            $out = '';
            $len = strlen($raw);
            $map = ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\', '$' => '$'];
            for ($i = 1; $i < $len; $i++) {
                $ch = $raw[$i];
                if ($ch === '\\' && $i + 1 < $len) {
                    $next = $raw[$i + 1];
                    $out .= $map[$next] ?? '\\' . $next;
                    $i++;
                } elseif ($ch === '"') {
                    return self::tailOk(substr($raw, $i + 1)) ? $out : null;
                } else {
                    $out .= $ch;
                }
            }

            return null; // kutip tidak ditutup
        }
        // Tanpa kutip: komentar inline dimulai oleh spasi/tab diikuti '#'.
        $pos = preg_match('/\s#/', $raw, $m, PREG_OFFSET_CAPTURE);
        if ($pos === 1) {
            $raw = substr($raw, 0, (int) $m[0][1]);
        }

        return trim($raw);
    }

    private static function tailOk(string $tail): bool
    {
        $tail = trim($tail);

        return $tail === '' || $tail[0] === '#';
    }

    /**
     * Terapkan ke environment proses tanpa menimpa variabel yang sudah ada, termasuk kunci $_SERVER (mis. REMOTE_ADDR).
     *
     * @param array<string,string> $vars
     */
    public static function apply(array $vars): void
    {
        foreach ($vars as $k => $v) {
            if (getenv($k) !== false || array_key_exists($k, $_SERVER)) {
                continue;
            }
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }

    /** Muat file jika ada dan terbaca; mengembalikan jumlah variabel yang dibaca. */
    public static function load(string $path): int
    {
        if (!is_file($path) || !is_readable($path)) {
            return 0;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return 0;
        }
        $vars = self::parse($content);
        self::apply($vars);

        return count($vars);
    }
}
