<?php
declare(strict_types=1);

namespace App\Support;

/** Konverter Markdown sederhana: heading, paragraf, list, tabel, blok kode, kode inline, tebal, tautan. */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $lines = preg_split('/\R/', $md) ?: [];
        $out = [];
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            if (preg_match('/^```(\w*)\s*$/', $line, $m) === 1) {
                $code = [];
                for ($i++; $i < $n && !str_starts_with($lines[$i], '```'); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $lang = $m[1] !== '' ? $m[1] : 'text';
                $out[] = '<figure class="codeblock"><figcaption>' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '</figcaption><pre><code class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre></figure>';
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m) === 1) {
                $lvl = strlen($m[1]);
                $id = self::slug($m[2]);
                $out[] = "<h{$lvl} id=\"{$id}\">" . self::inline($m[2]) . "</h{$lvl}>";
                $i++;
                continue;
            }
            if (str_starts_with($line, '|') && isset($lines[$i + 1]) && preg_match('/^\|[\s:|-]+\|\s*$/', $lines[$i + 1]) === 1) {
                $head = self::cells($line);
                $i += 2;
                $html = '<div class="table-wrap"><table class="table"><thead><tr>';
                foreach ($head as $c) {
                    $html .= '<th>' . self::inline($c) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                for (; $i < $n && str_starts_with($lines[$i], '|'); $i++) {
                    $html .= '<tr>';
                    foreach (self::cells($lines[$i]) as $c) {
                        $html .= '<td>' . self::inline($c) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $out[] = $html . '</tbody></table></div>';
                continue;
            }
            if (preg_match('/^(- |\d+\. )/', $line) === 1) {
                $ordered = $line[0] !== '-';
                $items = [];
                $task = false;
                for (; $i < $n && preg_match('/^(?:- |\d+\. )(.*)$/', $lines[$i], $m) === 1; $i++) {
                    $t = $m[1];
                    if (str_starts_with($t, '[ ] ')) {
                        $task = true;
                        $items[] = '<li><span class="check" aria-hidden="true">☐</span> ' . self::inline(substr($t, 4)) . '</li>';
                    } else {
                        $items[] = '<li>' . self::inline($t) . '</li>';
                    }
                }
                $tag = $ordered ? 'ol' : 'ul';
                $out[] = "<{$tag}" . ($task ? ' class="checklist"' : '') . '>' . implode('', $items) . "</{$tag}>";
                continue;
            }
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && preg_match('/^(```|#{1,4}\s|\||- |\d+\. )/', $lines[$i]) !== 1) {
                $para[] = $lines[$i++];
            }
            if ($para === []) {
                $para[] = $lines[$i++];
            }
            $out[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
        }
        return implode("\n", $out);
    }

    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = substr($row, 1, str_ends_with($row, '|') ? -1 : null);
        return array_map('trim', explode('|', $row));
    }

    private static function slug(string $t): string
    {
        $t = strtolower(preg_replace('/[^a-z0-9]+/i', '-', strip_tags($t)) ?? '');
        return trim($t, '-');
    }

    private static function inline(string $s): string
    {
        $codes = [];
        $s = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $s) ?? $s;
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/', '<a href="$2">$1</a>', $s) ?? $s;
        return preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $codes[(int) $m[1]], $s) ?? $s;
    }
}
