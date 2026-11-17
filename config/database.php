<?php
declare(strict_types=1);

use App\Core\Environment;

// Lazy configuration only. No connection or schema operations happen here.
return [
    'host' => Environment::get('DB_HOST', '127.0.0.1'),
    'port' => Environment::get('DB_PORT', '3306'),
    'database' => Environment::get('DB_DATABASE', ''),
    'username' => Environment::get('DB_USERNAME', ''),
    'password' => Environment::get('DB_PASSWORD', ''),
];
