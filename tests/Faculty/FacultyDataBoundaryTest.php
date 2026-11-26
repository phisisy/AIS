<?php
declare(strict_types=1);

// FAC-04, OFFLINE (UNVERIFIED: not run). Team 5 keeps no assignment data and no Team 2 data beyond user_id.

use App\Core\JsonStore;
use App\Faculty\Models\Faculty;
use App\Faculty\Repositories\FacultyRepositoryInterface;
use App\Faculty\Repositories\MockFacultyRepository;
use App\Faculty\Repositories\MySQLFacultyRepository;

function facultyMigrationColumns(): array
{
    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/V0005__faculty.sql');
    $tables = [];
    check(preg_match_all('/CREATE TABLE (\w+) \((.*?)\)\s*ENGINE=/s', $sql, $m, PREG_SET_ORDER) === 2);
    foreach ($m as $t) {
        foreach (preg_split('/\R/', $t[2]) as $line) {
            if (preg_match('/^\s*(?:PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i', $line) === 1) {
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s+`?([A-Za-z0-9_]+)`?\s+[A-Za-z]/', $line, $c) !== 1) {
                check(false, 'unrecognized line in CREATE TABLE ' . $t[1] . ': ' . trim($line));
            }
            $tables[$t[1]][] = $c[1];
        }
    }
    return $tables;
}

test('FAC-04: V0005 tables have exactly the faculty columns, no assignment or Team 2 data', function (): void {
    $tables = facultyMigrationColumns();
    same(['faculty', 'faculty_history'], array_keys($tables));
    same(['id', 'user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status', 'created_at', 'updated_at'], $tables['faculty']);
    same(['seq', 'at', 'faculty_id', 'field', 'from_value', 'to_value', 'actor_id'], $tables['faculty_history']);
    foreach ($tables as $columns) {
        foreach ($columns as $column) {
            check(preg_match('/section|assign|instructor|course|subject|enroll|grade|email|password|role|username/i', $column) !== 1, 'forbidden column ' . $column);
        }
    }
});

test('FAC-04: no migration other than V0005 alters faculty or faculty_history', function (): void {
    $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql');
    check($files !== false && $files !== []);
    foreach ($files as $file) {
        if (basename($file) === 'V0005__faculty.sql') {
            continue;
        }
        check(preg_match('/ALTER\s+TABLE\s+`?faculty(_history)?`?\b/i', (string) file_get_contents($file)) !== 1, 'faculty ALTER in ' . basename($file));
    }
});

test('FAC-04: faculty repository contract and both implementations expose no assignment operations', function (): void {
    foreach ([FacultyRepositoryInterface::class, MockFacultyRepository::class, MySQLFacultyRepository::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            check(preg_match('/assign|section|instructor|enroll|grade/i', $method->getName()) !== 1, $class . '::' . $method->getName());
        }
    }
    same(['findById', 'findByUserId', 'findByIds', 'search', 'create', 'update'],
        array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(FacultyRepositoryInterface::class))->getMethods()));
});

test('FAC-04: mock store schema has only faculty and history with the model row keys', function (): void {
    $ref = new ReflectionClass(MockFacultyRepository::class);
    same(['seq', 'at', 'faculty_id', 'field', 'from', 'to', 'actor_id'], $ref->getReflectionConstant('HISTORY_KEYS')->getValue());
    same(['status', 'department'], $ref->getReflectionConstant('TRACKED')->getValue());
    $dir = tempDirectory();
    try {
        $path = $dir . '/faculty.json';
        file_put_contents($path, json_encode(['faculty' => [], 'history' => []]));
        $repo = new MockFacultyRepository(new JsonStore($path));
        $faculty = $repo->create(['user_id' => 7, 'employee_number' => 'EMP-9', 'first_name' => 'A', 'last_name' => 'B',
            'department' => 'D', 'status' => 'active'], '2026-01-01T00:00:00Z', 1);
        $data = json_decode((string) file_get_contents($path), true);
        same(['faculty', 'history'], array_keys($data));
        same(Faculty::FIELDS, array_keys($data['faculty'][0]));
        same($faculty->toArray(), $data['faculty'][0]);
        foreach ($data['history'] as $entry) {
            same($ref->getReflectionConstant('HISTORY_KEYS')->getValue(), array_keys($entry));
        }
    } finally {
        removeTestDirectory($dir);
    }
});

test('FAC-04: Faculty model holds only user_id from Team 2', function (): void {
    same(['id', 'user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status', 'created_at', 'updated_at'], Faculty::FIELDS);
    $team2 = array_filter(Faculty::FIELDS, static fn (string $f): bool => preg_match('/user|email|password|role|account|username/i', $f) === 1);
    same(['user_id'], array_values($team2));
});
