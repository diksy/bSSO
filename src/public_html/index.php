<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\Request;

/** @var App\Support\Container $container */
$container = require __DIR__ . '/../sso/bootstrap.php';

/** @var Kernel $kernel */
$kernel = $container->get(Kernel::class);
$kernel->emit($kernel->handle(Request::fromGlobals((array) (($container->get('config.app'))['trusted_proxies'] ?? []))));
