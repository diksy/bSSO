<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class InstallController extends BaseController
{
    public function form(Request $req, array $p): Response
    {
        return $this->view('auth/install', ['title' => 'Pasang BikininSSO', 'done' => false, 'error' => null]);
    }

    public function submit(Request $req, array $p): Response
    {
        $email = $req->input('email');
        $pw = $req->input('password');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($pw) < 12 || $pw !== $req->input('password2')) {
            return $this->view('auth/install', ['title' => 'Pasang BikininSSO', 'done' => false, 'error' => 'Isi email valid dan kata sandi minimal 12 karakter yang sama di kedua kolom.'], 'auth', 422);
        }
        return $this->view('auth/install', ['title' => 'Pemasangan selesai', 'done' => true, 'error' => null]);
    }
}
