<?php

declare(strict_types=1);

use App\Controller\AuthController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    // SSO-only: no local login form route
    $app->get('/logout', [AuthController::class, 'logout'])->setName('logout');

    // OpenID Connect (SSO)
    $app->group('/auth', static function (Group $group): void {
        $group->get('/sso', [AuthController::class, 'ssoRedirect'])->setName('auth.sso');
        $group->get('/auth/callback', [AuthController::class, 'ssoCallback'])->setName('auth.callback');
    });
};
