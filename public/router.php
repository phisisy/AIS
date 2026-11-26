<?php
declare(strict_types=1);

// Never return false: php -S may have the repository root as its document root.
// Every request goes through the API, so private files cannot be served directly.
require __DIR__ . '/index.php';
