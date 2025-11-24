<?php

declare(strict_types=1);

use App\Controller\AuthController;
use Slim\App;

return static function (App $app): void {
    // SSO-only: no local login form route
    $app->get('/logout', [AuthController::class, 'logout'])->setName('logout');

    // OpenID Connect (SSO)
    $app->get('/auth/sso', [AuthController::class, 'ssoRedirect'])->setName('auth.sso');
    $app->get('/auth/callback', [AuthController::class, 'ssoCallback'])->setName('auth.callback');
};
