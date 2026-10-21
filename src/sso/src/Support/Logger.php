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

    private function write(string $level, string $message): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return;
        }
        $line = sprintf("%s %s %s\n", gmdate('c'), $level, preg_replace('/[\r\n]+/', ' ', $message));
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
