<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class LogoutController extends BaseController
{
    public function confirm(Request $req, array $p): Response
    {
        return $this->view('auth/logout_confirm', ['title' => 'Keluar']);
    }

    public function submit(Request $req, array $p): Response
    {
        $_SESSION = [];
        session_regenerate_id(true);
        return $this->redirect('/logged-out');
    }

    public function done(Request $req, array $p): Response
    {
        return $this->view('auth/logged_out', ['title' => 'Anda sudah keluar']);
    }
}
