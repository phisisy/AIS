# T-11 proposal — Academic Records (Team 3)

Status: **T-11a APPROVED and implemented** (owner, 2026-11-17; see `T11A_IMPLEMENTATION_REPORT.md`). T-11b (course catalog, curriculum, real summaries) is not approved. D4 was decided as **no cap**. Date: 2026-11-17. Base: `main` `07b4c83`.

## 1. What exists today (T-10, inspected)

| Item | State |
|---|---|
| Student profile | `app/Students/Models/Student.php`: `id, user_id, student_number, names, program_code, year_level, academic_status, admission_year, timestamps` |
| Storage | `runtime/students/students.json` (one exclusive lock per write); `programs.json` (read-only catalog) |
| Program catalog | `ProgramCatalogInterface` (`find`, `all`); `Program{code, name, max_year_level}`. No HTTP endpoint |
| Faculty visibility | `FacultyStudentVisibility`, deny-all until T-31 |
| History | **None.** Updates overwrite the current values; no record of earlier program, year or status |
| Cross-team read API | **None.** `StudentDirectory` from `API_CONTRACTS.md` §3 isn't built |

## 2. Ownership boundaries (to avoid duplicating Teams 4 and 5)

| Data | Owner | T-11 treatment |
|---|---|---|
| Student identity, program membership, year level, academic status, and their **history** | **Team 3** | Stored and served by T-11 |
| Program catalog | Team 3 (temporary, until Team 6) | Read endpoint in T-11 |
| Course/subject catalog and curriculum (which courses a program requires per year/term) | **Undecided:** Team 4's prompt says sections reference "approved course records" without naming an owner | **Owner decision D1** (§9). Until then T-11 doesn't build it |
| Terms, sections, schedules, enrollments | Team 4 | Not stored by Team 3. Read through a Team 4 interface when it exists |
| Teaching assignments, grades, finalization | Team 5 | Not stored by Team 3. Read through a Team 5 interface when it exists |

**Principle:** the academic record is a **view** combining Team 3's own data with read-only summaries from Teams 4 and 5. Team 3 never copies enrollment or grade rows.

## 3. Academic record structure

```json
{
  "student": {"id":1,"student_number":"2026-00001","full_name":"Reyes, Ana","program_code":"BSCS",
              "program_name":"BS Computer Science","year_level":2,"academic_status":"active","admission_year":2025},
  "history": [
    {"at":"2026-06-01T00:00:00Z","field":"year_level","from":1,"to":2,"actor_id":2},
    {"at":"2026-08-10T00:00:00Z","field":"academic_status","from":"active","to":"on_leave","actor_id":2}
  ],
  "enrollment_summary": {"available": false},
  "grades_summary": {"available": false}
}
```

- **`student`:** the current Team 3 values plus the program name from the catalog.
- **`history` (new, Team 3 owned):** an append-only list of academic changes to `program_code`, `year_level` and `academic_status`, plus profile creation. Each entry is written **in the same transaction** as the profile update, in the same `students.json` file, so they can't diverge. Fields: `at, field, from, to, actor_id`. No free text and no personal data beyond the values. Name and admission-year edits are profile corrections, not academic history, and are not included.
- **`enrollment_summary` and `grades_summary`:** placeholders `{"available": false}` until Teams 4 and 5 publish read interfaces (T-22, T-32). Later they would hold term-level counts and links, not copies of the rows. **Owner decision D3** (§9).

## 4. API contract (proposed)

| Method | Path | Guard roles | Rule |
|---|---|---|---|
| GET | `/api/students/{id}/academic-record` | registrar, admin, student | Staff: any record. Student: own record only, while holding `student` (as T-10 rule 2). Otherwise **404**, identical to missing. **No faculty access** (faculty use the T-10 summary) |
| GET | `/api/students/me/academic-record` | student | Own record; 404 if no profile |
| GET | `/api/programs` | any signed-in user | `{programs:[{code,name,max_year_level}]}`. Read-only |

- **Errors:** as T-10 (401, 403, 404; 500 generic).
- **Pagination:** history is returned oldest first and paginated (`?page=` 1–1,000,000, default 1; `?per_page=` 1–100, default 50; invalid values → 422). **D4 (owner decision): no cap.** Every valid entry is kept, history is never truncated, and an update is never refused because of the history size. The file-backed mock store reads the whole file on each request, so cost grows with total history (see README).
- **Not proposed:** program or course management endpoints (catalog writes stay seeder-only until Team 6), and any write endpoint for history. History is written only as a side effect of T-10 profile updates.

## 5. Cross-team interface: `StudentDirectory` (Team 3 provides, Teams 4 and 5 consume)

```php
namespace App\Students\Contracts;

interface StudentDirectory
{
    public function findById(int $studentId): ?StudentSummary;
    public function findByUserId(int $userId): ?StudentSummary;
    /** Whether Team 3's academic status allows a new enrollment request (owner decision D2). */
    public function isEnrollmentEligible(int $studentId): bool;
}

final class StudentSummary   // read-only value object
{
    public function __construct(
        public readonly int $id, public readonly int $userId, public readonly string $studentNumber,
        public readonly string $fullName, public readonly string $programCode, public readonly int $yearLevel,
        public readonly string $academicStatus,
    ) {}
}
```

- **In-process only, never over HTTP.** Teams 4 and 5 use it to resolve "the student for this account" and to check eligibility. It is never returned to faculty directly; T-10's B-07 summary rules still govern faculty responses.
- Provided by a `RepositoryStudentDirectory` over the T-10 repository. Consumers type-hint the interface only.

## 6. Repository changes

- `StudentRepositoryInterface::update()` (T-10) gets an optional `$actorId`. When `program_code`, `year_level` or `academic_status` actually changes, the repository appends history entries inside the same transaction.
- New `StudentRepositoryInterface::history(int $studentId): array`.
- Store shape: `{"students":[...], "history":{"<studentId>":[...]}}`. A missing `history` key means "no history yet", which is backward compatible with T-10 stores. A malformed history fails closed (500) for record reads and profile writes.
- `ProgramCatalogInterface` is unchanged.

## 7. Required tests

- **Academic record:**
  - staff see any record
  - a student sees their own only; others are 404 identical to missing
  - an account without `student` gets 404
  - faculty get 403 from the guard
  - `/me` variant
- **History:**
  - creation entry
  - each tracked field change appends exactly one entry with correct from/to/actor
  - no-op and name-only changes append nothing
  - entries are atomic with the update: a failed write leaves both unchanged; a concurrent multi-process update loses no entries
  - the cap is enforced
  - corrupt history fails closed
- **Programs:** read-only list for any signed-in user; anonymous 401; no write route.
- **StudentDirectory:**
  - found, not found, by user id
  - the summary has no private fields
  - eligibility rules (D2) for each academic status
- **Regression:** all 112 tests; guard-coverage test for the new routes; Apache.
- **Mutation checks:** ownership rule, history atomicity, eligibility.

## 8. Team 6 database dependencies

- Tables needed eventually:
  - students (exists conceptually)
  - programs
  - **student academic history** (new)
  - courses/curriculum (if D1 assigns it to Team 3)
- Field names are API names, not columns. Nothing is created in a database. MySQL stays fail-closed (T-40).
- A MySQL implementation must write the profile change and its history rows in **one transaction**.

## 9. Decisions needed before implementation

| # | Question | Recommendation |
|---|---|---|
| D1 | Who owns the course/subject catalog and curriculum? | Team 3 owns the **catalog** (code, title, units) because programs live here. Team 4 references `course_code` from it. Curriculum mapping (program × year × term → courses) is **deferred** to a separate task once Team 4's needs are known |
| D2 | Which academic statuses allow enrollment? | Only `active`. `on_leave`, `inactive`, `graduated` and `transferred` are not eligible |
| D3 | What do the enrollment and grade summaries contain once Teams 4 and 5 exist? | Term-level counts only (enrolled sections, finalized courses, units). No copied rows. Defined when T-22/T-32 land |
| D4 | History retention and cap | **Decided (owner): no cap.** Keep every entry; never truncate; never deleted with account changes (consistent with decision 3); responses paginated |
| D5 | Scope split | **T-11a** (now): history, academic-record endpoints, `/api/programs`, `StudentDirectory`. **T-11b** (later): course catalog and curriculum after D1 |
