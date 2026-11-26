<?php
declare(strict_types=1);

// Development-only: 2 fictional terms and 4 fictional sections via the catalog repository.
// Safe to re-run: if the store already has any term or section it changes nothing and exits 0.
// Usage: php database/seeders/dev_sections.php

use App\Core\Environment;
use App\Core\JsonStore;
use App\Core\RuntimeDirectory;
use App\Enrollment\Repositories\MockCatalogRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__, 2) . '/app/autoload.php';

try {
    Environment::load(dirname(__DIR__, 2) . '/.env');
    $config = require dirname(__DIR__, 2) . '/config/app.php';
    if ($config['repository'] !== 'mock') {
        throw new RuntimeException('The development seeder only writes the mock repository.');
    }
    $runtime = RuntimeDirectory::prepare($config['runtime_path'], dirname(__DIR__, 2) . '/public');
    $catalog = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
    $existingTerms = count($catalog->terms());
    $existingSections = (int) $catalog->searchSections([], 1, 1)['total'];
    if ($existingTerms > 0 || $existingSections > 0) {
        echo "Enrollment store already has {$existingTerms} terms and {$existingSections} sections; nothing changed." . PHP_EOL;
        exit(0);
    }
    $seeding = true;
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $current = $catalog->createTerm(['code' => '2026-1S', 'name' => 'First Semester 2026 (fictional)',
        'start_date' => '2026-08-03', 'end_date' => '2026-12-18', 'is_current' => true, 'enrollment_open' => true], $now);
    $next = $catalog->createTerm(['code' => '2026-2S', 'name' => 'Second Semester 2026 (fictional)',
        'start_date' => '2027-01-11', 'end_date' => '2027-05-28', 'is_current' => false, 'enrollment_open' => false], $now);
    $sections = [
        [$current, 'CS101', 'Introduction to Computing', 3, 'A', 40, [
            ['day' => 'MON', 'start' => '08:00', 'end' => '09:30', 'room' => 'R-101'],
            ['day' => 'THU', 'start' => '08:00', 'end' => '09:30', 'room' => 'R-101']]],
        [$current, 'CS101', 'Introduction to Computing', 3, 'B', 35, [
            ['day' => 'TUE', 'start' => '13:00', 'end' => '14:30', 'room' => 'R-102'],
            ['day' => 'FRI', 'start' => '13:00', 'end' => '14:30', 'room' => 'R-102']]],
        [$current, 'IT211', 'Web Systems and Technologies', 3, 'A', 30, [
            ['day' => 'WED', 'start' => '10:00', 'end' => '12:00', 'room' => 'LAB-1'],
            ['day' => 'SAT', 'start' => '09:00', 'end' => '10:00', 'room' => 'R-201']]],
        [$next, 'CS102', 'Computer Programming', 3, 'A', 40, [
            ['day' => 'MON', 'start' => '10:00', 'end' => '11:30', 'room' => 'R-103'],
            ['day' => 'THU', 'start' => '10:00', 'end' => '11:30', 'room' => 'R-103']]],
    ];
    foreach ($sections as [$term, $course, $title, $units, $code, $capacity, $meetings]) {
        $catalog->createSection(['term_id' => $term->id(), 'course_code' => $course, 'course_title' => $title,
            'units' => $units, 'section_code' => $code, 'capacity' => $capacity, 'meetings' => $meetings], $now);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    if (!empty($seeding)) {
        fwrite(STDERR, 'The enrollment store may be partially seeded. Resetting it must be done manually and only with approval from the project owner.' . PHP_EOL);
    }
    exit(1);
}
echo 'Seeded 2 fictional terms and 4 fictional sections.' . PHP_EOL;
