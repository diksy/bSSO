<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\PasswordPolicy;
use App\Security\RateLimiter;
use App\Security\SignedTokens;
use App\Support\Audit;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\Urls;
use App\Support\View;

final class RegisterController
{
    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SignedTokens $tokens,
        private readonly Mailer $mailer,
        private readonly Urls $urls,
        private readonly Audit $audit,
        private readonly Logger $logger,
    ) {
    }

    public function form(Request $request): Response
    {
        return $this->render('', '', null);
    }

    public function submit(Request $request): Response
    {
        $email = UserRepository::normalizeEmail((string) $request->input('email', ''));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $request->input('name', '')) ?? '');
        $password = (string) $request->input('password', '');
        $key = 'register:' . $request->ip();

        if ($this->limiter->blocked($key)) {
            return $this->render($email, $name, 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.', 429);
        }
        // Setiap percobaan dihitung agar pendaftaran massal dari satu IP dibatasi.
        $this->limiter->record($key, $request->ip(), false);

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->render($email, $name, 'Alamat email tidak valid.', 422);
        }
        if ($name === '' || mb_strlen($name) > 80) {
            return $this->render($email, $name, 'Nama wajib diisi (maksimal 80 karakter).', 422);
        }
        $policy = PasswordPolicy::check($password);
        if ($policy !== null) {
            return $this->render($email, $name, $policy, 422);
        }

        $hash = $this->hasher->hash($password); // selalu di-hash: waktu seragam baik email baru maupun sudah ada
        $existing = $this->users->findByEmail($email);
        if ($existing === null) {
            try {
                $user = $this->users->create($email, $hash, $name, 'pending');
                $this->audit->record('register', $user->id, $user->id);
                $this->sendVerification($user->id, $email, $hash);
            } catch (\PDOException) {
                // Balapan pendaftaran ganda (UNIQUE email): respons tetap generik.
            }
        } elseif ($existing->status === 'pending' && $existing->emailVerifiedAt === null) {
            // Anti pra-pembajakan: pendaftaran ulang atas akun belum terverifikasi menimpa password & nama.
            // Token verifikasi terikat ke password_hash, sehingga token pendaftaran sebelumnya otomatis gugur.
            if ($this->users->replacePendingRegistration($existing->id, $hash, $name)) {
                $this->audit->record('register', $existing->id, $existing->id);
                $this->sendVerification($existing->id, $email, $hash);
            }
        }
        // Akun active/terverifikasi tidak pernah ditimpa.

        // Respons sama untuk email baru maupun yang sudah terdaftar (anti-enumerasi).
        return $this->view->render('auth/verify_email', ['title' => 'Cek email Anda', 'email' => $email]);
    }

    public function verify(Request $request): Response
    {
        $payload = $this->tokens->verify(SignedTokens::VERIFY, (string) $request->input('token', ''));
        $user = $payload === null ? null : $this->users->findById($payload['uid']);
        if ($payload === null || $user === null || $user->email === null || !SignedTokens::bindMatches($payload['bind'], self::verifyBind($user->email, $user->passwordHash ?? ''))) {
            Flash::add('err', 'Tautan verifikasi tidak valid atau sudah kedaluwarsa.');

            return Response::redirect('/login', 303);
        }
        if ($user->emailVerifiedAt === null) {
            $this->users->markEmailVerified($user->id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->audit->record('email.verified', $user->id, $user->id);
        }
        Flash::add('ok', 'Email terverifikasi. Silakan masuk.');

        return Response::redirect('/login', 303);
    }

    private function sendVerification(int $userId, string $email, string $passwordHash): void
    {
        if (!$this->urls->available()) {
            $this->logger->error('base_url belum dikonfigurasi; email verifikasi tidak dikirim');

            return;
        }
        $token = $this->tokens->issue(SignedTokens::VERIFY, $userId, self::verifyBind($email, $passwordHash));
        $link = $this->urls->abs('/verify-email?token=' . $token);
        try {
            $this->mailer->send($email, 'Verifikasi email BikininSSO', "Buka tautan berikut untuk memverifikasi email Anda (berlaku 24 jam):\n\n" . $link . "\n\nAbaikan email ini jika Anda tidak mendaftar.");
        } catch (\Throwable $e) {
            $this->logger->error('Pengiriman email verifikasi gagal: ' . $e::class);
        }
    }

    /** Token verifikasi terikat ke email DAN password_hash: penimpaan password menggugurkan token lama. */
    private static function verifyBind(string $email, string $passwordHash): string
    {
        return $email . "\n" . $passwordHash;
    }

    private function render(string $email, string $name, ?string $error, int $status = 200): Response
    {
        return $this->view->render('auth/register', ['title' => 'Daftar', 'email' => $email, 'name' => $name, 'error' => $error], 'auth', $status);
    }
}
