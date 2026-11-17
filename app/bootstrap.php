<?php
declare(strict_types=1);

use App\Auth\Controllers\AuthController;
use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthService;
use App\Auth\Services\LoginRateLimiter;
use App\Auth\Session;
use App\Core\Environment;
use App\Core\JsonStore;
use App\Core\Response;
use App\Core\Router;
use App\Core\RuntimeDirectory;

require_once __DIR__ . '/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
// Explicit container: later approved tasks add services and route wiring here.
$container = [
    'config' => require dirname(__DIR__) . '/config/app.php',
    'database_config' => require dirname(__DIR__) . '/config/database.php',
    'router' => new Router(),
];
$container['router']->add('GET', '/', static fn (): Response => Response::success([], 'AIS API is running.'));

// Team 2 — Auth (T-01). MySQL repositories arrive with T-40; until then mysql mode fails closed.
if ($container['config']['repository'] !== 'mock') {
    throw new RuntimeException('MySQL repositories are not available yet.');
}
$runtime = RuntimeDirectory::prepare($container['config']['runtime_path'], dirname(__DIR__) . '/public');
$container['session'] = new Session(
    $runtime,
    $container['config']['https'] || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
);
$container['users'] = new MockUserRepository(new JsonStore($runtime . '/auth/users.json'));
$container['auth'] = new AuthService(
    $container['users'],
    $container['session'],
    new LoginRateLimiter(new JsonStore($runtime . '/auth/login-attempts.json'))
);
// REMOTE_ADDR only: forwarded headers are client-controlled and ignored.
$container['auth_controller'] = new AuthController(
    $container['auth'],
    $container['session'],
    static fn (): string => (string) ($_SERVER['REMOTE_ADDR'] ?? '')
);
foreach (['auth', 'students', 'enrollment', 'faculty'] as $module) {
    $register = require dirname(__DIR__) . '/routes/' . $module . '.php';
    $register($container['router'], $container);
}
return $container;
