<?php
declare(strict_types=1);

use App\Core\Router;

return static function (Router $router, array $container): void {
    $controller = $container['auth_controller'];
    $router->add('GET', '/api/auth/csrf', [$controller, 'csrf']);
    $router->add('POST', '/api/auth/login', [$controller, 'login']);
    $router->add('POST', '/api/auth/logout', [$controller, 'logout']);
    $router->add('GET', '/api/auth/me', [$controller, 'me']);
};
