<?php

declare(strict_types=1);

namespace App\Federation;

final class NormalizedProfile
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly array $raw,
    ) {
    }

    /**
     * Untuk disimpan di sesi UI server-side (bukan cookie klien).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->providerUserId, 'email' => $this->email, 'verified' => $this->emailVerified,
            'name' => $this->name, 'avatar' => $this->avatarUrl, 'raw' => $this->raw,
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $str = static fn (mixed $v): ?string => is_string($v) ? $v : null;

        return new self(
            (string) ($a['id'] ?? ''),
            $str($a['email'] ?? null),
            ($a['verified'] ?? false) === true,
            $str($a['name'] ?? null),
            $str($a['avatar'] ?? null),
            is_array($a['raw'] ?? null) ? $a['raw'] : [],
        );
    }
}
