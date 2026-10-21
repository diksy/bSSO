<?php
declare(strict_types=1);

use App\Controller\Account\AccountController;
use App\Controller\Admin\AdminController;
use App\Controller\ErrorController;
use App\Controller\FederationController;
use App\Controller\HomeController;
use App\Controller\InstallController;
use App\Controller\LoginController;
use App\Controller\LogoutController;

/** @var App\Http\Router $router */
$router->add('GET', '/', [HomeController::class, 'index']);
$router->add('GET', '/demo', [HomeController::class, 'map']);

$router->add('GET', '/panduan-integrasi', [HomeController::class, 'guide']);
$router->add('GET', '/panduan-integrasi.md', [HomeController::class, 'guideMarkdown']);

$router->add('GET', '/login', [LoginController::class, 'form']);
$router->add('POST', '/login', [LoginController::class, 'submit']);
$router->add('GET', '/login/mfa', [LoginController::class, 'mfaForm']);
$router->add('POST', '/login/mfa', [LoginController::class, 'mfaSubmit']);

$router->add('GET', '/federation/{slug}/start', [FederationController::class, 'start']);
$router->add('POST', '/federation/{slug}/callback', [FederationController::class, 'callback']);
$router->add('GET', '/federation/{slug}/confirm-link', [FederationController::class, 'confirmForm']);
$router->add('POST', '/federation/{slug}/confirm-link', [FederationController::class, 'confirmSubmit']);
$router->add('GET', '/federation/{slug}/complete-email', [FederationController::class, 'emailForm']);
$router->add('POST', '/federation/{slug}/complete-email', [FederationController::class, 'emailSubmit']);
$router->add('POST', '/federation/{slug}/verify-email', [FederationController::class, 'verify']);

$router->add('GET', '/logout', [LogoutController::class, 'confirm']);
$router->add('POST', '/logout', [LogoutController::class, 'submit']);
$router->add('GET', '/logged-out', [LogoutController::class, 'done']);
$router->add('GET', '/error/{kind}', [ErrorController::class, 'show']);
$router->add('GET', '/install', [InstallController::class, 'form']);
$router->add('POST', '/install', [InstallController::class, 'submit']);

$router->add('GET', '/account', [AccountController::class, 'profile']);
$router->add('POST', '/account', [AccountController::class, 'profileSave']);
$router->add('GET', '/account/password', [AccountController::class, 'password']);
$router->add('POST', '/account/password', [AccountController::class, 'passwordSave']);
$router->add('GET', '/account/mfa', [AccountController::class, 'mfa']);
$router->add('POST', '/account/mfa', [AccountController::class, 'mfaAction']);
$router->add('GET', '/account/linked', [AccountController::class, 'linked']);
$router->add('POST', '/account/linked/{slug}/unlink', [AccountController::class, 'unlink']);
$router->add('GET', '/account/sessions', [AccountController::class, 'sessions']);
$router->add('POST', '/account/sessions/revoke-all', [AccountController::class, 'revokeAll']);
$router->add('POST', '/account/sessions/{id}/revoke', [AccountController::class, 'revoke']);

$router->add('GET', '/admin', [AdminController::class, 'dashboard']);
$router->add('GET', '/admin/clients', [AdminController::class, 'clients']);
$router->add('GET', '/admin/clients/{id}', [AdminController::class, 'clientForm']);
$router->add('GET', '/admin/providers', [AdminController::class, 'providers']);
$router->add('GET', '/admin/providers/{slug}', [AdminController::class, 'providerForm']);
$router->add('GET', '/admin/users', [AdminController::class, 'users']);
$router->add('GET', '/admin/users/{id}', [AdminController::class, 'userDetail']);
$router->add('GET', '/admin/keys', [AdminController::class, 'keys']);
$router->add('GET', '/admin/audit', [AdminController::class, 'audit']);
$router->add('GET', '/admin/audit/export', [AdminController::class, 'auditExport']);
$router->add('GET', '/admin/maintenance', [AdminController::class, 'maintenance']);
$router->add('GET', '/admin/settings', [AdminController::class, 'settings']);
$router->add('GET', '/admin/roles', [AdminController::class, 'roles']);
$router->add('GET', '/admin/stepup', [AdminController::class, 'stepupForm']);
$router->add('POST', '/admin/stepup', [AdminController::class, 'stepupSubmit']);
$router->add('POST', '/admin/role-switch', [AdminController::class, 'switchRole']);
$router->add('POST', '/admin/expire-stepup', [AdminController::class, 'expireStepup']);
$router->add('POST', '/admin/{module}/{action}', [AdminController::class, 'act']);
