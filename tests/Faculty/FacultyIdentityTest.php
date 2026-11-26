<?php
declare(strict_types=1);

use App\Faculty\Models\Faculty;
use App\Faculty\Repositories\FacultyRepositoryInterface;

require_once __DIR__ . '/FacultySupport.php';
require_once __DIR__ . '/fixtures/DeactivatingFacultyIdentity.php';

/** Counts faculty-store reads. */
final class CountingFacultyRepository implements FacultyRepositoryInterface
{
    public int $reads = 0;

    public function __construct(private readonly FacultyRepositoryInterface $inner)
    {
    }

    public function findById(int $id): ?Faculty { $this->reads++; return $this->inner->findById($id); }
    public function findByUserId(int $userId): ?Faculty { $this->reads++; return $this->inner->findByUserId($userId); }
    public function findByIds(array $ids): array { $this->reads++; return $this->inner->findByIds($ids); }
    public function search(array $filters, int $page, int $perPage): array { $this->reads++; return $this->inner->search($filters, $page, $perPage); }
    public function create(array $fields, string $now, int $actorId): Faculty { return $this->inner->create($fields, $now, $actorId); }
    public function update(int $id, array $changes, string $now, int $actorId): Faculty { return $this->inner->update($id, $changes, $now, $actorId); }
}

function facultySection(array $f): void
{
    $registrar = $f['users']->findById(2);
    $f['terms']->create($registrar, termInput());
    $f['sections']->create($registrar, sectionInput());
}

test('Faculty identity: active only when profile, account and faculty role all hold; name always returned', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f); // faculty 1 = user 6, 2 = user 3, 3 = user 7
        $registrar = $f['users']->findById(2);
        $identity = $f['identity']->find(1);
        same([1, 'Cruz, Ben', true], [$identity->facultyId, $identity->displayName, $identity->active]);
        $f['service']->update($registrar, 1, ['status' => 'inactive']);
        same([false, 'Cruz, Ben'], [$f['identity']->find(1)->active, $f['identity']->find(1)->displayName]);
        changeFacultyAccount($dir, 3, static fn (array $row): array => array_replace($row, ['status' => 'inactive']));
        same([false, 'Reyes, Ana'], [$f['identity']->find(2)->active, $f['identity']->find(2)->displayName]);
        changeFacultyAccount($dir, 7, static fn (array $row): array => array_replace($row, ['roles' => ['student']]));
        same(false, $f['identity']->find(3)->active);
        foreach ([99, 0, -1] as $id) {
            same(null, $f['identity']->find($id));
        }
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity: an inactive linked account reads as inactive, not an error', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        changeFacultyAccount($dir, 6, static fn (array $row): array => array_replace($row, ['status' => 'inactive']));
        same(false, $f['identity']->find(1)->active);
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity: no cache, one instance reflects account changes between calls', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        same(true, $f['identity']->find(2)->active);
        changeFacultyAccount($dir, 3, static fn (array $row): array => array_replace($row, ['status' => 'inactive']));
        same(false, $f['identity']->find(2)->active);
        changeFacultyAccount($dir, 3, static fn (array $row): array => array_replace($row, ['status' => 'active']));
        same(true, $f['identity']->find(2)->active);
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity: findMany keys by id, ignores bad entries, one faculty-store read', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        $counting = new CountingFacultyRepository($f['repo']);
        $lookup = new App\Faculty\Services\RepositoryFacultyIdentity($counting, $f['users']);
        same([], $lookup->findMany([]));
        same(0, $counting->reads);
        $found = $lookup->findMany([2, 1, 2, '1', 0, -1, 99, 3]);
        same([1, 2, 3], array_keys($found));
        same(1, $counting->reads);
        same([true, true, true], array_map(static fn ($i): bool => $i->active, array_values($found)));
        $f['service']->update($f['users']->findById(2), 3, ['status' => 'inactive']);
        same(false, $lookup->findMany([3])[3]->active);
    } finally { removeTestDirectory($dir); }
});

test('Faculty directory: summary fields, unknown is null, inactive profile still mapped', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        $summary = $f['directory']->findByUserId(3);
        same([2, 3, 'EMP-001', 'Reyes, Ana', 'active'], [$summary->id, $summary->userId, $summary->employeeNumber, $summary->displayName, $summary->status]);
        same(null, $f['directory']->findByUserId(8));
        same(null, $f['directory']->findByUserId(0));
        $f['service']->update($f['users']->findById(2), 2, ['status' => 'inactive']);
        same('inactive', $f['directory']->findByUserId(3)->status);
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity: a corrupt faculty store throws, T-21 callers refuse and DELETE still works', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        facultySection($f);
        $registrar = $f['users']->findById(2);
        $f['sections']->assignInstructor($registrar, 1, ['faculty_id' => 2]);
        $enrollment = file_get_contents($dir . '/enrollment/enrollment.json');
        file_put_contents(facultyStorePath($dir), '{"faculty":"x","history":[]}');
        throws(RuntimeException::class, fn () => $f['identity']->find(2));
        throws(RuntimeException::class, fn () => $f['identity']->findMany([2]));
        same([422, ['faculty_id' => 'Unknown or inactive faculty member.']],
            (function () use ($f, $registrar): array { $e = httpFailure(fn () => $f['sections']->assignInstructor($registrar, 1, ['faculty_id' => 1])); return [$e->status, $e->errors]; })());
        same($enrollment, file_get_contents($dir . '/enrollment/enrollment.json'));
        same(null, $f['sections']->get(1)['instructors'][0]['name']);
        same(false, $f['section_directory']->isAssigned(2, 1));
        same([], $f['section_directory']->sectionIdsForFaculty(2));
        same([], $f['sections']->unassignInstructor($registrar, 1, 2)['instructors']);
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity: the A4 window is accepted but the late assignment grants no access', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        facultySection($f);
        $registrar = $f['users']->findById(2);
        $racing = new DeactivatingFacultyIdentity($f['identity'], static fn () => $f['repo']->update(2, ['status' => 'inactive'], '2026-11-20T00:00:00Z', 2));
        $sections = new App\Enrollment\Services\SectionService($f['catalog'], new App\Auth\Services\AuthorizationService(), $racing);
        $row = $sections->assignInstructor($registrar, 1, ['faculty_id' => 2]);
        same([['faculty_id' => 2, 'name' => 'Reyes, Ana']], $row['instructors']);
        same(false, $f['section_directory']->isAssigned(2, 1));
        same([], $f['section_directory']->sectionIdsForFaculty(2));
        same(422, httpFailure(fn () => $f['sections']->assignInstructor($registrar, 1, ['faculty_id' => 2]))->status);
        same('Reyes, Ana', $f['sections']->get(1)['instructors'][0]['name']);
    } finally { removeTestDirectory($dir); }
});

test('Faculty identity: reactivation restores access only while account and role hold; only staff may reactivate', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        facultySection($f);
        $registrar = $f['users']->findById(2);
        $f['sections']->assignInstructor($registrar, 1, ['faculty_id' => 2]);
        $f['service']->update($registrar, 2, ['status' => 'inactive']);
        changeFacultyAccount($dir, 3, static fn (array $row): array => array_replace($row, ['status' => 'inactive']));
        $history = count(json_decode(facultyStoreBytes($dir), true)['history']);
        $f['service']->update($registrar, 2, ['status' => 'active']);
        same($history + 1, count(json_decode(facultyStoreBytes($dir), true)['history']));
        same([false, false, []], [$f['identity']->find(2)->active, $f['section_directory']->isAssigned(2, 1), $f['section_directory']->sectionIdsForFaculty(2)]);
        changeFacultyAccount($dir, 3, static fn (array $row): array => array_replace($row, ['status' => 'active', 'roles' => ['student']]));
        same(false, $f['section_directory']->isAssigned(2, 1));
        changeFacultyAccount($dir, 3, static fn (array $row): array => array_replace($row, ['roles' => ['faculty']]));
        same([true, true, [1]], [$f['identity']->find(2)->active, $f['section_directory']->isAssigned(2, 1), $f['section_directory']->sectionIdsForFaculty(2)]);
        // reactivation by a student or faculty is refused and writes nothing
        $f['service']->update($registrar, 2, ['status' => 'inactive']);
        $bytes = facultyStoreBytes($dir);
        foreach ([4, 3] as $userId) {
            same(403, httpFailure(fn () => $f['service']->update($f['users']->findById($userId), 2, ['status' => 'active']))->status);
        }
        same($bytes, facultyStoreBytes($dir));
    } finally { removeTestDirectory($dir); }
});
