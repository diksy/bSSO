<?php

declare(strict_types=1);

namespace App\Support;

/** Log ke storage/logs/app-YYYY-MM-DD.log. Jangan pernah mengirim password, token, code, atau secret ke sini. */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    /** Hanya kelas, lokasi, dan pesan singkat (tanpa trace/argumen). */
    public function exception(\Throwable $e): void
    {
        $message = preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?? '';
        $this->write('ERROR', sprintf('%s di %s:%d: %s', $e::class, basename($e->getFile()), $e->getLine(), mb_substr($message, 0, 300)));
    }

    /** Samarkan rahasia yang mungkin terselip di pesan (password/token/secret, JWT, bearer, string acak panjang, email). */
    public static function redact(string $text): string
    {
        $rules = [
            '/\b(password|passwd|pass|secret|client_secret|token|code|authorization|api[_-]?key|hmac_key)(["\']?\s*[=:]\s*)(Bearer\s+)?("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => '$1$2[REDACTED]',
            '#otpauth://\S+#i' => '[otpauth]',
            '/\b[a-z2-9]{5}-[a-z2-9]{5}\b/' => '[REDACTED]',
            '/\b[A-Z2-7]{16,}={0,6}/' => '[REDACTED]',
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTED]',
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/' => '[JWT]',
            '/\b[A-Fa-f0-9]{32,}\b/' => '[HEX]',
            '/\b[A-Za-z0-9_\-+\/]{40,}={0,2}/' => '[REDACTED]',
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
        ];
        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    private function write(string $level, string $message): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return;
        }
        $line = sprintf("%s %s %s\n", gmdate('c'), $level, self::redact(preg_replace('/[\r\n]+/', ' ', $message) ?? ''));
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
