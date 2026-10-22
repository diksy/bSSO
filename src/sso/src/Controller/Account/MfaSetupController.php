<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Security\BackupCodes;
use App\Security\Crypto;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\Totp;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Flash;
use App\Support\UiSession;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** Pengaturan MFA di halaman akun. Secret/kode cadangan hanya lewat sesi UI dalam bentuk terenkripsi. */
final class MfaSetupController
{
    private const MSG_BLOCKED = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';

    public function __construct(
        private readonly AccountPage $page,
        private readonly UserRepository $users,
        private readonly Totp $totp,
        private readonly Crypto $crypto,
        private readonly BackupCodes $backup,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SsoSessionManager $sessions,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly Database $db,
    ) {
    }

    public function show(Request $request, ?string $error = null, int $status = 200): Response
    {
        $user = $this->user($request);
        $data = ['error' => $error, 'codes' => $this->pullCodes()];
        if ($user->mfaEnabledAt === null) {
            $secret = $this->setupSecret();
            $uri = $this->totp->provisioningUri($secret, $user->email ?? $user->uuid);
            $data += ['secret' => $secret, 'otpauthUri' => $uri, 'qr' => $this->qr($uri)];
        } else {
            $data += ['secret' => '', 'otpauthUri' => '', 'qr' => ''];
        }

        return $this->page->render($request, 'account/mfa', 'Verifikasi dua langkah', 'mfa', $data, $status)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function enable(Request $request): Response
    {
        $user = $this->user($request);
        if ($user->mfaEnabledAt !== null) {
            return Response::redirect('/account/mfa', 303);
        }
        $key = 'mfa:' . $user->id;
        $enc = UiSession::get('mfa_setup');
        if (!is_string($enc)) {
            return Response::redirect('/account/mfa', 303);
        }
        $attempt = $this->limiter->begin($key, $request->ip());
        if ($attempt === null) {
            return $this->show($request, self::MSG_BLOCKED, 429);
        }
        $secret = $this->crypto->decrypt($enc);
        $code = (string) $request->input('code', '');
        $step = strlen($code) > 16 ? null : $this->totp->verify($secret, $code, null);
        if ($step === null) {
            $this->audit->record('mfa.failure', $user->id, $user->id, null, ['stage' => 'setup']);

            return $this->show($request, 'Kode tidak valid. Pastikan jam perangkat Anda tepat, lalu coba lagi.', 422);
        }
        $this->limiter->markSuccess($attempt);
        $codes = $this->backup->generate();
        $ok = $this->users->enableMfa($user->id, $this->crypto->encrypt($secret), $codes['hashes'], $step, $this->clock->now());
        if (!$ok) {
            return Response::redirect('/account/mfa', 303);
        }
        $this->audit->record('mfa.enabled', $user->id, $user->id);

        // MFA baru saja lulus: ganti id sesi SSO dan sesi UI (anti-fixation), lalu tampilkan kode cadangan sekali saja.
        $cookie = $this->sessions->markMfaPassed($this->session($request));
        UiSession::forget('mfa_setup');
        UiSession::regenerate();
        UiSession::set('mfa_codes', $this->crypto->encrypt(json_encode($codes['plain'], JSON_THROW_ON_ERROR)));

        return Response::redirect('/account/mfa', 303)->withCookie($cookie);
    }

    public function disable(Request $request): Response
    {
        $user = $this->user($request);
        $denied = $this->checkPassword($request, $user);
        if ($denied !== null) {
            return $denied;
        }
        $this->users->disableMfa($user->id);
        UiSession::forget('mfa_setup');
        $this->audit->record('mfa.disabled', $user->id, $user->id);
        Flash::add('ok', 'MFA dinonaktifkan.');

        return Response::redirect('/account/mfa', 303);
    }

    public function regenerateBackupCodes(Request $request): Response
    {
        $user = $this->user($request);
        $denied = $this->checkPassword($request, $user);
        if ($denied !== null) {
            return $denied;
        }
        $codes = $this->backup->generate();
        $this->db->transaction(function () use ($user, $codes): void {
            $this->users->findByIdForUpdate($user->id);
            $this->users->setBackupCodes($user->id, $codes['hashes']);
        });
        UiSession::set('mfa_codes', $this->crypto->encrypt(json_encode($codes['plain'], JSON_THROW_ON_ERROR)));
        $this->audit->record('mfa.backup_regenerated', $user->id, $user->id);

        return Response::redirect('/account/mfa', 303);
    }

    /** Nonaktifkan/regenerasi: wajib MFA aktif dan password benar (dibatasi `mfa:<id>`). */
    private function checkPassword(Request $request, User $user): ?Response
    {
        if ($user->mfaEnabledAt === null) {
            return Response::redirect('/account/mfa', 303);
        }
        $key = 'mfa:' . $user->id;
        $password = (string) $request->input('password', '');
        if ($user->passwordHash === null) {
            return $this->show($request, 'Akun ini belum punya kata sandi. Buat dulu lewat "Lupa kata sandi" di halaman masuk.', 422);
        }
        $attempt = $this->limiter->begin($key, $request->ip());
        if ($attempt === null) {
            return $this->show($request, self::MSG_BLOCKED, 429);
        }
        if (strlen($password) > 1024 || !$this->hasher->verify($password, $user->passwordHash)) {
            $this->audit->record('mfa.password_failure', $user->id, $user->id);

            return $this->show($request, 'Kata sandi salah.', 422);
        }
        $this->limiter->markSuccess($attempt);

        return null;
    }

    private function setupSecret(): string
    {
        $enc = UiSession::get('mfa_setup');
        if (is_string($enc)) {
            return $this->crypto->decrypt($enc);
        }
        $secret = $this->totp->generateSecret();
        UiSession::set('mfa_setup', $this->crypto->encrypt($secret));

        return $secret;
    }

    /** @return list<string> */
    private function pullCodes(): array
    {
        $enc = UiSession::pull('mfa_codes');
        if (!is_string($enc)) {
            return [];
        }
        $list = json_decode($this->crypto->decrypt($enc), true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private function qr(string $uri): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($uri);
    }

    private function user(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('RequireSsoSession wajib');
        }

        return $user;
    }

    private function session(Request $request): SsoSession
    {
        $session = $request->attribute('sso_session');
        if (!$session instanceof SsoSession) {
            throw new \LogicException('RequireSsoSession wajib');
        }

        return $session;
    }
}
