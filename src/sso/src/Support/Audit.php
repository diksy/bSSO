<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ClientIp;
use App\Repository\AuditLogRepository;

final class Audit
{
    private const SECRET_KEY = '/password|secret|token|code/i';

    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly Clock $clock,
        private readonly ?string $ip = null,
        /** @var array<mixed> */
        private readonly array $trustedProxies = [],
    ) {
    }

    /**
     * Menyaring kunci context yang cocok password|secret|token|code sebelum disimpan.
     *
     * @param array<string,mixed> $context
     */
    public function record(
        string $event,
        ?int $actor,
        ?int $target = null,
        ?int $clientId = null,
        array $context = [],
    ): void {
        $this->logs->insert($event, $actor, $target, $clientId, $this->ip(), self::scrub($context), $this->clock->now());
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function scrub(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                continue;
            }
            $out[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $out;
    }

    private function ip(): ?string
    {
        return $this->ip ?? (PHP_SAPI === 'cli' ? null : ClientIp::resolve($_SERVER, $this->trustedProxies));
    }
}
