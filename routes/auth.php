<?php
declare(strict_types=1);

use App\Core\Router;

return static function (Router $router, array $container): void {
    $controller = $container['auth_controller'];
    $router->add('GET', '/api/auth/csrf', [$controller, 'csrf']);
    $router->add('POST', '/api/auth/login', [$controller, 'login']);
    $router->add('POST', '/api/auth/logout', [$controller, 'logout']);
    $router->add('GET', '/api/auth/me', [$controller, 'me']);

    // T-03 user administration: admin only; the guard adds CSRF on every non-GET.
    $admin = static fn (string $method) => $container['guard']->roles(['admin'], [$container['user_controller'], $method]);
    $router->add('GET', '/api/admin/users', $admin('index'));
    $router->add('POST', '/api/admin/users', $admin('create'));
    $router->add('GET', '/api/admin/users/{id}', $admin('show'));
    $router->add('PATCH', '/api/admin/users/{id}', $admin('update'));
    $router->add('PATCH', '/api/admin/users/{id}/status', $admin('status'));
    $router->add('POST', '/api/admin/users/{id}/reset-password', $admin('resetPassword'));
};
