<?php
declare(strict_types=1);

use App\Core\Router;

return static function (Router $router, array $container): void {
    // Team 5 (T-30): staff manage profiles; faculty read only their own profile and classes (ids come from the session).
    $guard = $container['guard'];
    $faculty = $container['faculty_controller'];
    $staff = ['registrar', 'admin'];
    $router->add('GET', '/api/registrar/faculty', $guard->roles($staff, [$faculty, 'index']));
    $router->add('POST', '/api/registrar/faculty', $guard->roles($staff, [$faculty, 'create']));
    $router->add('GET', '/api/registrar/faculty/{id}', $guard->roles($staff, [$faculty, 'show']));
    $router->add('PATCH', '/api/registrar/faculty/{id}', $guard->roles($staff, [$faculty, 'update']));
    $router->add('GET', '/api/faculty/me', $guard->roles(['faculty'], [$faculty, 'me']));
    $router->add('GET', '/api/faculty/classes', $guard->roles(['faculty'], [$faculty, 'classes']));
    $router->add('GET', '/api/faculty/classes/{id}/students', $guard->roles(['faculty'], [$container['class_roster_controller'], 'students']));
    // Team 5 (T-32): grades. Fail closed (409 grade_scale) on entry and corrections until the official scale is configured (B-05).
    $grades = $container['grade_controller'];
    $router->add('GET', '/api/faculty/classes/{id}/grades', $guard->roles(['faculty'], [$grades, 'classGrades']));
    $router->add('PUT', '/api/faculty/classes/{id}/grades/{studentId}', $guard->roles(['faculty'], [$grades, 'enter']));
    $router->add('POST', '/api/faculty/classes/{id}/grades/submit', $guard->roles(['faculty'], [$grades, 'submit']));
    $router->add('GET', '/api/registrar/sections/{id}/grades', $guard->roles($staff, [$grades, 'sectionGrades']));
    $router->add('POST', '/api/registrar/sections/{id}/grades/finalize', $guard->roles($staff, [$grades, 'finalize']));
    $router->add('POST', '/api/registrar/sections/{id}/grades/return', $guard->roles($staff, [$grades, 'returnToDraft']));
    $router->add('POST', '/api/registrar/grades/{id}/corrections', $guard->roles($staff, [$grades, 'correct']));
    $router->add('GET', '/api/grades/me', $guard->roles(['student'], [$grades, 'mine']));
};
