<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

test('Faculty identity port: production binds only Team 5 lookup', function (): void {
    $root = dirname(__DIR__, 2);
    $implementations = [];
    foreach (['app', 'routes', 'config', 'public'] as $part) {
        if (!is_dir($root . '/' . $part)) { continue; }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $part));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
            $code = file_get_contents($file->getPathname());
            if (preg_match_all('/class\s+(\w+)[^{]*?\bimplements\b[^{]*?\bFacultyIdentityLookup\b/', $code, $m)) {
                array_push($implementations, ...$m[1]);
            }
            check(preg_match('/new\s+class[^{]*?\bFacultyIdentityLookup\b/', $code) !== 1, 'Anonymous lookup in production: ' . $file->getPathname());
            check(!str_contains($code, 'StubFacultyIdentity') && !str_contains($code, 'DeactivatingFacultyIdentity'),
                'Test double leaked into production: ' . $file->getPathname());
        }
    }
    sort($implementations);
    same(['RepositoryFacultyIdentity', 'UnavailableFacultyIdentity'], $implementations);
    $bootstrap = file_get_contents($root . '/app/bootstrap.php');
    check(str_contains($bootstrap, 'new RepositoryFacultyIdentity('), 'Production lookup binding is missing.');
    check(!str_contains($bootstrap, 'new UnavailableFacultyIdentity()'), 'Production must not bind the unavailable lookup.');
});
