<?php
declare(strict_types=1);

use App\Enrollment\Repositories\MySQLCatalogRepository;

require_once dirname(__DIR__) . '/Database/MySQLConcurrencySupport.php';
require_once __DIR__ . '/CatalogRepositoryContract.php';

function mysqlRaceCatalog(): array
{
    $app = MySQLTestSupport::connectApp();
    return [$app, new MySQLCatalogRepository($app)];
}

if (mysqlConcurrencyEnabled()) {
    test('MySQL C1: concurrent current-term switches leave exactly one', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                [$app, $repo] = mysqlRaceCatalog();
                $a = $repo->createTerm(catalogTermFields('2025-A'), catalogTime())->id();
                $b = $repo->createTerm(catalogTermFields('2025-B'), catalogTime())->id();
                $app = null;
                $results = mysqlRace($owner, 'enrollment', [
                    ['op' => 'term_current', 'id' => $a], ['op' => 'term_current', 'id' => $b],
                ]);
                same(2, mysqlWinnerCount($results));
                same(1, (int) $owner->query('SELECT COUNT(*) FROM terms WHERE is_current = 1')->fetchColumn());
            } finally { $owner = null; }
        }
    });

    test('MySQL C2: separate section patches preserve every field', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                [$app, $repo] = mysqlRaceCatalog();
                $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime())->id();
                $section = $repo->createSection(catalogSectionFields($term, 'CS101', 'A'), catalogTime())->id();
                $app = null;
                $results = mysqlRace($owner, 'enrollment', [
                    ['op' => 'section_update', 'id' => $section, 'changes' => ['course_title' => 'New title']],
                    ['op' => 'section_update', 'id' => $section, 'changes' => ['units' => 4]],
                    ['op' => 'section_update', 'id' => $section, 'changes' => ['capacity' => 20]],
                ]);
                same(3, mysqlWinnerCount($results));
                $row = $owner->query('SELECT course_title, units, capacity FROM sections WHERE id = ' . $section)->fetch(PDO::FETCH_NUM);
                same(['New title', '4', '20'], array_map('strval', $row));
            } finally { $owner = null; }
        }
    });

    test('MySQL C3: overlapping faculty assignments admit one winner', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                [$app, $repo] = mysqlRaceCatalog();
                $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime())->id();
                $a = $repo->createSection(catalogSectionFields($term, 'CS101', 'A'), catalogTime())->id();
                $b = $repo->createSection(catalogSectionFields($term, 'CS102', 'A',
                    ['meetings' => [catalogMeeting('MON', '09:30', '10:30')]]), catalogTime())->id();
                $app = null;
                $results = mysqlRace($owner, 'enrollment', [
                    ['op' => 'assign', 'id' => $a, 'faculty_id' => 1],
                    ['op' => 'assign', 'id' => $b, 'faculty_id' => 1],
                ]);
                same(1, mysqlWinnerCount($results));
                same(409, array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0]['status']);
                same(1, (int) $owner->query('SELECT COUNT(*) FROM section_instructors WHERE faculty_id = 1')->fetchColumn());
                same([1], mysqlSequence($owner, 'SELECT seq FROM section_instructor_history ORDER BY seq'));
            } finally { $owner = null; }
        }
    });

    test('MySQL C4: fifth-instructor race enforces the cap', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                [$app, $repo] = mysqlRaceCatalog();
                $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime())->id();
                $section = $repo->createSection(catalogSectionFields($term, 'CS101', 'A'), catalogTime())->id();
                foreach ([1, 2, 3, 4] as $faculty) { $repo->assignInstructor($section, $faculty, catalogTime(), 1); }
                $app = null;
                $results = mysqlRace($owner, 'enrollment', [
                    ['op' => 'assign', 'id' => $section, 'faculty_id' => 5],
                    ['op' => 'assign', 'id' => $section, 'faculty_id' => 6],
                    ['op' => 'assign', 'id' => $section, 'faculty_id' => 7],
                ]);
                same(1, mysqlWinnerCount($results));
                foreach ($results as $result) {
                    if (!$result['ok']) {
                        same(409, $result['status']);
                        check(isset($result['errors']['instructors']), 'Missing cap error.');
                    }
                }
                same(5, (int) $owner->query('SELECT COUNT(*) FROM section_instructors')->fetchColumn());
                same(range(1, 5), mysqlSequence($owner, 'SELECT seq FROM section_instructor_history ORDER BY seq'));
            } finally { $owner = null; }
        }
    });

    test('MySQL C5: assignment history is gap-free and replays to current rows', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                [$app, $repo] = mysqlRaceCatalog();
                $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime())->id();
                $a = $repo->createSection(catalogSectionFields($term, 'CS101', 'A'), catalogTime())->id();
                $b = $repo->createSection(catalogSectionFields($term, 'CS102', 'A',
                    ['meetings' => [catalogMeeting('TUE')]]), catalogTime())->id();
                foreach ([1, 2, 3] as $faculty) { $repo->assignInstructor($a, $faculty, catalogTime(), 1); }
                $app = null;
                $results = mysqlRace($owner, 'enrollment', [
                    ['op' => 'unassign', 'id' => $a, 'faculty_id' => 1],
                    ['op' => 'unassign', 'id' => $a, 'faculty_id' => 2],
                    ['op' => 'assign', 'id' => $b, 'faculty_id' => 4],
                    ['op' => 'assign', 'id' => $b, 'faculty_id' => 5],
                ]);
                same(4, mysqlWinnerCount($results));
                same(range(1, 7), mysqlSequence($owner, 'SELECT seq FROM section_instructor_history ORDER BY seq'));
                same(7, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
                $history = $owner->query('SELECT seq, action, section_id, faculty_id FROM section_instructor_history ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
                $replay = [];
                foreach ($history as $event) {
                    $key = $event['section_id'] . ':' . $event['faculty_id'];
                    if ($event['action'] === 'assigned') { $replay[$key] = (int) $event['seq']; }
                    else { unset($replay[$key]); }
                }
                $current = [];
                foreach ($owner->query('SELECT section_id, faculty_id, assigned_seq FROM section_instructors')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $current[$row['section_id'] . ':' . $row['faculty_id']] = (int) $row['assigned_seq'];
                }
                ksort($replay);
                ksort($current);
                same($replay, $current);
                same([$a . ':3', $b . ':4', $b . ':5'], array_keys($current));
            } finally { $owner = null; }
        }
    });

    test('MySQL C6: meeting patch and assignment cannot create conflict', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                [$app, $repo] = mysqlRaceCatalog();
                $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime())->id();
                $a = $repo->createSection(catalogSectionFields($term, 'CS101', 'A'), catalogTime())->id();
                $b = $repo->createSection(catalogSectionFields($term, 'CS102', 'A',
                    ['meetings' => [catalogMeeting('MON', '10:00', '11:00')]]), catalogTime())->id();
                $repo->assignInstructor($a, 1, catalogTime(), 1);
                $app = null;
                $results = mysqlRace($owner, 'enrollment', [
                    ['op' => 'assign', 'id' => $b, 'faculty_id' => 1],
                    ['op' => 'section_update', 'id' => $b, 'changes' => ['meetings' => [catalogMeeting('MON', '09:30', '10:30')]]],
                ]);
                same(1, mysqlWinnerCount($results));
                same(409, array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0]['status']);
                $assigned = (int) $owner->query('SELECT COUNT(*) FROM section_instructors WHERE section_id = ' . $b . ' AND faculty_id = 1')->fetchColumn();
                $start = $owner->query('SELECT start_time FROM section_meetings WHERE section_id = ' . $b)->fetchColumn();
                check(($assigned === 1 && $start === '10:00') || ($assigned === 0 && $start === '09:30'), 'Conflicting final schedule.');
            } finally { $owner = null; }
        }
    });
}
