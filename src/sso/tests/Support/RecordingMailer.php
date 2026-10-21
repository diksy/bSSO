<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Mailer;

final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, text: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $text): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
    }

    public function lastToken(): string
    {
        $last = end($this->sent);
        preg_match('/token=([A-Za-z0-9_.-]+)/', $last === false ? '' : $last['text'], $m);

        return $m[1] ?? '';
    }
}
