<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Enrollment\Contracts\SectionDirectory;
use App\Enrollment\Contracts\SectionSummary;
use App\Enrollment\Models\Term;
use App\Faculty\Contracts\FacultyDirectory;
use App\Faculty\Contracts\FacultySummary;
use App\Faculty\Services\RosterFacultyVisibility;

require_once dirname(__DIR__) . '/Students/StudentSupport.php';

/** Double state: faculty user 3 -> profile 2 (active), user 8 -> profile 3; faculty 2 teaches section 10 (approved: 1) and 11 (approved: 1, 4). */
final class VisibilityWorld implements FacultyDirectory, SectionDirectory
{
    public string $status = 'active';
    public bool $fail = false;
    public array $taught = [2 => [10, 11]];
    public array $approved = [10 => [1], 11 => [1, 4]];

    public function findByUserId(int $userId): ?FacultySummary
    {
        $this->fail && throw new RuntimeException('down');
        return match ($userId) {
            3 => new FacultySummary(2, 3, 'EMP-001', 'Reyes, Ana', $this->status),
            8 => new FacultySummary(3, 8, 'EMP-008', 'Lim, Cara', 'active'),
            default => null,
        };
    }
    /** @var array<int,int> section id => term id (default 1) */
    public array $sectionTerm = [];
    /** @var array<int,bool> term id => is_current; term 1 is current by default */
    public array $termCurrent = [1 => true, 2 => false, 3 => false];
    public bool $termsFail = false;

    /** The terms() read handed to RosterFacultyVisibility. */
    public function terms(): array
    {
        $this->termsFail && throw new RuntimeException('terms down');
        $rows = [];
        foreach ($this->termCurrent as $id => $current) {
            $rows[] = Term::fromArray(['id' => $id, 'code' => 'T' . $id . '-X', 'name' => 'Term ' . $id, 'start_date' => '2026-0' . $id . '-01',
                'end_date' => '2026-0' . $id . '-28', 'is_current' => $current, 'enrollment_open' => false,
                'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z']);
        }
        return $rows;
    }
    public function findSection(int $sectionId): ?SectionSummary { return new SectionSummary($sectionId, $this->sectionTerm[$sectionId] ?? 1, 'CS1' . $sectionId, 'A'); }
    public function approvedStudentIds(int $sectionId): array { return $this->approved[$sectionId] ?? []; }
    public function isAssigned(int $facultyId, int $sectionId): bool { return in_array($sectionId, $this->taught[$facultyId] ?? [], true); }
    public function sectionIdsForFaculty(int $facultyId): array { return $this->taught[$facultyId] ?? []; }
    public function assignedFacultyIds(int $sectionId): array { return []; }
}

test('Roster visibility: approved in a currently assigned section only; every section listed; nothing cached', function (): void {
    $w = new VisibilityWorld();
    $v = new RosterFacultyVisibility($w, $w, $w->terms(...));
    $approved = static fn (int $id, string $code): array => ['section_id' => $id, 'section_code' => $code, 'enrollment_status' => 'approved'];
    same([$approved(10, 'CS110-A'), $approved(11, 'CS111-A')], $v->sharedSections(3, 1));
    same([$approved(11, 'CS111-A')], $v->sharedSections(3, 4));
    same([], $v->sharedSections(3, 2));   // not approved anywhere (pending, rejected or dropped students are not listed)
    same([], $v->sharedSections(8, 1));   // other faculty, no assignments
    same([], $v->sharedSections(99, 1));  // no faculty profile
    $w->approved[10] = [];                // dropped after the fact: visible immediately
    same([$approved(11, 'CS111-A')], $v->sharedSections(3, 1));
    $w->taught = [];                      // unassigned after the fact
    same([], $v->sharedSections(3, 1));
});

test('Roster visibility: current term only; past/future sections, no current term, ambiguity and term lookup failure all deny', function (): void {
    $w = new VisibilityWorld();
    $v = new RosterFacultyVisibility($w, $w, $w->terms(...));
    $approved = static fn (int $id, string $code): array => ['section_id' => $id, 'section_code' => $code, 'enrollment_status' => 'approved'];
    $w->sectionTerm = [10 => 1, 11 => 2];                      // 11 is in a non-current term: only 10 is listed
    same([$approved(10, 'CS110-A')], $v->sharedSections(3, 1));
    same([], $v->sharedSections(3, 4));                        // student 4 is approved only in 11 (not current term)
    $w->sectionTerm = [10 => 3, 11 => 2];                      // past/future/open-but-not-current: nothing
    same([], $v->sharedSections(3, 1));
    $w->sectionTerm = [];
    same([$approved(10, 'CS110-A'), $approved(11, 'CS111-A')], $v->sharedSections(3, 1));
    $w->termCurrent = [1 => false, 2 => true, 3 => false];     // current term switched mid-test: becomes invisible, live
    same([], $v->sharedSections(3, 1));
    $w->sectionTerm = [10 => 2];                               // a section moved into the new current term is visible
    same([$approved(10, 'CS110-A')], $v->sharedSections(3, 1));
    $w->termCurrent = [1 => false, 2 => false, 3 => false];    // no current term
    same([], $v->sharedSections(3, 1));
    $w->termCurrent = [1 => true, 2 => true, 3 => false];      // ambiguous: deny
    same([], $v->sharedSections(3, 1));
    $w->termCurrent = [1 => true, 2 => false, 3 => false];
    $w->sectionTerm = [];
    same(2, count($v->sharedSections(3, 1)));
    $w->termsFail = true;                                      // lookup failure propagates; StudentService denies
    throws(RuntimeException::class, fn () => $v->sharedSections(3, 1));
});

test('Roster visibility: inactive profile sees nothing; a lookup failure propagates so StudentService denies', function (): void {
    $w = new VisibilityWorld();
    $v = new RosterFacultyVisibility($w, $w, $w->terms(...));
    $w->status = 'inactive';
    same([], $v->sharedSections(3, 1));
    $w->status = 'active';
    $w->fail = true;
    throws(RuntimeException::class, fn () => $v->sharedSections(3, 1));
    $dir = tempDirectory();
    try {
        [$service, , $users] = studentFixture($dir, $v);
        $service->create($users->findById(2), anaInput());
        $w->fail = false;
        same(['id', 'student_number', 'full_name', 'program_code', 'year_level', 'sections'], array_keys($service->get($users->findById(3), 1)));
        $w->fail = true;
        same(404, throws(HttpException::class, fn () => $service->get($users->findById(3), 1))->status);
        $w->fail = false;
        same(404, throws(HttpException::class, fn () => $service->get($users->findById(8), 1))->status);
    } finally { removeTestDirectory($dir); }
});
