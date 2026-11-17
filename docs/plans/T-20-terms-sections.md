# T-20 — Terms & Sections (Team 4) — Implementation Plan

- **Status:** T-20 APPROVED by owner 2026-11-17. This plan is the spec for implementation.
- **Branch:** `task/T-20-terms-sections` (already checked out).
- **Pattern to copy:** Team 3 Students module (`app/Students/**`, `routes/students.php`, `tests/Students/*`).
- **Out of scope:** T-21 (SectionDirectory, faculty identity, instructor assignment, the `instructors` field) and T-22 (enrollments, schedules). No database schema. MySQL mode stays fail-closed (bootstrap already throws before any module wiring).

## 1. Goal and approved scope

Registrar and admin can manage academic terms and class sections. Any signed-in user can list them.
Owner scope items and where they are covered:

| # | Scope item | Section |
|---|---|---|
| 1 | Academic term management | §3.1 |
| 2 | Section management | §3.2 |
| 3 | Term and section validation | §3.1, §3.2 |
| 4 | Duplicate prevention | term code (409), (term_id, course_code, section_code) (409) |
| 5 | RBAC | §3.3 |
| 6 | Consistent API responses | §3.4 |
| 7 | Repository abstraction | §5 |
| 8 | Safe concurrent updates | §6 |
| 9 | Automated tests | §7 |

## 2. Decisions (where this plan differs from or settles the lead's guidance)

| ID | Decision | Why |
|---|---|---|
| P1 | **One store file** `$runtime/enrollment/enrollment.json` with keys `terms` and `sections`. | The section-create term check and the is_current switch need the same lock. T-22 must also update `approved_count` atomically with the enrollment row, so T-22 adds `enrollments` to this file. T-21 adds `assignments` and `assignment_history` here too. |
| P2 | **One repository interface** (`CatalogRepositoryInterface`) and one mock (`MockCatalogRepository`), not one per aggregate. | One store means one transaction boundary. Two classes on the same file would invite nested-lock mistakes (JsonStore forbids nesting). T-40's MySQL version can implement the same interface with one PDO transaction. |
| P3 | Terms **may overlap**. | Summer and special terms overlap regular ones. No rule needs non-overlap. |
| P4 | Term `code` is **immutable** after create. PATCH with `code` gets 422 `Unknown field.`, like `student_number`. | It is the natural key that humans and other modules display. Because nothing can be deleted, a typo can't be fixed: see open question Q1. |
| P5 | Section `term_id`, `course_code` and `section_code` are **immutable**. PATCH may change only `course_title`, `units`, `capacity` and `meetings`. | Enrollments (T-22) reference `section_id`. Changing the course or term under an existing enrollment would silently change what a student enrolled in. This also means uniqueness is checked only on create. See Q4. |
| P6 | Unknown `term_id` on section create gives **422** `{term_id: "Unknown term."}`, not 404. | 404 is for the URL resource. A body field that refers to a missing row is a validation error, the same as Students' `program_code` → 422 `Unknown program.`. It is checked inside the store lock. |
| P7 | A section's own overlapping meetings give **422** (`meetings`), not 409. | It is a defect in the submitted payload, not a conflict with other stored rows. The 409 "schedule clash" in ARCHITECTURE §5 is T-22's student-level clash. |
| P8 | Back-to-back meetings (`09:00–10:00`, then `10:00–11:00`) are **allowed**. Overlap means `a.start < b.end && b.start < a.end` on the same day. | This is standard interval semantics. |
| P9 | Sections need **1–14 meetings**. | A schedule view and the T-22 clash check need at least one slot. 14 = two per day. See Q3 for TBA or online sections. |
| P10 | Read endpoints don't re-check roles in the service (any active signed-in user, already enforced by the guard). Write services call `requireRole(STAFF)`. | Same as `/api/programs` (T-11a). |
| P11 | `GET /api/terms` is **not paginated**. | Terms are few (a handful per year). See Q6. |
| P12 | Without `term_id`, `GET /api/sections` lists all terms. It does not default to the current term. | Simpler and predictable. The frontend passes `term_id`. |
| P13 | There can be zero current terms. Setting `is_current:true` clears every other term in the same write. Setting `false` on the current term leaves none. | "At most one current" is the invariant. T-22 must handle "no current term". |
| P14 | Instructor assignment (B-04) goes into **T-21, expanded**, not a new task. | T-21 already depends on T-20 and B-04, and touches the same Team 4 files. See §9. |

## 3. API contract

All responses use the existing envelope (`Response::success` / `HttpException` → `{success,message,errors}`).
Path `{id}` is a positive integer (Router). Bodies are JSON objects only (415 / 400 / 413 / 422 from `Request`).

### 3.1 Terms

**Term (response shape, exact key order):**
`{id, code, name, start_date, end_date, is_current, enrollment_open, created_at, updated_at}`

| Method | Path | Guard | CSRF | Success | Errors |
|---|---|---|---|---|---|
| GET | `/api/terms` | authenticated | — | 200 `data.terms: Term[]`, sorted by `start_date` then `id` | 401 |
| POST | `/api/registrar/terms` | registrar, admin | ✔ | 201 `"Term created."` `data.term` | 401, 403, 409, 415, 422 |
| PATCH | `/api/registrar/terms/{id}` | registrar, admin | ✔ | 200 `"Term updated."` `data.term` | 401, 403, 404 `"Term not found."`, 415, 422 |

- **Create allowlist:** `code`, `name`, `start_date`, `end_date`, `is_current`, `enrollment_open`. The first four are required. Both booleans default to `false`.
- **Update allowlist:** `name`, `start_date`, `end_date`, `is_current`, `enrollment_open`. An empty body gives 422 `{body: "Provide at least one field to change."}`. `code`, `id` or any other field gives 422 `Unknown field.` (controller allowlist).
- **Order for PATCH:** controller allowlist (422) → role (403) → empty body (422) → find (404) → field validation (422) → in-lock merged-row check (422). This is the same order as Students.

**Validation rules:**

| Field | Rule | Error (422 unless noted) |
|---|---|---|
| `code` | string, trimmed, uppercased, `\A[A-Z0-9][A-Z0-9-]{1,19}\z` (e.g. `2026-1S`) | `Use 2 to 20 characters: A-Z, 0-9 and hyphen.` Duplicate after normalization gives **409** `{code: "This term code is already in use."}`, checked in the lock |
| `name` | string, trimmed, 1–100 chars, valid UTF-8, no `\p{Cc}\p{Cf}` | `Use 1 to 100 characters without control characters.` |
| `start_date`, `end_date` | string `\A\d{4}-\d{2}-\d{2}\z` and `checkdate()` | `Use a real date in YYYY-MM-DD format.` |
| dates together | `start_date < end_date` on the **merged** row (stored + changes), checked in the lock | `{end_date: "The end date must be after the start date."}` |
| `is_current`, `enrollment_open` | strict `bool` (`1`, `"true"` rejected) | `Use true or false.` |

`enrollment_open` is the term-level period flag recommended in B-08. T-22 reads it. It is independent of `is_current`.

### 3.2 Sections

**Section (response shape, exact key order). There is no `instructors` key in T-20, because T-21 adds it:**
`{id, term_id, course_code, course_title, units, section_code, capacity, approved_count, remaining, meetings:[{day, start, end, room}], created_at, updated_at}`
`remaining = capacity − approved_count` is computed in `Section::toArray()` and never stored.

| Method | Path | Guard | CSRF | Success | Errors |
|---|---|---|---|---|---|
| GET | `/api/sections` | authenticated | — | 200 `data.sections: Section[]`, `data.pagination{page, per_page, total}` | 401, 422 |
| GET | `/api/sections/{id}` | authenticated | — | 200 `data.section` | 401, 404 `"Section not found."` |
| POST | `/api/registrar/sections` | registrar, admin | ✔ | 201 `"Section created."` `data.section` | 401, 403, 409, 415, 422 |
| PATCH | `/api/registrar/sections/{id}` | registrar, admin | ✔ | 200 `"Section updated."` `data.section` | 401, 403, 404, 409, 415, 422 |

**List query (validated like T-11a; arrays or out-of-range values give 422, and unrecognized params are ignored like in Students):**
- `term_id`: positive int (`filter_var`, min 1). Exact match.
- `course`: normalized like `course_code` and must match its pattern. Exact `course_code` match.
- `search`: string ≤ 100 chars, UTF-8. Case-insensitive substring of `course_code`, `course_title` or `section_code`.
- `page`: 1–1,000,000, default 1. `per_page`: 1–100, default 20.
- Order: `term_id`, `course_code`, `section_code`, `id`.

**Create allowlist (all required):** `term_id`, `course_code`, `course_title`, `units`, `section_code`, `capacity`, `meetings`.
**Update allowlist:** `course_title`, `units`, `capacity`, `meetings`. An empty body gives 422. `term_id`, `course_code`, `section_code`, `approved_count`, `remaining`, `id` or any other field gives 422 `Unknown field.`
`approved_count` is never client-writable. It is 0 on create. Only T-22, inside the store transaction, will change it.

| Field | Rule | Error |
|---|---|---|
| `term_id` | int ≥ 1. The term must exist (checked in the lock) | 422 `Unknown term.` |
| `course_code` | string, trimmed, uppercased, `\A[A-Z0-9][A-Z0-9-]{1,19}\z` (e.g. `CS101`, `IT-211`). **Format only, see D1 note** | 422 |
| `course_title` | like term `name`, 1–150 chars | 422 |
| `units` | int 1–12 | 422 `Use a whole number from 1 to 12.` |
| `section_code` | string, trimmed, uppercased, `\A[A-Z0-9][A-Z0-9-]{0,9}\z` | 422 |
| `capacity` | int 1–1000. On PATCH it must be ≥ the stored `approved_count`, checked in the lock | 422 for range. **409** `{capacity: "Capacity cannot be below the approved count (N)."}` |
| (`term_id`, `course_code`, `section_code`) | unique, checked in the lock on create | **409** `{section_code: "This section already exists for the course in this term."}` |
| `meetings` | JSON array (PHP list), 1–14 items | 422 `{meetings: "Provide 1 to 14 meetings."}` |
| `meetings[i]` | JSON object (arrives as `stdClass` from `Request`, so accept `stdClass` and convert with `get_object_vars`). Keys exactly `day`, `start`, `end`, `room`. Extra or missing key gives 422 at `meetings.i` | 422 `meetings.i` / `meetings.i.<field>` |
| `day` | exactly one of `MON TUE WED THU FRI SAT SUN` (case-sensitive) | 422 |
| `start`, `end` | `\A([01]\d|2[0-3]):[0-5]\d\z`, and `start < end` (string compare is valid when zero-padded) | 422 |
| `room` | string, trimmed, 1–30 chars, no control or format characters | 422 |
| meetings together | no two on the same day overlap (P8) | 422 `{meetings: "Meetings i and j overlap."}` |

The service stores meetings sorted by day order (MON..SUN), then `start`. PATCH `meetings` replaces the whole list.

**D1 dependency (T-11b):** `course_code`, `course_title` and `units` are stored on the section and checked for format only, because course catalog ownership is undecided. When D1 is decided and a catalog exists, section create must validate `course_code` against it through a provider interface. `course_title` and `units` may then become derived, and existing rows will need reconciling. Record this in the T-11b and D1 follow-ups.

### 3.3 Role matrix (HTTP)

| Caller | GET terms | POST term | PATCH term | GET sections | GET section | POST section | PATCH section |
|---|---|---|---|---|---|---|---|
| Anonymous | 401 | 401 | 401 | 401 | 401 | 401 | 401 |
| Student | 200 | 403 | 403 | 200 | 200 | 403 | 403 |
| Faculty | 200 | 403 | 403 | 200 | 200 | 403 | 403 |
| Registrar | 200 | 201 | 200 | 200 | 200 | 201 | 200 |
| Admin | 200 | 201 | 200 | 200 | 200 | 201 | 200 |
| Staff, missing/invalid CSRF | — | 403 | 403 | — | — | 403 | 403 |

The guard order is auth → role → CSRF (T-02). Services repeat `requireRole(['registrar','admin'])` on writes (defense in depth).
Client-sent `role`, `user_id`, `approved_count`, etc. are rejected by the allowlists.

### 3.4 Errors

- Messages match the style used in Students: `Validation failed.` (422 and 409 with field `errors`), `Term not found.`, `Section not found.`, `Access denied.`, `Invalid CSRF token.`.
- Error messages never echo input values.
- A corrupt or unreadable store throws `RuntimeException` in the repository. The front controller turns it into a generic 500 `An internal error occurred.`.

## 4. Models (`App\Enrollment\Models`)

Both are immutable value objects like `Student`: a private constructor, `fromArray(mixed $row)` with strict validation (throws `RuntimeException('Invalid stored term|section.')`), getters, and `toArray()`.

- `Term`
  - `FIELDS = ['id','code','name','start_date','end_date','is_current','enrollment_open','created_at','updated_at']`
  - Getters: `id()`, `code()`, `isCurrent()`, `startDate()`, `endDate()`.
- `Section`
  - `FIELDS = ['id','term_id','course_code','course_title','units','section_code','capacity','approved_count','meetings','created_at','updated_at']`
  - `DAYS = ['MON','TUE','WED','THU','FRI','SAT','SUN']`
  - `fromArray` checks: `0 ≤ approved_count ≤ capacity`, `capacity ≥ 1`, and that meetings are a list of `{day,start,end,room}` with valid values.
  - Getters: `id()`, `termId()`, `courseCode()`, `sectionCode()`, `capacity()`, `approvedCount()`.
  - `toArray()` inserts `remaining` after `approved_count`.

## 5. Repository (`App\Enrollment\Repositories`)

```php
interface CatalogRepositoryInterface
{
    /** @return list<Term> ordered by start_date, then id. */
    public function terms(): array;
    public function findTerm(int $id): ?Term;
    /** Assigns id/timestamps. 409 on duplicate code. If is_current, clears every other term in the same write. */
    public function createTerm(array $fields, string $now): Term;
    /**
     * $changes ⊆ name,start_date,end_date,is_current,enrollment_open. 404 if missing.
     * $check(array $mergedRow) runs inside the lock before writing (throw to abort).
     * is_current=true clears every other term in the same write.
     */
    public function updateTerm(int $id, array $changes, string $now, ?callable $check = null): Term;

    public function findSection(int $id): ?Section;
    /** $filters: term_id, course_code, search. @return array{sections: list<Section>, total: int} */
    public function searchSections(array $filters, int $page, int $perPage): array;
    /** approved_count = 0. In lock: term must exist (422 term_id), unique (term_id, course_code, section_code) (409). */
    public function createSection(array $fields, string $now): Section;
    /**
     * $changes ⊆ course_title,units,capacity,meetings. 404 if missing.
     * $check(array $mergedRow) runs inside the lock (SectionService passes the capacity ≥ approved_count rule, 409).
     */
    public function updateSection(int $id, array $changes, string $now, ?callable $check = null): Section;
}
```

- `MockCatalogRepository implements CatalogRepositoryInterface`, constructed with `new JsonStore($runtime . '/enrollment/enrollment.json')`.
- Like `MockStudentRepository`, `updateTerm` and `updateSection` throw `InvalidArgumentException` when given a change key outside their mutable list. This is a programming error, so it surfaces as a 500.
- Business rules (formats, the merged-date rule, the capacity rule) live in the services. The repository owns the in-lock row-dependent invariants: uniqueness, term existence, the single current term, and id assignment.

**Store shape (`enrollment.json`):**
```json
{"terms":[{"id":1,"code":"2026-1S","name":"First Semester 2026-2027","start_date":"2026-08-10","end_date":"2026-12-18",
  "is_current":true,"enrollment_open":true,"created_at":"2026-11-17T00:00:00Z","updated_at":"2026-11-17T00:00:00Z"}],
 "sections":[{"id":1,"term_id":1,"course_code":"CS101","course_title":"Introduction to Computing","units":3,"section_code":"A",
  "capacity":40,"approved_count":0,"meetings":[{"day":"MON","start":"09:00","end":"10:30","room":"R-101"}],
  "created_at":"...","updated_at":"..."}]}
```

**Fail-closed snapshot validation** runs on every read and on every transaction before the callback works on the data:
- A missing file or `{}` means an empty store.
- Otherwise the top-level keys must be a subset of `["terms","sections"]` (T-21 and T-22 extend this set), and each value must be a list.
- Every row must pass `fromArray`. Ids must be unique per list.
- At most one term may have `is_current: true`.
- Every `section.term_id` must exist in `terms`.
- Any violation throws `RuntimeException('Invalid enrollment store.')`, which becomes a generic 500. Nothing is written.

## 6. Concurrency

- Every write is one `JsonStore::transaction()`: exclusive lock, read, validate, mutate, temp file, rename. Do not nest transactions or open a second `JsonStore` on the same path inside one.
- **Before the lock:** shape and format validation of the input only.
- **Inside the lock:** everything that depends on other rows or on the stored row:
  - term code uniqueness
  - clearing other `is_current` flags
  - term existence for a section
  - section triple uniqueness
  - `$check` on the merged row (dates, capacity vs `approved_count`)
  - id assignment (`max + 1`)
- Partial PATCHes merge into the row read under the lock, so concurrent PATCHes of different fields are both kept.
- A failed `rename` (Windows or OneDrive) leaves the file untouched (existing JsonStore behavior).

## 7. Tests (owner: qa-engineer, `php tests/run.php`, offline)

**Support (`tests/Enrollment/EnrollmentSupport.php`):**
- Requires `tests/Auth/HttpSupport.php` and defines its own fictional user rows: admin 1, registrar 2, faculty 3, student 4, plus inactive 5.
- `enrollmentFixture($dir)` returns `[TermService, SectionService, MockCatalogRepository, MockUserRepository]` with a fixed clock.
- `termInput()` and `sectionInput()` builders.
- `bumpApprovedCount($dir, $sectionId, $n)`: a test-only `JsonStore` transaction that edits `approved_count`, simulating T-22.
- `enrollmentServer()` (`AuthHttpServer`).

**Unit: `TermServiceTest.php`**
1. Create: normalizes the code (`" 2026-1s "` → `2026-1S`), defaults both booleans to `false`, and returns the exact key list.
2. Each required field missing; each invalid format (code, name with control or bidi characters, 101 chars, `2026-02-30`, `2026-2-01`, non-string dates, `start == end`, `start > end`, `is_current: 1` / `"true"`) gives 422 with the right key.
3. A duplicate code, including a case or whitespace variant, gives 409 `code`.
4. `is_current: true` on create and on PATCH clears the previous current term, so exactly one remains. `false` leaves zero.
5. PATCH: empty body gives 422. Missing id gives 404. `end_date` alone set before the stored `start_date` gives 422 `end_date`. Overlapping terms are allowed.
6. Student and faculty actors calling the service directly get 403 on create and update.
7. The list is ordered by `start_date`, then `id`.

**Unit: `SectionServiceTest.php`**
1. Create happy path: the code is normalized, `approved_count` is 0, `remaining` equals `capacity`, meetings are stored sorted, and the result has no `instructors` key.
2. `term_id` unknown, or a non-int value, gives 422 `term_id`.
3. Duplicate triple gives 409. The same course and section in another term, or the same course with another section code, is accepted.
4. Meeting checks:
   - Day: `mon` and `MONDAY` give 422.
   - Time: `24:00`, `9:00` and `09:60` give 422.
   - `start == end` gives 422.
   - Room: empty, 31 chars or a control character gives 422.
   - Extra key, missing key, item not an object, `meetings` not a list, 0 items and 15 items each give 422.
   - Same-day overlap gives 422. Back-to-back and same time on different days are accepted.
5. Capacity of `0`, `-1`, `1001` or `"10"` gives 422.
6. After `bumpApprovedCount(…, 5)`:
   - PATCH `capacity: 4` gives 409 `capacity`.
   - `5` is accepted and `remaining` becomes 0.
   - Other PATCHes (title) keep `approved_count`.
7. List filters:
   - `term_id`, `course` (normalized), `search` (case-insensitive on title, course and section code), ordering, `total` and slicing all work.
   - `page=0`, `per_page=101`, `term_id=abc`, `search[]=x`, `course[]=x` and a 101-char search give 422.
8. `get` on a missing id gives 404. Writes by student or faculty give 403.

**Store: `CatalogStoreTest.php`**
- Each corrupt snapshot throws `RuntimeException` on read **and** on write, and a failed write leaves the file byte-identical:
  - invalid JSON
  - `terms` not a list
  - an extra or missing row key
  - an unknown top-level key
  - a duplicate id
  - two current terms
  - `approved_count > capacity`
  - a section whose term is missing
  - a meeting with a bad day
- Over HTTP, a corrupt store gives 500 with body `An internal error occurred.` and no detail.
- Windows only: holding the file open makes a write fail with `Unable to replace store.` and leaves the store unchanged (same pattern as the Students test).

**HTTP: `EnrollmentHttpTest.php`**
1. The full role matrix from §3.3 over real sessions. Response bodies for 401, 403 and 404 match exactly.
2. Missing CSRF on each write gives 403 `Invalid CSRF token.`, before validation.
3. A non-JSON body gives 415.
4. Unknown or immutable fields give 422:
   - PATCH term: `code`, `id`
   - PATCH section: `term_id`, `course_code`, `section_code`, `approved_count`, `remaining`
   - POST section: `approved_count`, `instructors`
5. Meetings sent as real JSON objects are accepted. A meeting sent as a JSON array (`["MON","09:00",…]`) gives 422.
6. The envelope has headers `Content-Type`, `Cache-Control: no-store` and `nosniff`. `GET /api/sections/abc` gives 404.

**Concurrency: `EnrollmentConcurrencyTest.php` + `fixtures/enrollment_worker.php`**
- Copies `raceStudentWorkers`: start barrier, ready flags, `proc_open` with `bypass_shell`, stderr must be empty. The worker acts as registrar (user 2).
- Ops: `createTerm`, `updateTerm`, `createSection`, `updateSection`, and `bump` (raw `approved_count` increment in a store transaction, guarded by `approved_count < capacity`).

| # | Race | Expected |
|---|---|---|
| C1 | 3× `createTerm` with the same code | Exactly one success, the others 409 `code`, stored count 1 |
| C2 | 3× `updateTerm {is_current:true}` on three different terms, 3 rounds | Exactly one current term at the end, all ok |
| C3 | 3× `createSection` with the same triple | Exactly one, the others 409 |
| C4 | PATCH `capacity` down to N racing `bump` workers, 3 rounds | Stored `capacity ≥ approved_count` always. Each loser gets 409 or a refused bump |
| C5 | Concurrent PATCHes of `course_title`, `units` and `meetings` on one section | All three changes kept (no lost update) |

**Mutation checks (qa-engineer, in a scratch copy outside the workspace, never in owned files):**
Apply each mutation, run the suite, and confirm at least one test fails, then discard the copy. Record the results in the report.

| # | Mutation | Expected failing test |
|---|---|---|
| M1 | Remove the in-lock capacity `$check` | Unit 6 / C4 |
| M2 | Don't clear other `is_current` flags | Term unit 4 / C2 |
| M3 | Check term-code uniqueness from a pre-lock `read()` | C1 (3 rounds) |
| M4 | Change the POST section guard to `authenticated` | Role matrix |
| M5 | Add `approved_count` to the create allowlist | HTTP 4 |
| M6 | Overlap uses `<=` | Back-to-back test |
| M7 | Drop the two-current-terms store check | Store test |
| M8 | Drop `requireRole` in SectionService | Unit 8 |

All 124 existing tests must still pass, and `php -l` must pass on every PHP file.

## 8. Acceptance criteria (testable)

1. Every endpoint in §3 behaves per its table. The §3.3 matrix holds over HTTP.
2. Term code is unique after normalization (409) and immutable (422). Dates are real `YYYY-MM-DD` with `start < end`, also after a partial PATCH.
3. At most one current term at any time, including under concurrent PATCHes (C2).
4. Section triple is unique (409), including under concurrency (C3). An unknown term gives 422.
5. Meetings are validated per §3.2. A section's own meetings never overlap.
6. Capacity is 1–1000 and never below `approved_count` (409), including against concurrent count changes (C4). `remaining = capacity − approved_count`.
7. `approved_count` is 0 on create and is never accepted from clients.
8. Unknown and immutable fields give 422. Errors use the standard envelope with no echoed input. A corrupt store gives a generic 500 and is never written.
9. Section JSON has no `instructors` key (T-21).
10. `php tests/run.php` is fully green (124 existing tests plus the new ones). `php -l` is clean. MySQL mode still fails closed.
11. `php database/seeders/dev_sections.php` seeds fictional data once and refuses to run on a non-empty store or in mysql mode.

## 9. B-04 integration contract (owner decision 2026-11-17)

**Ownership:**
- **Team 4** owns the authoritative instructor-to-section assignment: storage, rules, endpoint and audit.
- **Team 5** owns faculty profiles and grades. It reads assignments only through Team 4's interface and keeps no assignment table of its own.

**Interfaces.** Both live in `app/Enrollment/Contracts/`, so the service graph stays acyclic: Enrollment ← Faculty.

| Interface | Defined by | Implemented by | Methods |
|---|---|---|---|
| `FacultyIdentityLookup` (port; replaces `InstructorLookup`) | Team 4 (shape agreed with Team 5) | **Team 5** (T-30). Until then `UnavailableFacultyIdentity` (Team 4) returns `null` for every id | `find(int $facultyId): ?FacultyIdentity` · `findMany(int[] $ids): array<int, FacultyIdentity>`. `FacultyIdentity {faculty_id, display_name, active}` |
| `SectionDirectory` | Team 4 | Team 4 (T-21) | `findSection(int): ?SectionSummary` · `approvedStudentIds(int): int[]` (returns `[]` until T-22) · **`isAssigned(int $facultyId, int $sectionId): bool`** · **`sectionIdsForFaculty(int $facultyId): int[]`** · `assignedFacultyIds(int $sectionId): int[]` |

`FacultyDirectory` (Team 5) keeps only `findByUserId(int $userId): ?FacultySummary`. Its `isAssigned` and `sectionIdsForFaculty` move to `SectionDirectory`.

**Endpoint.** The new owner is Team 4. It replaces `POST /api/registrar/teaching-assignments` (Team 5).

| Method | Path | Roles | CSRF | Rule |
|---|---|---|---|---|
| POST | `/api/registrar/sections/{id}/instructors` | registrar, admin | ✔ | `{faculty_id}` → 201. 404 for a missing section. 422 when `faculty_id` is unknown or inactive per `FacultyIdentityLookup` (fail closed: lookup error or null means refused). 409 for a duplicate, or above a bound of 5 instructors per section |
| DELETE | `/api/registrar/sections/{id}/instructors/{facultyId}` | registrar, admin | ✔ | 200. 404 when not assigned |

**Rules:**
- Storage holds only `{section_id, faculty_id}` in `enrollment.json` (`assignments`). No faculty names or profile fields are copied.
- Section JSON gains `instructors: [{faculty_id, name}]`. Names are resolved at read time through `findMany`. A missing or failed lookup gives `name: null`, so the assignment fact is still shown.
- **Audit:** each successful assign or unassign appends exactly one entry to `assignment_history`, in the **same store transaction**: `{seq, at, action: "assigned"|"unassigned", section_id, faculty_id, actor_id}`, append-only, no free text, no secrets. Failed attempts write nothing. Same model as T-11a history. A staff read endpoint for this history is deferred.
- Authorization is server-side: the guard plus `requireRole` in the service. Faculty can't change assignments.
- No Team 6 schema changes. MySQL comes with T-40.

**Required task-description changes (for the lead to apply to TASK_BOARD / API_CONTRACTS):**
- **T-21:** rename to "SectionDirectory, faculty identity port and instructor assignments".
  - Files: `app/Enrollment/**` (including `Contracts/`), `routes/enrollment.php`, `app/bootstrap.php` (Enrollment wiring), `tests/Enrollment/*`, `README.md`.
  - Scope: the interfaces above, `UnavailableFacultyIdentity`, the assignment endpoints, audit history, and `instructors` in section JSON. Tests use a stub lookup.
  - Depends on T-20 and B-04 ✔.
- **T-30:** Faculty profiles, `FacultyDirectory::findByUserId`, and the **implementation of `FacultyIdentityLookup`**. `/api/faculty/me`, and `/api/faculty/classes` built from `SectionDirectory::sectionIdsForFaculty`.
  - Remove the TeachingAssignment model and repository and the registrar assignment endpoint.
  - Bootstrap: swap the `FacultyIdentityLookup` binding.
  - Its acceptance criterion "duplicate assignment → 409" moves to T-21.
- **T-31:** the roster check is `SectionDirectory::isAssigned(facultyId, sectionId)` (Team 4 source). The "officially assigned" fact for B-07 `FacultyStudentVisibility` also comes from Team 4.
- **API_CONTRACTS:**
  - §2: drop the Team 5 teaching-assignments row and add the two Team 4 instructor rows.
  - §3: replace `InstructorLookup` with `FacultyIdentityLookup` and move `isAssigned` / `sectionIdsForFaculty` to `SectionDirectory`.
  - §4: in the 4 ↔ 5 row, Team 4 owns assignments.

## 10. Dependencies handed to later tasks

- **T-21:**
  - Reads sections through `CatalogRepositoryInterface::findSection`.
  - Adds `assignments` and `assignment_history` to the store's allowed keys.
  - Adds `instructors` to the section response, which means changing `Section` or `SectionService`.
- **T-22:**
  - Adds `enrollments` to the **same** store so that approval and the `approved_count` increment commit in one transaction. Either extend `MockCatalogRepository` or add a repository on the same `JsonStore` path that never nests.
  - Reads `enrollment_open`.
  - Must handle "no current term".
  - Should decide whether `meetings` and `capacity` edits are restricted once enrollments exist, because a meeting change can create clashes for students who are already enrolled.
- **T-11b / D1:** course catalog validation of `course_code` (§3.2 note).
- **T-40:** the MySQL implementation of the same interface needs one transaction with row locks (`SELECT … FOR UPDATE` on the term and section rows). Unique keys are `terms.code` and (`term_id`, `course_code`, `section_code`).

## 11. Wiring (exact intent)

`app/bootstrap.php` (Enrollment block, after the Team 3 block and before the route loop):
```php
// Team 4 — terms & sections (T-20). One store so term/section checks share a lock (T-22 adds enrollments here).
$container['enrollment_catalog'] = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
$container['term_controller'] = new TermController(new TermService($container['enrollment_catalog'], $container['authorization']));
$container['section_controller'] = new SectionController(new SectionService($container['enrollment_catalog'], $container['authorization']));
```
Both services take an optional `?callable $clock` like `StudentService`.

`routes/enrollment.php`:
- `GET /api/terms` → `authenticated`
- `POST /api/registrar/terms` and `PATCH /api/registrar/terms/{id}` → `roles(['registrar','admin'])`
- `GET /api/sections` and `GET /api/sections/{id}` → `authenticated`
- `POST /api/registrar/sections` and `PATCH /api/registrar/sections/{id}` → `roles(['registrar','admin'])`

**Seeder `database/seeders/dev_sections.php`:**
- Same structure as `dev_students.php`: CLI only, mock only, `RuntimeDirectory::prepare`.
- If the store already has any terms or sections, it reports the state and exits 0 without writing (safe to re-run; lead decision after security review L-2). A failure partway through exits 1 and says that a reset must be manual and owner-approved. It never overwrites or deletes.
- Creates, through the repository's public methods:
  - 2 fictional terms (`2026-1S`, current and open; `2026-2S`)
  - 4 fictional sections with meetings: `CS101-A`, `CS101-B`, `IT211-A` (2026-1S) and `CS102-A` (2026-2S)
- Data must be plainly fictional: rooms like `R-101`, no real names.

**README:** a new section "Enrollment: terms & sections (Team 4, T-20)":
- the endpoint table, rules summary, role notes and seeder command
- the D1 note
- the storage note (single file `enrollment/enrollment.json`)

## 12. File ownership table

| Phase | File | Action | Owner |
|---|---|---|---|
| 1 | `app/Enrollment/Models/Term.php` | create | backend-developer |
| 1 | `app/Enrollment/Models/Section.php` | create | backend-developer |
| 1 | `app/Enrollment/Repositories/CatalogRepositoryInterface.php` | create | backend-developer |
| 1 | `app/Enrollment/Repositories/MockCatalogRepository.php` | create | backend-developer |
| 1 | `app/Enrollment/Services/TermService.php` | create | backend-developer |
| 1 | `app/Enrollment/Services/SectionService.php` | create | backend-developer |
| 1 | `app/Enrollment/Controllers/TermController.php` | create | backend-developer |
| 1 | `app/Enrollment/Controllers/SectionController.php` | create | backend-developer |
| 1 | `routes/enrollment.php` | modify | backend-developer |
| 1 | `app/bootstrap.php` (Enrollment wiring only) | modify | backend-developer |
| 1 | `README.md` (new Enrollment section only) | modify | backend-developer |
| 2a | `tests/Enrollment/EnrollmentSupport.php` | create | qa-engineer |
| 2a | `tests/Enrollment/TermServiceTest.php` | create | qa-engineer |
| 2a | `tests/Enrollment/SectionServiceTest.php` | create | qa-engineer |
| 2a | `tests/Enrollment/CatalogStoreTest.php` | create | qa-engineer |
| 2a | `tests/Enrollment/EnrollmentHttpTest.php` | create | qa-engineer |
| 2a | `tests/Enrollment/EnrollmentConcurrencyTest.php` | create | qa-engineer |
| 2a | `tests/Enrollment/fixtures/enrollment_worker.php` | create | qa-engineer |
| 2b | `database/seeders/dev_sections.php` | create | database-engineer |
| 3 | `ai-coordination/T20_IMPLEMENTATION_REPORT.md` | create | qa-engineer (or the lead, if preferred) |

Not owned by any phase agent: `ai-coordination/TASK_BOARD.md` and `ai-coordination/API_CONTRACTS.md` (the lead applies §9 changes). `app/Enrollment/Contracts/` is untouched (T-21).

**Sequence:**
1. **Phase 1: backend-developer**, alone. The files depend on each other and `bootstrap.php` is shared. Finish with `php -l` on the new files and a green run of the existing 124 tests.
2. **Phase 2a (qa-engineer) and 2b (database-engineer) in parallel.** The file sets are disjoint, and both only read phase 1 code.
3. **Phase 3:**
   1. qa-engineer runs the full suite, the mutation checks and `php -l`, and writes the report.
   2. security-reviewer reviews (read-only).
   3. The owning agent fixes each finding.
   4. qa-engineer re-runs.

Optional: qa-engineer may draft phase 2a tests from this spec while phase 1 runs, because the files are disjoint. Tests must not be treated as results until phase 1 is complete.

## 13. Risks

| Risk | Mitigation |
|---|---|
| D1 rework: free-text course fields may need reconciling with a future catalog | Format-only validation, documented. T-11b owns the migration plan |
| A single growing JSON file, fully validated on every request | Acceptable for development (same as T-11a L-01). Production waits for T-40 |
| OneDrive or AV file locks on Windows | Existing JsonStore rename retry. `APP_RUNTIME_PATH` outside OneDrive is documented |
| Immutable codes with no delete endpoint mean typos are permanent | Q1, Q4 |
| Meeting or capacity edits after enrollments exist | Handed to T-22 (§10) |
| `room` and `course_title` are free text shown by Team 1 | Control and format characters are rejected. JSON has `nosniff`. Team 1 must escape output |
| Concurrency tests are probabilistic | 3 rounds, plus mutation check M3 |

No destructive database operations. No schema changes. Nothing here needs approval beyond the open questions.

## 14. Open questions (owner)

| # | Question | Proposed default (used by this plan) |
|---|---|---|
| Q1 | Term `code` immutable, with no delete. Is a mistyped code acceptable until an archive or delete task exists? | Immutable |
| Q2 | Units: integer 1–12. Are 0-unit or fractional-unit courses needed? | Int 1–12 |
| Q3 | Must every section have at least one meeting (no TBA or online-only)? | Yes, 1–14 |
| Q4 | `course_code` / `section_code` immutable after create? | Yes |
| Q5 | Delete or archive for terms and sections? | Out of scope |
| Q6 | Terms list unpaginated? | Yes |
| Q7 | B-04: confirm T-21 is expanded (not a new task) for instructor assignment, with a max of 5 instructors per section | Expand T-21, max 5 |
