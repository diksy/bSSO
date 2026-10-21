<?php

declare(strict_types=1);

namespace App\Support;

final class ErrorHandler
{
    public static function register(Logger $logger, bool $debug): void
    {
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '0');
        error_reporting(E_ALL);
        set_error_handler(static function (int $no, string $str, string $file, int $line) use ($logger): bool {
            if ((error_reporting() & $no) === 0) {
                return false;
            }
            $logger->error(sprintf('PHP error %d di %s:%d: %s', $no, basename($file), $line, $str));

            return true;
        });
        set_exception_handler(static function (\Throwable $e) use ($logger, $debug): void {
            $logger->exception($e);
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $debug ? $e->getMessage() : 'Terjadi kesalahan pada server.';
        });
    }
}
