<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\PasswordPolicy;
use App\Security\RateLimiter;
use App\Session\SessionTerminator;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Flash;

final class PasswordController
{
    public function __construct(
        private readonly AccountPage $page,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SessionTerminator $terminator,
        private readonly Audit $audit,
        private readonly Database $db,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->page->render($request, 'account/password', 'Kata sandi', 'password');
    }

    public function save(Request $request): Response
    {
        $user = $request->attribute('user');
        $session = $request->attribute('sso_session');
        if (!$user instanceof User || !$session instanceof SsoSession) {
            throw new \LogicException('RequireSsoSession wajib');
        }
        $fail = fn (string $msg, int $status = 422): Response => $this->page->render(
            $request,
            'account/password',
            'Kata sandi',
            'password',
            ['error' => $msg],
            $status,
        );
        $key = 'pwchange:' . $user->id;
        if ($this->limiter->blocked($key)) {
            return $fail('Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }
        if ($user->passwordHash === null) {
            return $fail('Akun ini belum punya kata sandi. Gunakan "Lupa kata sandi" di halaman masuk untuk membuatnya.');
        }
        $current = (string) $request->input('current', '');
        $new = (string) $request->input('new', '');
        if (strlen($current) > 1024 || !$this->hasher->verify($current, $user->passwordHash)) {
            $this->limiter->record($key, $request->ip(), false);

            return $fail('Kata sandi saat ini salah.');
        }
        $policy = PasswordPolicy::check($new);
        if ($policy !== null) {
            return $fail($policy);
        }
        if (!hash_equals($new, (string) $request->input('confirm', ''))) {
            return $fail('Konfirmasi kata sandi tidak cocok.');
        }
        if (hash_equals($current, $new)) {
            return $fail('Kata sandi baru harus berbeda dari yang lama.');
        }
        $hash = $this->hasher->hash($new);
        $this->db->transaction(function () use ($user, $hash, $session): void {
            $locked = $this->users->findByIdForUpdate($user->id);
            if ($locked === null) {
                throw new \RuntimeException('User hilang');
            }
            $this->users->updatePasswordHash($user->id, $hash);
            $this->terminator->endAllForUser($user->id, $session->sid, 'password_change');
        });
        $this->limiter->record($key, $request->ip(), true);
        $this->audit->record('account.password_change', $user->id, $user->id);
        Flash::add('ok', 'Kata sandi diganti. Sesi di perangkat lain telah diakhiri.');

        return Response::redirect('/account/password', 303);
    }
}
