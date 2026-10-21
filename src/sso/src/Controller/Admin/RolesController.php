<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminPolicy;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AdminRoleRepository;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Flash;

/** Role admin (hanya superadmin; grant/revoke wajib step-up). Tanpa IDOR: semua target divalidasi di server. */
final class RolesController
{
    public function __construct(
        private readonly AdminPage $page,
        private readonly AdminRoleRepository $roles,
        private readonly UserRepository $users,
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $policy = $this->page->policy();
        $admins = [];
        $adminIds = [];
        foreach ($this->roles->listAll() as $r) {
            $u = $this->users->findById($r->userId);
            $adminIds[$r->userId] = true;
            $admins[] = ['id' => $r->userId, 'email' => $u->email ?? ('#' . $r->userId), 'admin_role' => $r->role];
        }
        $others = [];
        foreach ($this->users->search('', 200) as $u) {
            if (!isset($adminIds[$u->id]) && $u->status === 'active' && $u->email !== null) {
                $others[] = ['id' => $u->id, 'email' => $u->email];
            }
        }
        $matrix = ['roles' => [], 'modules' => [], 'matrix' => []];
        foreach (array_keys(AdminPolicy::MATRIX) as $role) {
            $matrix['roles'][$role] = ucfirst($role);
        }
        foreach (AdminPolicy::MODULES as $key => [$label]) {
            $matrix['modules'][$key] = $label;
            foreach (array_keys(AdminPolicy::MATRIX) as $role) {
                $level = $policy->level($role, $key);
                $matrix['matrix'][$key][$role] = [
                    'level' => $level === 'manage' ? 'write' : $level,
                    'label' => $policy->label($role, $key),
                ];
            }
        }

        return $this->page->render($request, 'admin/roles', 'Role admin', 'roles', [
            'admins' => $admins, 'others' => $others, 'matrix' => $matrix,
        ]);
    }

    /** Beri role baru atau ubah role admin yang ada. */
    public function grant(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $actorRole = $this->page->role($request);
        $targetId = ctype_digit($request->input('id') ?? '') ? (int) $request->input('id') : 0;
        $newRole = (string) $request->input('role', '');
        $back = $this->page->back($request, '/admin/roles');
        if ($targetId <= 0 || !AdminPolicy::isRole($newRole)) {
            Flash::add('err', 'Permintaan tidak valid.');

            return Response::redirect($back, 303);
        }
        if ($targetId === $actor->id) {
            Flash::add('err', 'Anda tidak bisa mengubah role sendiri.');

            return Response::redirect($back, 303);
        }

        $error = $this->db->transaction(function () use ($actor, $actorRole, $targetId, $newRole): ?string {
            $this->roles->lockSuperadmins();
            $target = $this->users->findByIdForUpdate($targetId);
            if ($target === null || $target->status !== 'active') {
                return 'User tidak ditemukan atau tidak aktif.';
            }
            $current = $this->roles->findByUserId($targetId);
            $policy = $this->page->policy();
            if (!$policy->canAssign($actorRole, $newRole, $current?->role)) {
                $this->audit->record('role.escalation_blocked', $actor->id, $targetId, null, [
                    'actor_role' => $actorRole, 'requested' => $newRole, 'current' => $current?->role,
                ]);

                return 'Anda tidak berhak memberikan role tersebut.';
            }
            $now = $this->clock->now();
            if ($current === null) {
                $this->roles->create($targetId, $newRole, $actor->id, $now);
            } else {
                if ($current->role === $newRole) {
                    return null;
                }
                if ($current->role === 'superadmin' && count($this->roles->lockSuperadmins()) <= 1) {
                    return 'Superadmin terakhir tidak bisa diturunkan.';
                }
                $this->roles->updateRole($targetId, $newRole, $actor->id, $now);
            }
            $this->audit->record($current === null ? 'role.grant' : 'role.change', $actor->id, $targetId, null, [
                'before' => ['role' => $current?->role], 'after' => ['role' => $newRole],
            ]);

            return null;
        });
        if (is_string($error)) {
            Flash::add('err', $error);
        } else {
            Flash::add('ok', 'Role admin disimpan.');
        }

        return Response::redirect($back, 303);
    }

    public function revoke(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $actorRole = $this->page->role($request);
        $idParam = (string) $request->attribute('id');
        $targetId = ctype_digit($idParam) ? (int) $idParam : 0;
        $back = $this->page->back($request, '/admin/roles');
        if ($targetId <= 0) {
            throw new HttpException(404);
        }
        if ($targetId === $actor->id) {
            Flash::add('err', 'Anda tidak bisa mencabut role sendiri.');

            return Response::redirect($back, 303);
        }
        $error = $this->db->transaction(function () use ($actor, $actorRole, $targetId): ?string {
            $supers = $this->roles->lockSuperadmins();
            $current = $this->roles->findByUserId($targetId);
            if ($current === null) {
                return 'User itu bukan admin.';
            }
            if (!$this->page->policy()->canAssign($actorRole, $current->role, $current->role)) {
                $this->audit->record('role.escalation_blocked', $actor->id, $targetId, null, [
                    'actor_role' => $actorRole, 'action' => 'revoke', 'current' => $current->role,
                ]);

                return 'Anda tidak berhak mencabut role tersebut.';
            }
            if ($current->role === 'superadmin' && count($supers) <= 1) {
                return 'Superadmin terakhir tidak bisa dihapus.';
            }
            $this->roles->delete($targetId);
            $this->audit->record('role.revoke', $actor->id, $targetId, null, [
                'before' => ['role' => $current->role], 'after' => ['role' => null],
            ]);

            return null;
        });
        Flash::add(is_string($error) ? 'err' : 'ok', is_string($error) ? $error : 'Role admin dicabut.');

        return Response::redirect($back, 303);
    }
}
