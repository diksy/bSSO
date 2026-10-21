<?php

declare(strict_types=1);

namespace App\Support;

/** Implementasi Mailer berbasis fungsi mail(). */
final class PhpMailer implements Mailer
{
    public function send(string $to, string $subject, string $text): void
    {
        throw new \LogicException('belum diimplementasi');
    }
}
