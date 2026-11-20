<?php
declare(strict_types=1);

// CLI worker for Team 4 concurrency tests. Acts as the registrar (user 2).
// Usage: php enrollment_worker.php <runtime-dir> <start-flag> <ready-flag> <op> <json-input> [id]
require dirname(__DIR__, 3) . '/app/autoload.php';
require __DIR__ . '/StubFacultyIdentity.php';

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\SectionService;
use App\Enrollment\Services\TermService;

if (PHP_SAPI !== 'cli' || $argc < 6 || !in_array($argv[4], ['createTerm', 'updateTerm', 'createSection', 'updateSection', 'bump', 'assign', 'unassign'], true)) {
    exit(2);
}
[$dir, $start, $ready, $op, $json] = array_slice($argv, 1, 5);
$users = new MockUserRepository(new JsonStore($dir . '/auth/users.json'));
$repository = new MockCatalogRepository(new JsonStore($dir . '/enrollment/enrollment.json'));
$terms = new TermService($repository, new AuthorizationService());
$sections = new SectionService($repository, new AuthorizationService(),
    new StubFacultyIdentity(array_fill_keys(range(10, 99), ['name' => 'Instructor', 'active' => true])));
$actor = $users->findById(2);
// Meetings must reach the service as stdClass, exactly as Request delivers them.
$input = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
foreach ($input['meetings'] ?? [] as $i => $meeting) {
    $input['meetings'][$i] = (object) $meeting;
}
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 10;
while (!is_file($start)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Start barrier timed out\n");
        exit(1);
    }
    usleep(1000);
}
try {
    match ($op) {
        'createTerm' => $terms->create($actor, $input),
        'updateTerm' => $terms->update($actor, (int) $argv[6], $input),
        'createSection' => $sections->create($actor, $input),
        'updateSection' => $sections->update($actor, (int) $argv[6], $input),
        'assign' => $sections->assignInstructor($actor, (int) $argv[6], $input),
        'unassign' => $sections->unassignInstructor($actor, (int) $argv[6], (int) $input['faculty_id']),
        // Simulates T-22: raw approved_count increment guarded by capacity, in one store transaction.
        'bump' => (new JsonStore($dir . '/enrollment/enrollment.json'))->transaction(static function (array &$data) use ($argv): void {
            foreach ($data['sections'] as $i => $row) {
                if ($row['id'] === (int) $argv[6]) {
                    if ($row['approved_count'] >= $row['capacity']) {
                        throw new HttpException(409, 'Full.');
                    }
                    $data['sections'][$i]['approved_count']++;
                    return;
                }
            }
            throw new HttpException(404, 'Missing.');
        }),
    };
    echo json_encode(['ok' => true, 'status' => 200]);
} catch (HttpException $exception) {
    echo json_encode(['ok' => false, 'status' => $exception->status, 'errors' => $exception->errors]);
}
