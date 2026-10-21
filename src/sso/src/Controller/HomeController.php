<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Support\Markdown;
use App\Support\View;

final class HomeController
{
    public function __construct(private readonly View $view, private readonly Markdown $markdown)
    {
    }

    /** Pengguna yang belum masuk diteruskan ke /login oleh RequireSsoSession di /account. */
    public function index(Request $request): Response
    {
        return Response::redirect('/account');
    }

    public function guide(Request $request): Response
    {
        return $this->view->render('auth/guide', [
            'title' => 'Panduan Integrasi',
            'guideHtml' => $this->markdown->toHtml($this->guideSource()),
        ], 'plain');
    }

    public function guideMarkdown(Request $request): Response
    {
        return new Response(200, $this->guideSource(), ['Content-Type' => 'text/markdown; charset=utf-8']);
    }

    private function guideSource(): string
    {
        $root = defined('SSO_ROOT') ? SSO_ROOT : dirname(__DIR__, 2);

        return (string) file_get_contents($root . '/content/panduan-integrasi.md');
    }
}
