<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';
require __DIR__ . '/Support.php';

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);
foreach ($files as $file) {
    require $file;
}

$failed = 0;
foreach ($GLOBALS['ais_tests'] as [$name, $action]) {
    try {
        $action();
        echo "PASS $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL $name: " . $exception->getMessage() . "\n";
    }
}
$count = count($GLOBALS['ais_tests']);
echo ($count - $failed) . " passed, $failed failed, $count total\n";
exit($failed === 0 && $count > 0 ? 0 : 1);
