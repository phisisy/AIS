<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

test('Faculty identity port: production has only the unavailable lookup and explicit binding', function (): void {
    $root = dirname(__DIR__, 2);
    $implementations = [];
    foreach (['app', 'routes', 'config', 'public'] as $part) {
        if (!is_dir($root . '/' . $part)) { continue; }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $part));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
            $code = file_get_contents($file->getPathname());
            if (preg_match('/class\s+(\w+)\s+implements\s+FacultyIdentityLookup\b/', $code, $m)) {
                $implementations[] = $m[1];
            }
            check(!str_contains($code, 'StubFacultyIdentity'), 'Test stub leaked into production: ' . $file->getPathname());
        }
    }
    same(['UnavailableFacultyIdentity'], $implementations);
    check(str_contains(file_get_contents($root . '/app/bootstrap.php'), 'new UnavailableFacultyIdentity()'), 'Production lookup binding is missing.');
});
