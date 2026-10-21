<?php

declare(strict_types=1);

namespace App\Support;

/** Implementasi Mailer berbasis fungsi mail(). */
final class PhpMailer implements Mailer
{
    public function __construct(private readonly string $from = '')
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $to . $subject) === 1) {
            throw new \InvalidArgumentException('Alamat atau subjek email tidak valid');
        }
        $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
        if ($this->from !== '' && filter_var($this->from, FILTER_VALIDATE_EMAIL) !== false) {
            $headers[] = 'From: ' . $this->from;
        }
        $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        if (!mail($to, $encoded, $text, implode("\r\n", $headers))) {
            throw new \RuntimeException('Pengiriman email gagal');
        }
    }
}
