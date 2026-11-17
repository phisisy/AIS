<?php
declare(strict_types=1);

use App\Core\Router;

return static function (Router $router, array $container): void {
    // Team 4: signed-in reads, registrar/admin writes (including CSRF via RouteGuard).
    $guard = $container['guard'];
    $terms = $container['term_controller'];
    $sections = $container['section_controller'];
    $staff = ['registrar', 'admin'];
    $router->add('GET', '/api/terms', $guard->authenticated([$terms, 'index']));
    $router->add('POST', '/api/registrar/terms', $guard->roles($staff, [$terms, 'create']));
    $router->add('PATCH', '/api/registrar/terms/{id}', $guard->roles($staff, [$terms, 'update']));
    $router->add('GET', '/api/sections', $guard->authenticated([$sections, 'index']));
    $router->add('GET', '/api/sections/{id}', $guard->authenticated([$sections, 'show']));
    $router->add('POST', '/api/registrar/sections', $guard->roles($staff, [$sections, 'create']));
    $router->add('PATCH', '/api/registrar/sections/{id}', $guard->roles($staff, [$sections, 'update']));
};
