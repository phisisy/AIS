<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Enrollment\Repositories\CatalogRepositoryInterface;

function catalogRepositoryContract(string $backend, callable $factory): void
{
    $case = static function (string $name, callable $body) use ($backend, $factory): void {
        test("Catalog repository {$backend}: {$name}", static function () use ($factory, $body): void {
            [$repo, $cleanup] = $factory();
            try {
                $body($repo);
            } finally {
                $cleanup();
            }
        });
    };

    $case('terms, current switch and merged check', static function (CatalogRepositoryInterface $repo): void {
        same([], $repo->terms());
        same(null, $repo->findTerm(999));
        $late = $repo->createTerm(catalogTermFields('2025-A', ['start_date' => '2025-08-01', 'end_date' => '2025-12-01', 'is_current' => true]), catalogTime());
        $early = $repo->createTerm(catalogTermFields('2025-B', ['start_date' => '2025-01-01', 'end_date' => '2025-05-01']), catalogTime());
        same([2, 1], array_map(static fn ($term): int => $term->id(), $repo->terms()));
        same(true, $repo->findTerm(1)->isCurrent());
        $seen = null;
        $updated = $repo->updateTerm(2, ['is_current' => true, 'name' => 'Spring term'], '2025-01-02T03:04:05Z',
            static function (array $row) use (&$seen): void { $seen = $row; });
        same($seen, $updated->toArray());
        same(false, $repo->findTerm(1)->isCurrent());
        same(true, $repo->findTerm(2)->isCurrent());
        same('2025-01-02T03:04:05Z', $repo->findTerm(1)->toArray()['updated_at']);
        $before = $repo->findTerm(2)->toArray();
        throws(RuntimeException::class, static fn () => $repo->updateTerm(2, ['name' => 'Rejected'], catalogTime(),
            static function (): void { throw new RuntimeException('check failed'); }));
        same($before, $repo->findTerm(2)->toArray());
        same('Unsupported term change.', throws(InvalidArgumentException::class,
            static fn () => $repo->updateTerm(2, ['code' => 'OTHER'], catalogTime()))->getMessage());
        $missing = throws(HttpException::class, static fn () => $repo->updateTerm(999, ['name' => 'Absent'], catalogTime()));
        same([404, 'Term not found.'], [$missing->status, $missing->getMessage()]);
        $duplicate = throws(HttpException::class, static fn () => $repo->createTerm(catalogTermFields('2025-A'), catalogTime()));
        same([409, ['code' => 'This term code is already in use.']], [$duplicate->status, $duplicate->errors]);
    });

    $case('sections, meetings, search and wildcard escaping', static function (CatalogRepositoryInterface $repo): void {
        $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
        $secondTerm = $repo->createTerm(catalogTermFields('2025-B'), catalogTime());
        $first = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'B', ['course_title' => 'Percent% title',
            'meetings' => [catalogMeeting('WED', '10:00', '11:00'), catalogMeeting('MON', '08:00', '09:00')]]), catalogTime());
        $second = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A', ['course_title' => 'Under_score title']), catalogTime());
        $third = $repo->createSection(catalogSectionFields($secondTerm->id(), 'CS101', 'A'), catalogTime());
        same($first->toArray(), $repo->findSection($first->id())->toArray());
        same(10, $first->toArray()['remaining']);
        same(['WED', 'MON'], array_column($repo->findSection($first->id())->meetings(), 'day'));
        same([2, 1, 3], array_map(static fn ($s): int => $s->id(), $repo->searchSections([], 1, 10)['sections']));
        same(3, $repo->searchSections([], 1, 2)['total']);
        same([2, 1], array_map(static fn ($s): int => $s->id(), $repo->searchSections([], 1, 2)['sections']));
        same([3], array_map(static fn ($s): int => $s->id(), $repo->searchSections([], 2, 2)['sections']));
        same([], $repo->searchSections([], 9, 2)['sections']);
        same([1], array_map(static fn ($s): int => $s->id(), $repo->searchSections(['search' => '%'], 1, 10)['sections']));
        same([2], array_map(static fn ($s): int => $s->id(), $repo->searchSections(['search' => '_'], 1, 10)['sections']));
        same(1, $repo->searchSections(['search' => 'percent%'], 1, 10)['total']);
        same(2, $repo->searchSections(['term_id' => $term->id()], 1, 10)['total']);
        same(2, $repo->searchSections(['course_code' => 'CS101', 'term_id' => $term->id()], 1, 10)['total']);
        same(0, $repo->searchSections(['course_code' => 'OTHER'], 1, 10)['total']);
        same(null, $repo->findSection(999));
        $duplicate = throws(HttpException::class, static fn () => $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime()));
        same([409, ['section_code' => 'This section already exists for the course in this term.']], [$duplicate->status, $duplicate->errors]);
        $unknown = throws(HttpException::class, static fn () => $repo->createSection(catalogSectionFields(999, 'CS102', 'A'), catalogTime()));
        same([422, ['term_id' => 'Unknown term.']], [$unknown->status, $unknown->errors]);
    });

    $case('section patch replaces meetings and guards capacity', static function (CatalogRepositoryInterface $repo): void {
        $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
        $section = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
        $seen = null;
        $meetings = [catalogMeeting('FRI', '13:00', '14:00'), catalogMeeting('TUE', '09:00', '10:00')];
        $updated = $repo->updateSection($section->id(), ['capacity' => 20, 'meetings' => $meetings], catalogTime(),
            static function (array $row) use (&$seen): void { $seen = $row; });
        same($seen, catalogStoredSection($updated));
        same(20, $updated->toArray()['remaining']);
        same($meetings, $repo->findSection($section->id())->meetings());
        $before = $repo->findSection($section->id())->toArray();
        throws(RuntimeException::class, static fn () => $repo->updateSection($section->id(), ['capacity' => 30], catalogTime(),
            static function (): void { throw new RuntimeException('check failed'); }));
        same($before, $repo->findSection($section->id())->toArray());
        same('Unsupported section change.', throws(InvalidArgumentException::class,
            static fn () => $repo->updateSection($section->id(), ['term_id' => 2], catalogTime()))->getMessage());
        $missing = throws(HttpException::class, static fn () => $repo->updateSection(999, ['capacity' => 20], catalogTime()));
        same([404, 'Section not found.'], [$missing->status, $missing->getMessage()]);
    });

    $case('delivery modes permit empty meetings and retain actual conflicts', static function (CatalogRepositoryInterface $repo): void {
        $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
        $otherTerm = $repo->createTerm(catalogTermFields('2025-B'), catalogTime());
        $tba = $repo->createSection(catalogSectionFields($term->id(), 'CS201', 'A',
            ['delivery_mode' => 'tba', 'meetings' => []]), catalogTime());
        same([], $repo->findSection($tba->id())->meetings());
        $repo->assignInstructor($tba->id(), 1, catalogTime(), 1);
        $async = $repo->createSection(catalogSectionFields($term->id(), 'CS202', 'A',
            ['delivery_mode' => 'online_async', 'meetings' => [catalogMeeting()]]), catalogTime());
        $repo->assignInstructor($async->id(), 1, catalogTime(), 1);
        $scheduled = $repo->createSection(catalogSectionFields($term->id(), 'CS203', 'A'), catalogTime());
        $error = throws(HttpException::class, static fn () => $repo->assignInstructor($scheduled->id(), 1, catalogTime(), 1));
        same([409, ['faculty_id' => 'This instructor already teaches CS202-A (section 2) at an overlapping time.']],
            [$error->status, $error->errors]);
        $other = $repo->createSection(catalogSectionFields($otherTerm->id(), 'CS203', 'A'), catalogTime());
        $repo->assignInstructor($other->id(), 1, catalogTime(), 1);
        same([$tba->id(), $async->id(), $other->id()], $repo->sectionIdsForFaculty(1));
    });

    $case('assignments preserve order, history and errors', static function (CatalogRepositoryInterface $repo): void {
        $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
        $section = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
        same([$section->id() => []], $repo->assignedFacultyIds([$section->id()]));
        same([], $repo->sectionIdsForFaculty(1));
        same([1], $repo->assignInstructor($section->id(), 1, catalogTime(), 1)['faculty_ids']);
        same([1, 2], $repo->assignInstructor($section->id(), 2, catalogTime(), 1)['faculty_ids']);
        same([1, 2], $repo->assignedFacultyIds([$section->id()])[$section->id()]);
        same([$section->id()], $repo->sectionIdsForFaculty(2));
        $duplicate = throws(HttpException::class, static fn () => $repo->assignInstructor($section->id(), 1, catalogTime(), 1));
        same([409, ['faculty_id' => 'This instructor is already assigned to this section.']], [$duplicate->status, $duplicate->errors]);
        same([2], $repo->unassignInstructor($section->id(), 1, catalogTime(), 1)['faculty_ids']);
        same([2, 1], $repo->assignInstructor($section->id(), 1, catalogTime(), 1)['faculty_ids']);
        same([2, 1], $repo->assignedFacultyIds([$section->id()])[$section->id()]);
        $missing = throws(HttpException::class, static fn () => $repo->unassignInstructor($section->id(), 3, catalogTime(), 1));
        same([404, 'Instructor assignment not found.'], [$missing->status, $missing->getMessage()]);
        $missing = throws(HttpException::class, static fn () => $repo->assignInstructor(999, 1, catalogTime(), 1));
        same([404, 'Section not found.'], [$missing->status, $missing->getMessage()]);
    });

    $case('five-instructor cap and schedule conflict', static function (CatalogRepositoryInterface $repo): void {
        $term = $repo->createTerm(catalogTermFields('2025-A'), catalogTime());
        $first = $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
        $second = $repo->createSection(catalogSectionFields($term->id(), 'CS102', 'A',
            ['meetings' => [catalogMeeting('MON', '09:30', '10:30')]]), catalogTime());
        $backToBack = $repo->createSection(catalogSectionFields($term->id(), 'CS103', 'A',
            ['meetings' => [catalogMeeting('MON', '10:00', '11:00')]]), catalogTime());
        foreach ([1, 2, 3, 4, 5] as $facultyId) {
            $repo->assignInstructor($first->id(), $facultyId, catalogTime(), 1);
        }
        $cap = throws(HttpException::class, static fn () => $repo->assignInstructor($first->id(), 6, catalogTime(), 1));
        same([409, ['instructors' => 'A section can have at most 5 instructors.']], [$cap->status, $cap->errors]);
        $conflict = throws(HttpException::class, static fn () => $repo->assignInstructor($second->id(), 1, catalogTime(), 1));
        same([409, ['faculty_id' => 'This instructor already teaches CS101-A (section 1) at an overlapping time.']],
            [$conflict->status, $conflict->errors]);
        $repo->assignInstructor($backToBack->id(), 1, catalogTime(), 1);
        same([1, 3], $repo->sectionIdsForFaculty(1));
        $patchConflict = throws(HttpException::class, static fn () => $repo->updateSection($backToBack->id(),
            ['meetings' => [catalogMeeting('MON', '09:00', '11:00')]], catalogTime()));
        same([409, ['meetings' => 'Instructor 1 already teaches CS101-A (section 1) at an overlapping time.']],
            [$patchConflict->status, $patchConflict->errors]);
        same('10:00', $repo->findSection($backToBack->id())->meetings()[0]['start']);
    });
}

function catalogTime(): string { return '2025-01-01T03:04:05Z'; }

function catalogTermFields(string $code, array $changes = []): array
{
    return array_replace(['code' => $code, 'name' => 'Term', 'start_date' => '2025-01-01',
        'end_date' => '2025-05-01', 'is_current' => false, 'enrollment_open' => false], $changes);
}

function catalogMeeting(string $day = 'MON', string $start = '09:00', string $end = '10:00'): array
{
    return ['day' => $day, 'start' => $start, 'end' => $end, 'room' => 'R101'];
}

function catalogSectionFields(int $termId, string $courseCode, string $sectionCode, array $changes = []): array
{
    return array_replace(['term_id' => $termId, 'course_code' => $courseCode, 'course_title' => 'Course',
        'units' => 3, 'section_code' => $sectionCode, 'capacity' => 10,
        'delivery_mode' => 'scheduled', 'meetings' => [catalogMeeting()]], $changes);
}

function catalogStoredSection(\App\Enrollment\Models\Section $section): array
{
    $row = $section->toArray();
    unset($row['remaining']);
    return $row;
}
