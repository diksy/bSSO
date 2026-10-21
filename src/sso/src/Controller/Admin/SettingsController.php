<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Repository\SettingRepository;
use App\Security\KeyManager;
use App\Security\PasswordPolicy;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Flash;

/** Pengaturan (modul settings): baca = auditor/superadmin, ubah = superadmin + step-up. Audit before/after. */
final class SettingsController
{
    /** field form => [nama setting, bawaan, min, maks] */
    private const INT_FIELDS = [
        'sso_session_hours' => ['sso.session_hours', 8, 1, 720],
        'password_min' => ['auth.min_length', PasswordPolicy::MIN, PasswordPolicy::MIN, 128],
        'rate_limit_attempts' => ['rate_limit.attempts', 5, 3, 100],
        'rate_limit_window_min' => ['rate_limit.window_minutes', 15, 1, 1440],
        'key_rotation_days' => ['key.rotation_days', KeyManager::DEFAULT_ROTATION_DAYS, 7, 365],
        'key_overlap_days' => ['key.overlap_days', 2, 1, 90],
        'admin_idle_minutes' => ['admin.idle_minutes', 15, 1, 240],
    ];
    private const BOOL_FIELD = ['mfa_required_all', 'mfa.required_all'];

    public function __construct(
        private readonly AdminPage $page,
        private readonly SettingRepository $settings,
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->page->render($request, 'admin/settings', 'Pengaturan', 'settings', ['s' => $this->current()]);
    }

    public function update(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $current = $this->current();
        $new = [];
        foreach (self::INT_FIELDS as $field => [, , $min, $max]) {
            $raw = trim($request->input($field) ?? '');
            if (preg_match('/^\d{1,6}$/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
                return $this->page->render(
                    $request,
                    'admin/settings',
                    'Pengaturan',
                    'settings',
                    ['s' => $current, 'error' => "Nilai '{$field}' harus bilangan bulat {$min}-{$max}."],
                    422,
                );
            }
            $new[$field] = (int) $raw;
        }
        $new[self::BOOL_FIELD[0]] = $request->input(self::BOOL_FIELD[0]) === '1';
        if ($new['key_overlap_days'] >= $new['key_rotation_days']) {
            return $this->page->render(
                $request,
                'admin/settings',
                'Pengaturan',
                'settings',
                ['s' => $current, 'error' => 'key.overlap_days harus lebih kecil dari key.rotation_days.'],
                422,
            );
        }

        $changes = $this->db->transaction(function () use ($actor, $current, $new): array {
            $changes = [];
            $now = $this->clock->now();
            foreach ($new as $field => $value) {
                if ($current[$field] === $value) {
                    continue;
                }
                $name = self::INT_FIELDS[$field][0] ?? self::BOOL_FIELD[1];
                $this->settings->set($name, $value, $actor->id, $now);
                $changes[$name] = ['before' => $current[$field], 'after' => $value];
            }
            if ($changes !== []) {
                $this->audit->record('settings.update', $actor->id, null, null, ['changes' => $changes]);
            }

            return $changes;
        });
        Flash::add('ok', $changes === [] ? 'Tidak ada perubahan.' : 'Pengaturan disimpan.');

        return Response::redirect('/admin/settings', 303);
    }

    /** @return array<string, int|bool> */
    private function current(): array
    {
        $out = [];
        foreach (self::INT_FIELDS as $field => [$name, $default]) {
            $out[$field] = $this->settings->int($name, $default);
        }
        $out[self::BOOL_FIELD[0]] = $this->settings->get(self::BOOL_FIELD[1])?->value === true;

        return $out;
    }
}
