<?php
declare(strict_types=1);

require_once __DIR__ . '/AssignmentServiceTest.php';

test('Section directory: summaries, assignment order, ascending ids and active identity access', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, $repository, $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $sections->create($actor, sectionInput(['section_code' => 'B', 'meetings' => [meeting('TUE')]]));
        $sections->assignInstructor($actor, 2, assignInput(10));
        $sections->assignInstructor($actor, 1, assignInput(10));
        $sections->assignInstructor($actor, 1, assignInput(11));
        $directory = new App\Enrollment\Services\RepositorySectionDirectory($repository, $stub);
        $summary = $directory->findSection(1);
        same([1, 1, 'CS101', 'A'], [$summary->id, $summary->termId, $summary->courseCode, $summary->sectionCode]);
        same(null, $directory->findSection(99));
        same([], $directory->approvedStudentIds(1));
        same([10, 11], $directory->assignedFacultyIds(1));
        same([], $directory->assignedFacultyIds(99));
        same(true, $directory->isAssigned(10, 1));
        same(false, $directory->isAssigned(10, 99));
        same([1, 2], $directory->sectionIdsForFaculty(10));
        foreach (['inactive', 'null', 'mismatch', 'throw', 'throwError'] as $mode) {
            if ($mode === 'inactive') { $stub->rows[10]['active'] = false; }
            elseif ($mode === 'null') { unset($stub->rows[10]); }
            else { $stub->$mode = true; }
            same(false, $directory->isAssigned(10, 1), $mode);
            same([], $directory->sectionIdsForFaculty(10), $mode);
            same([10, 11], $directory->assignedFacultyIds(1), $mode);
            $stub->rows[10] = ['name' => 'Instructor', 'active' => true];
            if (property_exists($stub, $mode)) { $stub->$mode = false; }
        }
        $unavailable = new App\Enrollment\Services\RepositorySectionDirectory($repository,
            new App\Enrollment\Contracts\UnavailableFacultyIdentity());
        same(false, $unavailable->isAssigned(10, 1));
        same([], $unavailable->sectionIdsForFaculty(10));
        same([10, 11], $unavailable->assignedFacultyIds(1));
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity rejects invalid ids, names and control characters', function (): void {
    foreach ([[0, 'Name'], [1, ''], [1, str_repeat('A', 151)], [1, "A\nB"], [1, "A\u{202E}B"]] as [$id, $name]) {
        throws(InvalidArgumentException::class, fn () => new App\Enrollment\Contracts\FacultyIdentity($id, $name, true));
    }
});
