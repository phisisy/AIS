<?php
declare(strict_types=1);

// CORE-07, OFFLINE static pin of the Apache directives the app relies on (UNVERIFIED: not run).
// This reads the .htaccess files only. It cannot prove Apache honours them: runtime behaviour
// (mod_rewrite, AllowOverride, PHP module) still needs tests/Core/apache_check.php on a trusted machine.

function apacheConfigLines(string $relative): array
{
    $text = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    check($text !== false, $relative . ' unreadable');
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
}

test('Apache: repository .htaccess routes everything through public/index.php and hides indexes/errors', function (): void {
    same([
        'Options -Indexes -MultiViews',
        '<IfModule php_module>',
        'php_flag display_errors Off',
        'php_flag display_startup_errors Off',
        '</IfModule>',
        'RewriteEngine On',
        'RewriteRule ^public/index\.php$ - [END]',
        'RewriteRule ^ public/index.php [END,QSA]',
    ], apacheConfigLines('.htaccess'));
});

test('Apache: public/.htaccess front-controller rewrite to index.php', function (): void {
    $lines = apacheConfigLines('public/.htaccess');
    same([
        'Options -Indexes -MultiViews',
        '<IfModule php_module>',
        'php_flag display_errors Off',
        'php_flag display_startup_errors Off',
        '</IfModule>',
        'DirectoryIndex index.php',
        'RewriteEngine On',
        'RewriteRule ^index\.php$ - [END]',
        'RewriteRule ^ index.php [END,QSA]',
    ], $lines);
    // The catch-all rewrite is unconditional, so no path escapes the front controller.
    foreach ($lines as $line) {
        check(!str_starts_with($line, 'RewriteCond'), 'unexpected RewriteCond');
    }
});

test('Apache: README documents the .htaccess behaviour the files implement', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    foreach (['mod_rewrite', 'AllowOverride All', 'routes requests to `index.php`', 'disables error display', 'tests/Core/apache_check.php'] as $needle) {
        check(str_contains($readme, $needle), 'README missing: ' . $needle);
    }
});

test('Apache: apache_check.php is the manual runtime companion, not an automatic test', function (): void {
    $path = dirname(__DIR__, 2) . '/tests/Core/apache_check.php';
    check(is_file($path));
    check(!str_ends_with($path, 'Test.php'));
});
