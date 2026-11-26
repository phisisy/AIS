<?php
declare(strict_types=1);

// TEST-ONLY php -S router: the real bootstrap and guard plus probe routes, so RBAC is
// exercised over real HTTP sessions. Never registered by the application.
require dirname(__DIR__, 3) . '/app/autoload.php';

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;

ErrorHandler::run(static function (): Response {
    $container = require dirname(__DIR__, 3) . '/app/bootstrap.php';
    $guard = $container['guard'];
    $router = $container['router'];
    $probe = static fn (Request $request, array $params, $user): Response
        => Response::success(['user_id' => $user->id, 'params' => $params]);
    $allowlists = [
        'student' => ['student'],
        'faculty' => ['faculty'],
        'registrar' => ['registrar'],
        'admin' => ['admin'],
        'staff' => ['registrar', 'admin'],
    ];
    foreach ($allowlists as $name => $roles) {
        $router->add('GET', '/probe/' . $name, $guard->roles($roles, $probe));
    }
    $router->add('GET', '/probe/any', $guard->authenticated($probe));
    $router->add('POST', '/probe/admin-write', $guard->roles(['admin'], $probe));
    $router->add('GET', '/probe/records/{id}', $guard->roles(['student', 'registrar'], $probe));
    return $router->dispatch(Request::fromGlobals());
})->send();
