<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Controller\LoginController;
use App\Http\Request;
use App\Http\Response;

final class AdminController extends BaseController
{
    private const SLUGS = [
        'clients' => 'klien', 'providers' => 'provider', 'users' => 'user', 'keys' => 'kunci',
        'audit' => 'audit', 'maintenance' => 'pemeliharaan', 'settings' => 'pengaturan', 'roles' => 'role',
    ];

    /** modul => aksi => perlu step-up (RequireRecentAuth) */
    private const ACTIONS = [
        'klien' => ['save' => true, 'create' => true, 'regen-secret' => true, 'test-logout' => false, 'toggle' => false],
        'provider' => ['save' => true, 'test' => false, 'toggle' => false],
        'user' => ['suspend' => false, 'unlink' => false, 'reset-mfa' => false, 'reset-password' => false, 'revoke-sessions' => false],
        'kunci' => ['rotate' => true, 'rotate-emergency' => true],
        'pemeliharaan' => ['gc' => false, 'retry' => false, 'rotate' => false],
        'pengaturan' => ['save' => true],
        'role' => ['grant' => true, 'revoke' => true],
    ];

    private const STEPUP_SECONDS = 300;

    private function role(): ?string
    {
        $u = $this->user();
        return $u === null ? null : ($_SESSION['demo_role'] ?? $u['admin_role']);
    }

    /** Gerbang RequireAdmin + izin modul. Mengembalikan Response jika ditolak. */
    private function guard(string $module): ?Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $u = $this->user();
        $role = $this->role();
        if ($role === null || $u['admin_role'] === null) {
            return $this->view('auth/error', ['title' => 'Akses ditolak', 'code' => 'access_denied', 'heading' => 'Akses ditolak', 'text' => 'Akun Anda bukan admin. Masuk dengan akun admin demo (lihat halaman /demo).'], 'auth', 403);
        }
        if (!$u['mfa']) {
            return $this->view('admin/mfa_required', ['title' => 'MFA wajib untuk admin'], 'auth', 403);
        }
        if ($this->perm($module)['level'] === 'none') {
            return $this->admin('admin/forbidden', 'Akses ditolak', $module, ['moduleName' => $this->repo()->roles()['modules'][$module]], 403);
        }
        return null;
    }

    private function perm(string $module): array
    {
        $roles = $this->repo()->roles();
        return $roles['matrix'][$module][$this->role() ?? 'auditor'];
    }

    private function admin(string $view, string $title, string $module, array $data = [], int $status = 200): Response
    {
        $roles = $this->repo()->roles();
        $nav = [];
        foreach ($roles['modules'] as $key => $label) {
            $nav[$key] = ['label' => $label, 'perm' => $roles['matrix'][$key][$this->role()], 'url' => $key === 'dasbor' ? '/admin' : '/admin/' . (array_search($key, self::SLUGS, true) ?: $key)];
        }
        $stepFresh = isset($_SESSION['mfa_at']) && time() - (int) $_SESSION['mfa_at'] <= self::STEPUP_SECONDS;
        return $this->view($view, $data + [
            'title' => $title, 'module' => $module, 'nav' => $nav, 'perm' => $this->perm($module),
            'role' => $this->role(), 'roleList' => $roles['roles'], 'stepFresh' => $stepFresh, 'repo' => null,
        ], 'admin', $status);
    }

    public function dashboard(Request $req, array $p): Response
    {
        return $this->guard('dasbor') ?? $this->admin('admin/dashboard', 'Dasbor', 'dasbor', ['d' => $this->repo()->dashboard()]);
    }

    public function clients(Request $req, array $p): Response
    {
        return $this->guard('klien') ?? $this->admin('admin/clients', 'Aplikasi klien', 'klien', ['clients' => $this->repo()->clients()]);
    }

    public function clientForm(Request $req, array $p): Response
    {
        if ($r = $this->guard('klien')) {
            return $r;
        }
        $c = ($p['id'] ?? 'new') === 'new' ? null : $this->repo()->client($p['id']);
        if (($p['id'] ?? 'new') !== 'new' && $c === null) {
            return $this->admin('admin/forbidden', 'Tidak ditemukan', 'klien', ['moduleName' => 'Klien tidak ditemukan'], 404);
        }
        return $this->admin('admin/client_form', $c ? 'Edit ' . $c['name'] : 'Klien baru', 'klien', ['c' => $c]);
    }

    public function providers(Request $req, array $p): Response
    {
        return $this->guard('provider') ?? $this->admin('admin/providers', 'Provider', 'provider', ['providers' => $this->repo()->providers()]);
    }

    public function providerForm(Request $req, array $p): Response
    {
        if ($r = $this->guard('provider')) {
            return $r;
        }
        $pr = $this->repo()->provider($p['slug']);
        if ($pr === null) {
            return $this->admin('admin/forbidden', 'Tidak ditemukan', 'provider', ['moduleName' => 'Provider tidak ditemukan'], 404);
        }
        return $this->admin('admin/provider_form', 'Provider ' . $pr['name'], 'provider', ['pr' => $pr, 'callback' => 'https://sso.contoh.test/federation/' . $pr['slug'] . '/callback']);
    }

    public function users(Request $req, array $p): Response
    {
        if ($r = $this->guard('user')) {
            return $r;
        }
        $q = mb_strtolower($req->input('q'));
        $list = array_filter($this->repo()->users(), static fn (array $u): bool => $q === '' || str_contains(mb_strtolower($u['email'] . ' ' . $u['name']), $q));
        return $this->admin('admin/users', 'User & sesi', 'user', ['users' => $list, 'q' => $req->input('q')]);
    }

    public function userDetail(Request $req, array $p): Response
    {
        if ($r = $this->guard('user')) {
            return $r;
        }
        $u = $this->repo()->user((int) $p['id']);
        if ($u === null) {
            return $this->admin('admin/forbidden', 'Tidak ditemukan', 'user', ['moduleName' => 'User tidak ditemukan'], 404);
        }
        $locked = $u['admin_role'] !== null && $this->role() === 'operator';
        $logins = [['2026-10-21 08:12', 'berhasil', '203.0.113.10'], ['2026-10-20 17:40', 'berhasil', '198.51.100.24'], ['2026-10-19 21:03', 'gagal', '192.0.2.55']];
        return $this->admin('admin/user_detail', $u['name'], 'user', ['u' => $u, 'locked' => $locked, 'sessions' => $this->repo()->sessions(), 'logins' => $logins, 'providers' => $this->repo()->providers()]);
    }

    public function keys(Request $req, array $p): Response
    {
        return $this->guard('kunci') ?? $this->admin('admin/keys', 'Kunci', 'kunci', ['keys' => $this->repo()->keys()]);
    }

    private function auditFiltered(Request $req): array
    {
        $rows = $_SESSION['audit_extra'] ?? [];
        $rows = array_merge(array_reverse($rows), $this->repo()->audit());
        $f = ['actor' => mb_strtolower($req->input('actor')), 'target' => mb_strtolower($req->input('target')), 'event' => mb_strtolower($req->input('event')), 'from' => $req->input('from'), 'to' => $req->input('to')];
        return array_values(array_filter($rows, static function (array $r) use ($f): bool {
            return ($f['actor'] === '' || str_contains(mb_strtolower($r['actor']), $f['actor']))
                && ($f['target'] === '' || str_contains(mb_strtolower($r['target']), $f['target']))
                && ($f['event'] === '' || str_contains(mb_strtolower($r['event']), $f['event']))
                && ($f['from'] === '' || substr($r['time'], 0, 10) >= $f['from'])
                && ($f['to'] === '' || substr($r['time'], 0, 10) <= $f['to']);
        }));
    }

    public function audit(Request $req, array $p): Response
    {
        if ($r = $this->guard('audit')) {
            return $r;
        }
        $rows = $this->auditFiltered($req);
        $per = 10;
        $pages = max(1, (int) ceil(count($rows) / $per));
        $page = min($pages, max(1, (int) ($req->query['page'] ?? 1)));
        $qs = array_filter(array_intersect_key($req->query, array_flip(['actor', 'target', 'event', 'from', 'to'])), 'strlen');
        return $this->admin('admin/audit', 'Audit log', 'audit', [
            'rows' => array_slice($rows, ($page - 1) * $per, $per), 'page' => $page, 'pages' => $pages, 'total' => count($rows),
            'f' => ['actor' => $req->input('actor'), 'target' => $req->input('target'), 'event' => $req->input('event'), 'from' => $req->input('from'), 'to' => $req->input('to')],
            'qs' => http_build_query($qs),
        ]);
    }

    public function auditExport(Request $req, array $p): Response
    {
        if ($r = $this->guard('audit')) {
            return $r;
        }
        if ($this->role() === 'operator') {
            return $this->admin('admin/forbidden', 'Akses ditolak', 'audit', ['moduleName' => 'Ekspor audit log (operator hanya baca)'], 403);
        }
        $out = "waktu,pelaku,event,target\n";
        foreach ($this->auditFiltered($req) as $r) {
            $out .= implode(',', [$r['time'], $r['actor'], $r['event'], $r['target']]) . "\n";
        }
        return new Response($out, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="audit-log-demo.csv"']);
    }

    public function maintenance(Request $req, array $p): Response
    {
        return $this->guard('pemeliharaan') ?? $this->admin('admin/maintenance', 'Pemeliharaan', 'pemeliharaan');
    }

    public function settings(Request $req, array $p): Response
    {
        return $this->guard('pengaturan') ?? $this->admin('admin/settings', 'Pengaturan', 'pengaturan', ['s' => $_SESSION['settings'] ?? $this->repo()->settings()]);
    }

    public function roles(Request $req, array $p): Response
    {
        if ($r = $this->guard('role')) {
            return $r;
        }
        $admins = array_filter($this->repo()->users(), static fn (array $u): bool => $u['admin_role'] !== null);
        $others = array_filter($this->repo()->users(), static fn (array $u): bool => $u['admin_role'] === null);
        return $this->admin('admin/roles', 'Role admin', 'role', ['admins' => $admins, 'others' => $others, 'matrix' => $this->repo()->roles()]);
    }

    /* ---- Demo & step-up ---- */

    public function switchRole(Request $req, array $p): Response
    {
        $role = $req->input('role');
        if ($this->user() !== null && isset($this->repo()->roles()['roles'][$role])) {
            $_SESSION['demo_role'] = $role;
            $_SESSION['mfa_at'] = time();
            flash('Role demo diganti menjadi ' . $this->repo()->roles()['roles'][$role] . '.');
        }
        return $this->redirect('/admin');
    }

    public function expireStepup(Request $req, array $p): Response
    {
        $_SESSION['mfa_at'] = time() - self::STEPUP_SECONDS - 1;
        flash('Simulasi: 5 menit berlalu sejak verifikasi MFA terakhir.');
        return $this->redirect($req->input('back') !== '' && preg_match('#^/admin[a-z/\-]*$#', $req->input('back')) === 1 ? $req->input('back') : '/admin');
    }

    public function stepupForm(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        return $this->admin('admin/stepup', 'Verifikasi ulang', 'dasbor', ['error' => null, 'pending' => $_SESSION['pending_action'] ?? null]);
    }

    public function stepupSubmit(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        if ($req->input('code') !== LoginController::DEMO_TOTP) {
            return $this->admin('admin/stepup', 'Verifikasi ulang', 'dasbor', ['error' => 'Kode tidak valid.', 'pending' => $_SESSION['pending_action'] ?? null], 422);
        }
        $_SESSION['mfa_at'] = time();
        $pending = $_SESSION['pending_action'] ?? null;
        unset($_SESSION['pending_action']);
        if (is_array($pending)) {
            return $this->execute($pending['module'], $pending['action'], $pending['post'], $pending['back']);
        }
        return $this->redirect('/admin');
    }

    /* ---- Aksi POST ---- */

    public function act(Request $req, array $p): Response
    {
        $module = self::SLUGS[$p['module']] ?? null;
        if ($module === null || !isset(self::ACTIONS[$module][$p['action']])) {
            return $this->redirect('/error/404');
        }
        if ($r = $this->guard($module)) {
            return $r;
        }
        $back = '/admin/' . $p['module'];
        if ($this->perm($module)['level'] !== 'write') {
            flash('Role Anda hanya dapat membaca modul ini.', 'err');
            return $this->redirect($back);
        }
        $post = array_filter($req->post, 'is_string');
        unset($post['_csrf']);
        if (isset($post['back']) && preg_match('#^/admin[a-z0-9/\-]*$#', $post['back']) === 1) {
            $back = $post['back'];
        }
        $fresh = isset($_SESSION['mfa_at']) && time() - (int) $_SESSION['mfa_at'] <= self::STEPUP_SECONDS;
        if (self::ACTIONS[$module][$p['action']] && !$fresh) {
            $_SESSION['pending_action'] = ['module' => $module, 'action' => $p['action'], 'post' => $post, 'back' => $back];
            return $this->redirect('/admin/stepup');
        }
        return $this->execute($module, $p['action'], $post, $back);
    }

    private function log(string $event, string $target, array $before = [], array $after = []): void
    {
        $_SESSION['audit_extra'][] = [
            'id' => 2000 + count($_SESSION['audit_extra'] ?? []), 'time' => date('Y-m-d H:i'), 'actor' => $this->user()['email'] ?? '-',
            'event' => $event, 'target' => $target, 'context' => ($before || $after) ? ['before' => $before, 'after' => $after] : [],
        ];
    }

    private function execute(string $module, string $action, array $post, string $back): Response
    {
        $self = $this->user()['email'];
        $id = $post['id'] ?? '-';
        $msg = 'Aksi dijalankan (simulasi, tidak ada data nyata berubah).';
        $type = 'ok';

        if ($module === 'user') {
            $target = $this->repo()->user((int) $id);
            if ($target !== null && $target['admin_role'] !== null && $this->role() === 'operator') {
                flash('Operator tidak boleh mengelola user ber-role admin.', 'err');
                return $this->redirect($back);
            }
            $label = $target['email'] ?? $id;
            $names = ['suspend' => 'Status user diubah', 'unlink' => 'Tautan provider diputus', 'reset-mfa' => 'MFA user di-reset', 'reset-password' => 'Email reset kata sandi dikirim (simulasi)', 'revoke-sessions' => 'Semua sesi user dicabut'];
            $msg = $names[$action] . ' untuk ' . $label . '.';
            $this->log('user.' . $action, (string) $label);
        } elseif ($module === 'klien') {
            if ($action === 'regen-secret' || $action === 'create') {
                $secret = 'demo_' . substr(hash('sha256', uniqid('', true)), 0, 24);
                $_SESSION['one_time_secret'] = ['for' => $post['id'] ?? ($post['name'] ?? 'klien baru'), 'secret' => $secret];
                $msg = $action === 'create' ? 'Klien dibuat.' : 'Secret baru dibuat.';
                $this->log('client.' . $action, (string) ($post['id'] ?? 'baru'));
            } elseif ($action === 'test-logout') {
                $msg = 'Logout token uji dikirim ke endpoint back-channel (simulasi): HTTP 200.';
            } else {
                $msg = 'Pengaturan klien disimpan.';
                $this->log('client.' . $action, (string) $id, ['catatan' => 'nilai lama'], ['catatan' => 'nilai baru']);
            }
        } elseif ($module === 'provider') {
            $msg = $action === 'test' ? 'Tes koneksi: discovery dan JWKS berhasil diambil (simulasi).' : 'Provider disimpan. Secret tidak ditampilkan kembali.';
            if ($action !== 'test') {
                $this->log('provider.' . $action, (string) $id);
            }
        } elseif ($module === 'kunci') {
            if ($action === 'rotate-emergency' && ($post['confirm'] ?? '') !== 'ROTATE') {
                flash('Ketik ROTATE persis (huruf besar) untuk rotasi darurat.', 'err');
                return $this->redirect($back);
            }
            $msg = $action === 'rotate' ? 'Rotasi manual dijadwalkan: kunci "next" menjadi aktif.' : 'Rotasi darurat dilakukan: kunci aktif langsung dipensiunkan.';
            $this->log('key.' . $action, 'kunci');
        } elseif ($module === 'pemeliharaan') {
            $names = ['gc' => 'GC selesai: 128 baris kedaluwarsa dihapus.', 'retry' => 'Retry notifikasi logout: 1 terkirim, 0 gagal.', 'rotate' => 'Rotasi kunci terjadwal diperiksa: belum jatuh tempo.'];
            $msg = $names[$action];
            $this->log('maintenance.' . $action, $action);
        } elseif ($module === 'pengaturan') {
            $s = $this->repo()->settings();
            $new = $s;
            foreach (['sso_session_hours', 'password_min', 'rate_limit_attempts', 'rate_limit_window_min', 'key_rotation_days', 'key_overlap_days', 'admin_idle_minutes'] as $k) {
                $new[$k] = max(1, (int) ($post[$k] ?? $s[$k]));
            }
            $new['mfa_required_all'] = isset($post['mfa_required_all']);
            $old = $_SESSION['settings'] ?? $s;
            $_SESSION['settings'] = $new;
            $this->log('settings.update', 'pengaturan', array_diff_assoc($old, $new), array_diff_assoc($new, $old));
            $msg = 'Pengaturan disimpan.';
        } elseif ($module === 'role') {
            $target = $this->repo()->user((int) $id);
            if ($target === null) {
                flash('Pilih user terlebih dahulu.', 'err');
                return $this->redirect($back);
            }
            if ($target['email'] === $self) {
                flash('Anda tidak bisa mengubah atau mencabut role Anda sendiri.', 'err');
                return $this->redirect($back);
            }
            $supers = count(array_filter($this->repo()->users(), static fn (array $u): bool => $u['admin_role'] === 'superadmin'));
            if ($target['admin_role'] === 'superadmin' && $supers <= 1 && ($action === 'revoke' || ($post['role'] ?? '') !== 'superadmin')) {
                flash('Superadmin terakhir tidak bisa dihapus.', 'err');
                return $this->redirect($back);
            }
            $msg = $action === 'revoke' ? 'Role ' . $target['email'] . ' dicabut.' : 'Role ' . $target['email'] . ' diatur menjadi ' . ($post['role'] ?? '-') . '.';
            $this->log('role.' . $action, $target['email'], ['role' => $target['admin_role']], ['role' => $action === 'revoke' ? null : ($post['role'] ?? null)]);
        }
        flash($msg, $type);
        return $this->redirect($back);
    }
}
