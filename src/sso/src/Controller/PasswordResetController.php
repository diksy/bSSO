<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\PasswordPolicy;
use App\Security\RateLimiter;
use App\Security\SignedTokens;
use App\Session\SessionTerminator;
use App\Support\Audit;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\Urls;
use App\Support\View;

final class PasswordResetController
{
    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SignedTokens $tokens,
        private readonly Mailer $mailer,
        private readonly Urls $urls,
        private readonly SessionTerminator $terminator,
        private readonly Audit $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function forgotForm(Request $request): Response
    {
        return $this->view->render('auth/forgot', ['title' => 'Lupa kata sandi', 'email' => '', 'error' => null]);
    }

    public function forgotSubmit(Request $request): Response
    {
        $email = UserRepository::normalizeEmail((string) $request->input('email', ''));
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->view->render('auth/forgot', ['title' => 'Lupa kata sandi', 'email' => $email, 'error' => 'Alamat email tidak valid.'], 'auth', 422);
        }
        $key = 'forgot:' . $email;
        // Respons selalu sama (juga saat dibatasi atau email tidak ada).
        if (!$this->limiter->blocked($key)) {
            $this->limiter->record($key, $request->ip(), false);
            $user = $this->users->findByEmail($email);
            if ($user !== null && in_array($user->status, ['active', 'pending'], true) && $user->email !== null) {
                $this->sendLink($user);
            }
        }
        Flash::add('info', 'Jika email terdaftar, tautan untuk mengatur ulang kata sandi telah dikirim. Tautan berlaku 1 jam.');

        return Response::redirect('/login', 303);
    }

    public function resetForm(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        if ($this->resolve($token, null) === null) {
            return $this->invalid();
        }

        return $this->view->render('auth/reset', ['title' => 'Atur ulang kata sandi', 'token' => $token, 'error' => null]);
    }

    public function resetSubmit(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        $new = (string) $request->input('new', '');
        $fail = fn (string $m): Response => $this->view->render('auth/reset', ['title' => 'Atur ulang kata sandi', 'token' => $token, 'error' => $m], 'auth', 422);

        $policy = PasswordPolicy::check($new);
        if ($this->resolve($token, null) === null) {
            return $this->invalid();
        }
        if ($policy !== null) {
            return $fail($policy);
        }
        if (!hash_equals($new, (string) $request->input('confirm', ''))) {
            return $fail('Konfirmasi kata sandi tidak cocok.');
        }

        $hash = $this->hasher->hash($new);
        $userId = $this->db->transaction(function () use ($token, $hash): ?int {
            $user = $this->resolve($token, true);
            if ($user === null) {
                return null;
            }
            $this->users->updatePasswordHash($user->id, $hash);
            if ($user->emailVerifiedAt === null) {
                $this->users->markEmailVerified($user->id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
            $this->terminator->endAllForUser($user->id, null, 'password_reset');

            return $user->id;
        });
        if ($userId === null) {
            return $this->invalid();
        }
        $this->audit->record('password.reset', $userId, $userId);
        Flash::add('ok', 'Kata sandi diatur ulang. Silakan masuk.');

        return Response::redirect('/login', 303);
    }

    /** Token valid hanya jika HMAC benar, belum kedaluwarsa, dan masih terikat ke password_hash saat ini. */
    private function resolve(string $token, ?bool $lock): ?User
    {
        $payload = $this->tokens->verify(SignedTokens::RESET, $token);
        if ($payload === null) {
            return null;
        }
        $user = $lock === true ? $this->users->findByIdForUpdate($payload['uid']) : $this->users->findById($payload['uid']);
        if ($user === null || !in_array($user->status, ['active', 'pending'], true) || !SignedTokens::bindMatches($payload['bind'], $user->passwordHash ?? '')) {
            return null;
        }

        return $user;
    }

    private function sendLink(User $user): void
    {
        if (!$this->urls->available() || $user->email === null) {
            $this->logger->error('base_url belum dikonfigurasi; email reset tidak dikirim');

            return;
        }
        $token = $this->tokens->issue(SignedTokens::RESET, $user->id, $user->passwordHash ?? '');
        try {
            $this->mailer->send($user->email, 'Atur ulang kata sandi BikininSSO', "Buka tautan berikut untuk mengatur ulang kata sandi (berlaku 1 jam, sekali pakai):\n\n" . $this->urls->abs('/password/reset?token=' . $token) . "\n\nAbaikan email ini jika Anda tidak memintanya.");
            $this->audit->record('password.reset_requested', null, $user->id);
        } catch (\Throwable $e) {
            $this->logger->error('Pengiriman email reset gagal: ' . $e::class);
        }
    }

    private function invalid(): Response
    {
        return $this->view->render('auth/error', [
            'title' => 'Tautan tidak valid',
            'heading' => 'Tautan tidak valid',
            'text' => 'Tautan atur ulang kata sandi tidak valid, sudah dipakai, atau kedaluwarsa. Minta tautan baru.',
            'code' => 'invalid_token',
        ], 'auth', 400);
    }
}
