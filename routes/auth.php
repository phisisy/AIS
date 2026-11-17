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
    // T-04: each refusal is logged as a security event under the route's own pattern.
    $admin = static function (string $method, string $path, string $action) use ($router, $container): void {
        $router->add($method, $path, $container['guard']->roles(['admin'],
            [$container['user_controller'], $action], $method . ' ' . $path));
    };
    $admin('GET', '/api/admin/users', 'index');
    $admin('POST', '/api/admin/users', 'create');
    $admin('GET', '/api/admin/users/{id}', 'show');
    $admin('PATCH', '/api/admin/users/{id}', 'update');
    $admin('PATCH', '/api/admin/users/{id}/status', 'status');
    $admin('POST', '/api/admin/users/{id}/reset-password', 'resetPassword');
};
