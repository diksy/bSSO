<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class HomeController extends BaseController
{
    public function index(Request $req, array $p): Response
    {
        return $this->redirect($this->user() === null ? '/login' : '/account');
    }

    public function map(Request $req, array $p): Response
    {
        return $this->view('auth/map', ['title' => 'Peta halaman prototype', 'providers' => $this->repo()->providers()], 'plain');
    }

    private const GUIDE = SSO_ROOT . '/content/panduan-integrasi.md';

    public function guide(Request $req, array $p): Response
    {
        $html = \App\Support\Markdown::toHtml((string) file_get_contents(self::GUIDE));
        return $this->view('auth/guide', ['title' => 'Panduan Integrasi', 'guideHtml' => $html], 'plain');
    }

    public function guideMarkdown(Request $req, array $p): Response
    {
        return new Response((string) file_get_contents(self::GUIDE), 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
    }
}
