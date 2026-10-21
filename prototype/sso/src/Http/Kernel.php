<?php
declare(strict_types=1);

namespace App\Http;

use App\Controller\ErrorController;

final class Kernel
{
    public function run(): void
    {
        $req = Request::fromGlobals();
        if ($req->method === 'POST') {
            $sent = (string) ($req->post['_csrf'] ?? '');
            if (!hash_equals(csrf_token(), $sent)) {
                (new ErrorController())->show($req, ['kind' => 'csrf'])->send();
                return;
            }
        }
        $router = new Router();
        require SSO_ROOT . '/routes.php';
        $res = $router->dispatch($req) ?? (new ErrorController())->show($req, ['kind' => '404']);
        $res->send();
    }
}
