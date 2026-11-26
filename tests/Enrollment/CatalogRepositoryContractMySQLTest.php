<?php
declare(strict_types=1);

use App\Enrollment\Repositories\MySQLCatalogRepository;
use Database\Tools\MigrationRunner;

require_once __DIR__ . '/CatalogRepositoryContract.php';
require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

if (MySQLTestSupport::enabled()) {
    $factory = static function (): array {
        $owner = MySQLTestSupport::connect();
        MySQLTestSupport::reset($owner);
        (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $user = $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)');
        $faculty = $owner->prepare('INSERT INTO faculty (id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $hash = password_hash('Catalog contract passphrase', PASSWORD_DEFAULT);
        for ($id = 1; $id <= 8; $id++) {
            $user->execute([$id, "catalog{$id}@example.test", 'active', $hash, 1]);
            $faculty->execute([$id, $id, "EMP{$id}", 'Faculty', "Number{$id}", 'Science', 'active',
                '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
        }
        return [new MySQLCatalogRepository(MySQLTestSupport::connectApp()), static fn () => null, $owner];
    };
    catalogRepositoryContract('MySQL', $factory);

    test('Catalog repository MySQL: assignment history is gap-free and failed writes add none', static function () use ($factory): void {
        [$repo, $cleanup, $owner] = $factory();
        try {
            $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
            $section = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
            $repo->assignInstructor($section->id(), 1, catalogTime(), 1);
            throws(\App\Core\HttpException::class, static fn () => $repo->assignInstructor($section->id(), 1, catalogTime(), 1));
            $repo->assignInstructor($section->id(), 2, catalogTime(), 1);
            $repo->unassignInstructor($section->id(), 1, catalogTime(), 1);
            same([1, 2, 3], array_map('intval', $owner->query('SELECT seq FROM section_instructor_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN)));
            same(['assigned', 'assigned', 'unassigned'], $owner->query('SELECT action FROM section_instructor_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN));
            same(3, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
            same([2], $repo->assignedFacultyIds([$section->id()])[$section->id()]);
        } finally {
            $cleanup();
        }
    });

    test('Catalog repository MySQL: faculty foreign key rejects unknown instructor', static function () use ($factory): void {
        [$repo, $cleanup, $owner] = $factory();
        try {
            $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
            $section = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
            same('Catalog repository unavailable.', throws(RuntimeException::class,
                static fn () => $repo->assignInstructor($section->id(), 999, catalogTime(), 1))->getMessage());
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
            same(0, (int) $owner->query('SELECT COUNT(*) FROM section_instructor_history')->fetchColumn());
        } finally {
            $cleanup();
        }
    });

    test('Catalog repository MySQL: approved count rejects capacity reduction', static function () use ($factory): void {
        [$repo, $cleanup, $owner] = $factory();
        try {
            $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
            $section = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
            $owner->prepare('UPDATE sections SET approved_count = ? WHERE id = ?')->execute([5, $section->id()]);
            same(5, $repo->findSection($section->id())->toArray()['approved_count']);
            throws(RuntimeException::class, static fn () => $repo->updateSection($section->id(), ['capacity' => 4], catalogTime()));
            same([10, 5, 5], array_values(array_intersect_key($repo->findSection($section->id())->toArray(),
                array_flip(['capacity', 'approved_count', 'remaining']))));
        } finally {
            $cleanup();
        }
    });
}
