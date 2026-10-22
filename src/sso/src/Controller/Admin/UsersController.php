<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AdminRoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\IdentityProviderRepository;
use App\Repository\Record\User;
use App\Repository\SsoSessionRepository;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Security\SignedTokens;
use App\Session\SessionTerminator;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\Urls;

/**
 * User & sesi (F8). Setiap aksi: target dimuat dari DB (404 bila tidak ada), AdminPolicy::canManageUser (operator tidak
 * boleh menyentuh user ber-role admin), dan admin tidak boleh menimpa dirinya sendiri / superadmin terakhir.
 */
final class UsersController
{
    private const STATUS_LABEL = ['active' => 'aktif', 'suspended' => 'ditangguhkan', 'pending' => 'menunggu'];

    public function __construct(
        private readonly AdminPage $page,
        private readonly UserRepository $users,
        private readonly UserIdentityRepository $identities,
        private readonly IdentityProviderRepository $providers,
        private readonly SsoSessionRepository $sessions,
        private readonly AdminRoleRepository $roles,
        private readonly AuditLogRepository $auditLogs,
        private readonly SessionTerminator $terminator,
        private readonly SignedTokens $tokens,
        private readonly Mailer $mailer,
        private readonly Urls $urls,
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $q = $request->query['q'] ?? '';
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';
        $rows = [];
        foreach ($this->users->search($q, 50) as $u) {
            $rows[] = [
                'id' => $u->id, 'name' => $u->name ?? '', 'email' => $u->email ?? '', 'admin_role' => $this->roles->findByUserId($u->id)?->role,
                'mfa' => $u->mfaEnabledAt !== null, 'status' => self::STATUS_LABEL[$u->status] ?? $u->status,
            ];
        }

        return $this->page->render($request, 'admin/users', 'User & sesi', 'users', ['users' => $rows, 'q' => $q]);
    }

    public function show(Request $request): Response
    {
        $target = $this->target($request);
        $adminRole = $this->roles->findByUserId($target->id)?->role;
        $locked = !$this->page->policy()->canManageUser($this->page->role($request), $adminRole);
        $slugs = [];
        $bySlug = [];
        foreach ($this->providers->listAll() as $p) {
            $bySlug[$p->id] = $p->slug;
        }
        foreach ($this->identities->listForUser($target->id) as $i) {
            $slugs[] = $bySlug[$i->providerId] ?? ('#' . $i->providerId);
        }
        $logins = [];
        foreach ($this->auditLogs->listFiltered(['target_id' => $target->id, 'event' => 'login.'], 10, 0) as $l) {
            $logins[] = [$l->createdAt->format('Y-m-d H:i'), $l->event, $l->ipAddress ?? '-'];
        }
        $sessions = [];
        foreach ($this->sessions->listActiveForUser($target->id, $this->clock->now()) as $s) {
            $sessions[] = [
                'sid' => $s->sid, 'device' => mb_substr($s->userAgent ?? '-', 0, 80), 'ip' => $s->ipAddress ?? '-',
                'started' => $s->createdAt->format('Y-m-d H:i'),
            ];
        }
        $u = [
            'id' => $target->id, 'email' => $target->email ?? '', 'name' => $target->name ?? '', 'admin_role' => $adminRole,
            'mfa' => $target->mfaEnabledAt !== null, 'status' => self::STATUS_LABEL[$target->status] ?? $target->status,
            'status_raw' => $target->status, 'identities' => $slugs,
        ];

        return $this->page->render($request, 'admin/user_detail', 'User: ' . ($target->email ?? '#' . $target->id), 'users', [
            'u' => $u, 'locked' => $locked, 'logins' => $logins, 'sessions' => $sessions,
            'self' => $target->id === $this->page->actor($request)->id,
        ]);
    }

    /** Suspend <-> aktifkan. Tidak untuk diri sendiri, user pending, atau superadmin aktif terakhir. */
    public function setStatus(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $target = $this->target($request, true);
        $back = $this->backTo($request, $target);
        if ($target->id === $actor->id) {
            return $this->fail($back, 'Anda tidak bisa mengubah status akun sendiri.');
        }
        $error = $this->db->transaction(function () use ($actor, $target): ?string {
            $supers = $this->roles->lockSuperadmins();
            $fresh = $this->users->findByIdForUpdate($target->id);
            if ($fresh === null || !in_array($fresh->status, ['active', 'suspended'], true)) {
                return 'Status user ini tidak bisa diubah (belum aktif).';
            }
            $to = $fresh->status === 'active' ? 'suspended' : 'active';
            if ($to === 'suspended' && in_array($fresh->id, $supers, true)) {
                $others = 0;
                foreach ($supers as $sid) {
                    $o = $sid === $fresh->id ? null : $this->users->findById($sid);
                    if ($o !== null && $o->status === 'active') {
                        $others++;
                    }
                }
                if ($others === 0) {
                    return 'Superadmin terakhir tidak bisa dinonaktifkan.';
                }
            }
            if (!$this->users->setStatus($fresh->id, $fresh->status, $to)) {
                return 'Status user berubah di tempat lain; coba lagi.';
            }
            if ($to === 'suspended') {
                $this->terminator->endAllForUser($fresh->id, null, 'admin_suspend');
            }
            $this->audit->record('user.status', $actor->id, $fresh->id, null, [
                'before' => ['status' => $fresh->status], 'after' => ['status' => $to],
            ]);

            return null;
        });

        return is_string($error) ? $this->fail($back, $error) : $this->done($back, 'Status user diperbarui.');
    }

    public function resetMfa(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $target = $this->target($request, true);
        $back = $this->backTo($request, $target);
        if ($target->id === $actor->id) {
            return $this->fail($back, 'Atur MFA akun sendiri lewat halaman akun.');
        }
        $this->db->transaction(function () use ($actor, $target): void {
            $this->users->disableMfa($target->id);
            $this->terminator->endAllForUser($target->id, null, 'admin_reset_mfa');
            $this->audit->record('user.mfa_reset', $actor->id, $target->id, null, [
                'before' => ['mfa' => $target->mfaEnabledAt !== null], 'after' => ['mfa' => false],
            ]);
        });

        return $this->done($back, 'MFA direset; semua sesi user dicabut.');
    }

    /** Kirim tautan atur ulang password (email) dan cabut semua sesi user. Password lama tidak dihapus. */
    public function forcePasswordReset(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $target = $this->target($request, true);
        $back = $this->backTo($request, $target);
        if ($target->id === $actor->id) {
            return $this->fail($back, 'Gunakan halaman akun untuk mengganti password sendiri.');
        }
        if ($target->email === null || !in_array($target->status, ['active', 'pending'], true) || !$this->urls->available()) {
            return $this->fail($back, 'Email tidak dapat dikirim (user tanpa email/tidak aktif atau base_url belum diatur).');
        }
        $token = $this->tokens->issue(SignedTokens::RESET, $target->id, $target->passwordHash ?? '');
        try {
            $this->mailer->send(
                $target->email,
                'Atur ulang kata sandi BikininSSO',
                "Administrator meminta Anda mengatur ulang kata sandi. Buka tautan berikut (berlaku 1 jam, sekali pakai):\n\n"
                . $this->urls->abs('/password/reset?token=' . $token) . "\n\nAbaikan email ini jika Anda tidak mengenalinya.",
            );
        } catch (\Throwable $e) {
            $this->logger->error('Email reset paksa gagal: ' . $e::class);

            return $this->fail($back, 'Pengiriman email gagal.');
        }
        $this->terminator->endAllForUser($target->id, null, 'admin_force_reset');
        $this->audit->record('user.force_reset', $actor->id, $target->id, null, ['after' => ['reset_link_sent' => true]]);

        return $this->done($back, 'Tautan reset dikirim dan semua sesi user dicabut.');
    }

    /** Putuskan tautan provider; tidak boleh membuat user tanpa cara masuk sama sekali. */
    public function unlink(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $target = $this->target($request, true);
        $back = $this->backTo($request, $target);
        $slug = (string) $request->attribute('slug');
        $provider = preg_match('/^[a-z0-9_-]{1,30}$/', $slug) === 1 ? $this->providers->findBySlug($slug) : null;
        $identity = $provider === null ? null : $this->identities->findByUserAndProvider($target->id, $provider->id);
        if ($provider === null || $identity === null) {
            throw new HttpException(404);
        }
        if ($target->id === $actor->id) {
            return $this->fail($back, 'Putuskan tautan akun sendiri lewat halaman akun.');
        }
        $error = $this->db->transaction(function () use ($actor, $target, $provider, $identity): ?string {
            $fresh = $this->users->findByIdForUpdate($target->id);
            $others = count($this->identities->listForUser($target->id)) - 1;
            if ($fresh === null || ($fresh->passwordHash === null && $others < 1)) {
                return 'Tautan terakhir tidak bisa diputuskan: user tidak punya password.';
            }
            if (!$this->identities->deleteForUser($target->id, $identity->id)) {
                return 'Tautan tidak ditemukan.';
            }
            $this->audit->record('identity.unlinked_by_admin', $actor->id, $target->id, null, [
                'before' => ['provider' => $provider->slug, 'linked' => true], 'after' => ['provider' => $provider->slug, 'linked' => false],
            ]);

            return null;
        });

        return is_string($error) ? $this->fail($back, $error) : $this->done($back, 'Tautan provider diputuskan.');
    }

    public function revokeSession(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $target = $this->target($request, true);
        $back = $this->backTo($request, $target);
        $sid = (string) $request->attribute('sid');
        $session = preg_match('/^[0-9a-fA-F-]{36}$/', $sid) === 1 ? $this->sessions->findBySid($sid) : null;
        // IDOR: sesi harus milik user pada URL.
        if ($session === null || $session->userId !== $target->id || $session->revokedAt !== null) {
            throw new HttpException(404);
        }
        $this->terminator->endSession($session->sid, 'admin_revoke');
        $this->audit->record('session.admin_revoke', $actor->id, $target->id, null, ['sid' => $session->sid]);

        return $this->done($back, 'Sesi dicabut.');
    }

    /** Muat target dari id rute; 404 bila tidak ada; 403 (dan audit) bila kebijakan melarang aktor mengelolanya. */
    private function target(Request $request, bool $manage = false): User
    {
        $raw = (string) $request->attribute('id');
        $user = ctype_digit($raw) && strlen($raw) <= 18 ? $this->users->findById((int) $raw) : null;
        if ($user === null) {
            throw new HttpException(404);
        }
        if ($manage) {
            $actor = $this->page->actor($request);
            $adminRole = $this->roles->findByUserId($user->id)?->role;
            if (!$this->page->policy()->canManageUser($this->page->role($request), $adminRole)) {
                $this->audit->record('user.manage_denied', $actor->id, $user->id, null, [
                    'actor_role' => $this->page->role($request), 'target_role' => $adminRole,
                ]);
                throw new HttpException(403);
            }
        }

        return $user;
    }

    private function backTo(Request $request, User $target): string
    {
        return $this->page->back($request, '/admin/users/' . $target->id);
    }

    private function done(string $back, string $message): Response
    {
        Flash::add('ok', $message);

        return Response::redirect($back, 303);
    }

    private function fail(string $back, string $message): Response
    {
        Flash::add('err', $message);

        return Response::redirect($back, 303);
    }
}
