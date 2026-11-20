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
    // T-21: Team 4 owns instructor-to-section assignments (B-04).
    $router->add('POST', '/api/registrar/sections/{id}/instructors', $guard->roles($staff, [$sections, 'assignInstructor']));
    $router->add('DELETE', '/api/registrar/sections/{id}/instructors/{facultyId}', $guard->roles($staff, [$sections, 'unassignInstructor']));
};
