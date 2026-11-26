<?php
declare(strict_types=1);

use App\Core\JsonStore;
use App\Faculty\Repositories\MockFacultyRepository;

require_once __DIR__ . '/FacultyRepositoryContract.php';

facultyRepositoryContract('mock', static function (): array {
    $directory = tempDirectory();
    $path = $directory . '/faculty.json';
    $history = static function () use ($path): array {
        $store = json_decode((string) file_get_contents($path), true) ?? ['history' => []];
        return array_map(static fn (array $e): array => [$e['seq'], $e['field'], $e['from'], $e['to'], $e['actor_id']], $store['history']);
    };
    return [new MockFacultyRepository(new JsonStore($path)), static fn () => removeTestDirectory($directory), $history];
});
