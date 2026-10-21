<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\Request;
use App\Http\Response;
use App\Repository\AdminRoleRepository;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Support\View;

/** Helper render halaman akun (layout account) dengan data $user untuk view. */
final class AccountPage
{
    public function __construct(private readonly View $view, private readonly AdminRoleRepository $roles)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(Request $r, string $view, string $title, string $tab, array $data = [], int $status = 200): Response
    {
        $user = $r->attribute('user');
        $session = $r->attribute('sso_session');
        if (!$user instanceof User || !$session instanceof SsoSession) {
            throw new \LogicException('RequireSsoSession wajib');
        }
        $fmt = static fn (\DateTimeImmutable $t): string => $t->format('Y-m-d H:i') . ' UTC';

        return $this->view->render($view, $data + [
            'title' => $title,
            'tab' => $tab,
            'error' => null,
            'user' => [
                'name' => $user->name ?? '',
                'email' => $user->email ?? '',
                'created' => $fmt($user->createdAt),
                'last_login' => $fmt($session->createdAt),
                'admin_role' => $this->roles->findByUserId($user->id)?->role,
            ],
        ], 'account', $status);
    }
}
