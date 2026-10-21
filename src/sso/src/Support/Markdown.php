<?php

declare(strict_types=1);

namespace App\Support;

final class Markdown
{
    /** Escape dulu; subset heading/list/tabel/blok kode/kode inline/tebal/link http(s) saja. */
    public function toHtml(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
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
                $out[] = '<figure class="codeblock"><figcaption>' . $this->esc($lang) . '</figcaption><pre><code class="language-'
                    . $this->esc($lang) . '">' . $this->esc(implode("\n", $code)) . '</code></pre></figure>';
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m) === 1) {
                $level = strlen($m[1]);
                $out[] = "<h{$level} id=\"" . $this->slug($m[2]) . "\">" . $this->inline($m[2]) . "</h{$level}>";
                $i++;
                continue;
            }
            if (str_starts_with($line, '|') && isset($lines[$i + 1]) && preg_match('/^\|[\s:|-]+\|\s*$/', $lines[$i + 1]) === 1) {
                $html = '<div class="table-wrap"><table class="table"><thead><tr>';
                foreach ($this->cells($line) as $c) {
                    $html .= '<th>' . $this->inline($c) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                for ($i += 2; $i < $n && str_starts_with($lines[$i], '|'); $i++) {
                    $html .= '<tr>';
                    foreach ($this->cells($lines[$i]) as $c) {
                        $html .= '<td>' . $this->inline($c) . '</td>';
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
                    if (str_starts_with($m[1], '[ ] ')) {
                        $task = true;
                        $items[] = '<li><span class="check" aria-hidden="true">&#9744;</span> ' . $this->inline(substr($m[1], 4)) . '</li>';
                    } else {
                        $items[] = '<li>' . $this->inline($m[1]) . '</li>';
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
            $out[] = '<p>' . $this->inline(implode(' ', $para)) . '</p>';
        }

        return implode("\n", $out);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return list<string> */
    private function cells(string $row): array
    {
        $row = trim($row);
        $row = substr($row, 1, str_ends_with($row, '|') ? -1 : null);

        return array_map('trim', explode('|', $row));
    }

    private function slug(string $text): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? ''), '-');
    }

    private function inline(string $s): string
    {
        $codes = [];
        $s = preg_replace_callback('/`([^`]+)`/', function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $this->esc($m[1]) . '</code>';

            return "\x01" . (count($codes) - 1) . "\x01";
        }, str_replace("\x01", '', $s)) ?? $s;
        $s = $this->esc($s);
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)"\'<>]+)\)/',
            '<a href="$2" rel="noopener noreferrer">$1</a>',
            $s,
        ) ?? $s;

        return preg_replace_callback('/\x01(\d+)\x01/', static fn (array $m): string => $codes[(int) $m[1]], $s) ?? $s;
    }
}
