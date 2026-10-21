<?php
declare(strict_types=1);

// Prototype BikininSSO: data dummy saja, tanpa DB/jaringan/kripto nyata.
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('SSO_ROOT', __DIR__);
define('MOCKS_PATH', dirname(__DIR__) . '/mocks');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = SSO_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require SSO_ROOT . '/src/Support/helpers.php';

session_name('bsso_proto');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

(new App\Http\Kernel())->run();
