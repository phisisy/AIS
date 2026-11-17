<?php
declare(strict_types=1);

use App\Core\Router;

return static function (Router $router, array $container): void {
    // Team 3 — student records (T-10). Guard roles are the outer gate; StudentService enforces
    // ownership and faculty visibility (404 for anything the caller may not see).
    $guard = $container['guard'];
    $controller = $container['student_controller'];
    $staff = ['registrar', 'admin'];
    $router->add('GET', '/api/students', $guard->roles($staff, [$controller, 'index']));
    $router->add('POST', '/api/students', $guard->roles($staff, [$controller, 'create']));
    $router->add('GET', '/api/students/me', $guard->roles(['student'], [$controller, 'me']));
    $router->add('GET', '/api/students/{id}', $guard->roles([...$staff, 'student', 'faculty'], [$controller, 'show']));
    $router->add('PATCH', '/api/students/{id}', $guard->roles($staff, [$controller, 'update']));

    // T-11a academic records (no faculty access) and the read-only program catalog.
    $records = $container['academic_record_controller'];
    $router->add('GET', '/api/students/me/academic-record', $guard->roles(['student'], [$records, 'me']));
    $router->add('GET', '/api/students/{id}/academic-record', $guard->roles([...$staff, 'student'], [$records, 'show']));
    $router->add('GET', '/api/programs', $guard->authenticated([$records, 'programs']));
};
