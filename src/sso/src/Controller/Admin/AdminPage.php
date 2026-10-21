<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminPolicy;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Support\SafePath;
use App\Support\View;

/** Helper bersama controller admin: render layout admin (nav/perm/role dari AdminPolicy) dan ambil aktor. */
final class AdminPage
{
    public function __construct(private readonly View $view, private readonly AdminPolicy $policy)
    {
    }

    public function policy(): AdminPolicy
    {
        return $this->policy;
    }

    public function actor(Request $r): User
    {
        $user = $r->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('RequireSsoSession wajib');
        }

        return $user;
    }

    public function session(Request $r): SsoSession
    {
        $s = $r->attribute('sso_session');
        if (!$s instanceof SsoSession) {
            throw new \LogicException('RequireSsoSession wajib');
        }

        return $s;
    }

    public function role(Request $r): string
    {
        $role = $r->attribute('admin_role');
        if (!is_string($role) || !AdminPolicy::isRole($role)) {
            throw new \LogicException('RequireAdmin wajib');
        }

        return $role;
    }

    /** Tujuan redirect setelah aksi: hanya path internal di bawah /admin. */
    public function back(Request $r, string $default): string
    {
        $back = SafePath::clean($r->input('back'), $default);

        return str_starts_with($back, '/admin') ? $back : $default;
    }

    /**
     * @param array<string,mixed> $data boleh menimpa 'perm' dan 'role'
     */
    public function render(Request $r, string $view, string $title, string $module, array $data = [], int $status = 200): Response
    {
        $user = $this->actor($r);
        $role = is_string($data['role'] ?? null) ? $data['role'] : $this->role($r);

        return $this->view->render($view, $data + [
            'title' => $title,
            'module' => $module,
            'role' => $role,
            'perm' => $this->policy->level($role, $module),
            'nav' => $this->policy->nav($role),
            'error' => null,
            'user' => ['name' => $user->name ?? '', 'email' => $user->email ?? ''],
        ], 'admin', $status);
    }
}
