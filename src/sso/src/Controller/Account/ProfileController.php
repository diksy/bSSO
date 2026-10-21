<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Flash;

final class ProfileController
{
    public function __construct(
        private readonly AccountPage $page,
        private readonly UserRepository $users,
        private readonly Audit $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->page->render($request, 'account/profile', 'Profil', 'profile');
    }

    public function save(Request $request): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('RequireSsoSession wajib');
        }
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $request->input('name', '')) ?? '');
        $len = mb_strlen($name);
        if ($len < 1 || $len > 80) {
            return $this->page->render($request, 'account/profile', 'Profil', 'profile', ['error' => 'Nama wajib diisi (maksimal 80 karakter).'], 422);
        }
        $this->users->updateName($user->id, $name);
        $this->audit->record('account.profile_update', $user->id, $user->id);
        Flash::add('ok', 'Profil disimpan.');

        return Response::redirect('/account', 303);
    }
}
