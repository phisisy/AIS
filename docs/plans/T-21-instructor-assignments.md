# T-21 — SectionDirectory, faculty identity port and instructor assignments (Team 4) — Plan

- **Status:** DRAFT. Planning only. **Nothing is approved for implementation.** The owner must approve this plan, the T-21 file list (§12) and the open questions (§14) first.
- **Base:** `main` at `8c2b980` (T-20 merged, 163 tests green).
- **Branches (proposed, §12):** `task/T-21-instructor-assignments` (Claude) and `task/T-21-impl` (Codex, branched from Claude's phase-1 commit).
- **Pattern to copy:** T-20 Enrollment module (`MockCatalogRepository` with one store and one lock, `$check` closures, `fromArray` fail-closed). The audit pattern comes from T-11a `MockStudentRepository::appendHistory`.
- **Out of scope:**
  - T-22: enrollments. `approvedStudentIds` returns `[]`.
  - T-30: the real `FacultyIdentityLookup` and `FacultyDirectory`.
  - T-31: the B-07 visibility implementation.
  - A history read endpoint, a DB schema and seeder changes. MySQL mode stays fail-closed.

## 1. Owner decisions (2026-11-20, binding) and where they are covered

| # | Decision | Section |
|---|---|---|
| 1 | Team 4 owns instructor-to-section assignments | §3, §5 |
| 2 | Team 5 verifies faculty identity through the Team 4 port `FacultyIdentityLookup` (T-30) | §6.1 |
| 3 | T-21 may be developed before T-30 | §6.1, §11 |
| 4 | Production fails closed: bootstrap wires `UnavailableFacultyIdentity`, so every assign gets 422 and nothing is written | §3.2, §9.6 |
| 5 | Mock lookups only under `tests/`. Never wired in bootstrap, never selected by config or env | §6.1, §9.6 |
| 6 | No overlapping instructor schedules within the same term | §4 |
| 7 | 409 for instructor schedule conflicts | §3.2, §4 |
| 8 | Conflict checks are atomic, inside the single enrollment store lock | §7 |
| 9 | Append-only assignment history in the same transaction, with the actor id. Failures write nothing | §5.2 |
| 10 | Store only `{section_id, faculty_id}`. Names are resolved at read time | §3.3, §5.1 |
| 11 | At most 5 instructors per section (Q7) | §3.2 |

## 2. Decisions taken by this plan

| ID | Decision | Why |
|---|---|---|
| A1 | **No router change.** `Router::add` accepts any `[A-Z]+` method and any number of `{name}` segments. `DELETE /api/registrar/sections/{id}/instructors/{facultyId}` gives `params = {id, facultyId}`, both positive ints. `RouteGuard` already enforces CSRF on every non-GET/HEAD/OPTIONS method. `Request` accepts an empty DELETE body. `.htaccess` has no `<Limit>`. | Verified in `app/Core/Router.php`, `Request.php`, `RouteGuard.php` and both `.htaccess` files. No Core owner is needed. Phase 3 still checks DELETE through Apache. |
| A2 | Assign and unassign go in **`SectionService` / `SectionController`**. There is no separate `AssignmentService` or `AssignmentController`. | The responses are the section JSON with `instructors`, so one presenter is shared and nothing is duplicated. This differs from the draft in `AGENT_TASKS.md`. |
| A3 | The **repository** owns the in-lock checks: section exists, duplicate, cap, schedule conflict. The conflict check is one private helper that both `assignInstructor` and `updateSection` call. | This matches T-20 §5 (the repository owns row-dependent invariants). One rule in one place means the PATCH path can't drift from the assign path. |
| A4 | The identity lookup runs **before** the lock, in the service. | It is a cross-module call (in T-30, another store). Calling it inside the enrollment lock invites nested or ordering lock bugs. It doesn't depend on enrollment rows. The accepted TOCTOU window (deactivated between lookup and write) is covered in §13. |
| A5 | **Section PATCH that would create an instructor conflict is refused with 409** `{meetings: …}`. Nothing is written. There is no auto-unassign and no "flag and allow". | This keeps the invariant "the store never holds a conflict" true at all times, which lets the snapshot reject stored conflicts (fail closed). Auto-unassign would be a silent, unrequested change to the audit trail. The registrar resolves it by unassigning first, which is audited, and then patching. |
| A6 | The conflict rule uses **meetings only**. `delivery_mode` doesn't take part, so a section with 0 meetings never conflicts. A `tba` or `online_async` section that does list meetings **is** checked. | The rule is deterministic and matches the T-22 student clash rule (T-20 §16). A mode-only PATCH can't change meetings, so it can never create a conflict, but the check still runs on the merged row. See Q1. |
| A7 | **Unassign never calls the identity lookup.** | Staff must be able to remove an assignment of a deactivated or unverifiable faculty member. In production (Unavailable lookup) DELETE still works on any existing row. |
| A8 | One refusal message for a lookup that returns null, an inactive identity, an id mismatch or a thrown `Throwable`: 422 `{faculty_id: "Unknown or inactive faculty member."}` | No enumeration of faculty ids, and fail closed on every error path. |
| A9 | Names: `findMany` is called once per response, for every faculty id on the page. On a thrown exception, a missing key or a key/id mismatch, `name` is `null`. An **inactive** identity still shows its `display_name`. | The assignment fact is always shown. Inactivity blocks new assignments, but it doesn't rewrite what is displayed. |
| A10 | `assignment_history` is **one global list** with a gap-free `seq = index + 1`. It isn't a per-section map. | Simpler than the T-11a map, and "gap-free" is directly checkable. |
| A11 | Snapshot validation **replays** the history. Every `assigned` entry needs the pair to be absent, every `unassigned` entry needs it present, and the replayed set must equal `assignments`. | It detects hand edits to either list. It costs a few lines and one linear pass. |
| A12 | ARCHITECTURE §6 item 3 ("client-supplied `faculty_id` rejected") applies to the actor's identity on "me" routes. Here `faculty_id` is the *target* of a staff action, validated through the port. The actor always comes from the session. | Recorded so that a reviewer doesn't flag it. |
| A13 | Instructor order is the stored assignment order. Assign appends, and unassign removes one entry and keeps the rest in order. | Deterministic, and it carries meaning (first assigned appears first). |

## 3. API contract

All responses use the existing envelope. Path ids are positive ints (Router). Error messages never echo input.

### 3.1 Section JSON (changed for every section response)

Exact key order. `instructors` sits after `meetings`:
`{id, term_id, course_code, course_title, units, section_code, capacity, approved_count, remaining, delivery_mode, meetings, instructors:[{faculty_id, name}], created_at, updated_at}`

- `GET /api/sections`, `GET /api/sections/{id}`, POST and PATCH section, and both assignment endpoints all return this shape.
- POST section always returns `instructors: []` and doesn't call the lookup.
- `name` is `string|null`. In production until T-30 it is always `null`, and the list is empty anyway, because no assignment can be created.
- `instructors` isn't writable through POST or PATCH section. Both give 422 `Unknown field.`, because the controller allowlists don't change.

### 3.2 New endpoints

| Method | Path | Guard | CSRF | Body | Success | Errors |
|---|---|---|---|---|---|---|
| POST | `/api/registrar/sections/{id}/instructors` | registrar, admin | ✔ | `{faculty_id}` only | 201 `"Instructor assigned."` `data.section` | 401, 403, 404, 409, 415, 422 |
| DELETE | `/api/registrar/sections/{id}/instructors/{facultyId}` | registrar, admin | ✔ | none. Any field gives 422 `Unknown field.` (controller calls `$request->json([])`) | 200 `"Instructor unassigned."` `data.section` | 401, 403, 404, 415, 422 |

**POST order:**
1. Guard: 401, then 403, then CSRF 403.
2. Controller allowlist `['faculty_id']`: 422.
3. `requireRole(STAFF)`: 403.
4. Pre-lock find: 404 `Section not found.`
5. `faculty_id` format:
   - missing: 422 `{faculty_id: "This field is required."}`
   - not a strict int ≥ 1: 422 `{faculty_id: "Use a positive whole number."}` (`"12"` is rejected)
6. Identity: `find()` must return an identity with the same id and `active === true`. Otherwise 422 (A8).
7. **In the lock**, in this order:
   1. Section exists: 404.
   2. Duplicate: 409 `{faculty_id: "This instructor is already assigned to this section."}`
   3. Cap: 409 `{instructors: "A section can have at most 5 instructors."}`
   4. Schedule conflict: 409 `{faculty_id: "This instructor already teaches <COURSE>-<SECTION> (section <id>) at an overlapping time."}`
   5. Append the assignment and one `assigned` history entry.

**DELETE order:**
1. Guard.
2. Body allowlist `[]`: 422.
3. `requireRole`: 403.
4. **In the lock:**
   1. Section exists: 404 `Section not found.`
   2. Pair exists: 404 `Instructor assignment not found.`
   3. Remove the assignment and append one `unassigned` entry.

**Section PATCH (changed):** after the existing `$check` (422 mode/meeting pairing, then 409 capacity), and still in the same lock, the repository re-runs the conflict check for **every** instructor assigned to that section against the merged row. On a clash it returns 409 `{meetings: "Instructor <faculty_id> already teaches <COURSE>-<SECTION> (section <id>) at an overlapping time."}`. The first clash found is reported (ordered by assignment order, then by other section id). Nothing is written.

**What a 409 conflict reveals:** only the clashing section's `course_code`, `section_code` and `id`, which signed-in users can already see in the public catalog. It never reveals meeting times, rooms, other instructors or names.

### 3.3 Authorization matrix (HTTP)

| Caller | GET sections / section | POST instructors | DELETE instructor |
|---|---|---|---|
| Anonymous | 401 | 401 | 401 |
| Student | 200 (sees `instructors`) | 403 | 403 |
| Faculty | 200 (sees `instructors`) | 403 | 403 |
| Registrar | 200 | **production: 422** (fail closed). With the test stub (service and controller level): 201 | 200 if assigned (raw-seeded in the HTTP test), else 404 |
| Admin | 200 | same as registrar | same as registrar |
| Staff, missing or invalid CSRF | — | 403 `Invalid CSRF token.` before validation | 403 before validation |

Service methods repeat `requireRole(['registrar','admin'])` (defense in depth). Student and faculty actors calling the service directly get 403.

## 4. Conflict rule (one helper in `MockCatalogRepository`)

Faculty F may not be assigned to target section S if there is another section X ≠ S such that:
1. (F, X) is already in `assignments`, **and**
2. `X.term_id === S.term_id`, **and**
3. some meeting `a` of S and some meeting `b` of X satisfy `a.day === b.day && a.start < b.end && b.start < a.end`.

More precisely:
- Back-to-back meetings (`09:00–10:00` and `10:00–11:00`) are fine.
- Different terms never conflict.
- A section with zero meetings never conflicts (A6).
- On PATCH, S is the merged row and the rule runs for each F assigned to S.
- `term_id` is immutable (T-20 P5), so meetings and assignments are the only inputs that can change. Both are checked under the lock.
- Implementation note: add the getter `Section::meetings(): array` and reuse the same interval test as `Section::validMeetings`.

## 5. Storage (`$runtime/enrollment/enrollment.json`)

The allowed top-level keys become `["terms","sections","assignments","assignment_history"]`. A missing key means an empty list, so a T-20 store loads unchanged. Writes always store both new keys.

```json
"assignments":[{"section_id":1,"faculty_id":12}],
"assignment_history":[{"seq":1,"at":"2026-11-20T00:00:00Z","action":"assigned","section_id":1,"faculty_id":12,"actor_id":2}]
```

### 5.1 `assignments` validation (fail closed → `RuntimeException('Invalid enrollment store.')`, generic 500, nothing written)

- The value is a list. Each row has keys **exactly** `["section_id","faculty_id"]`, both strict ints ≥ 1.
- `section_id` exists in `sections`.
- The pair `(section_id, faculty_id)` is unique.
- At most 5 rows per `section_id`.
- **No stored conflict** (§4 run over all pairs). This is the reason for A5.

### 5.2 `assignment_history` validation

- The value is a list. Each entry has keys exactly `["seq","at","action","section_id","faculty_id","actor_id"]`.
- `seq === index + 1`.
- `at` matches `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z`.
- `action` is `assigned` or `unassigned`.
- `section_id` exists, and `faculty_id` and `actor_id` are ints ≥ 1.
- Replay rule (A11): replaying the history must give exactly the set in `assignments`.
- Entries are only ever appended. There is no update or delete path in code. No free text, names or secrets.

## 6. Interfaces

### 6.1 `app/Enrollment/Contracts/` (Claude, phase 1)

```php
namespace App\Enrollment\Contracts;

/** Team 5 → Team 4 identity fact. Constructor throws InvalidArgumentException on bad data (so a bad T-30 row fails closed). */
final class FacultyIdentity
{
    public function __construct(
        public readonly int $facultyId,      // >= 1
        public readonly string $displayName, // trimmed, 1–150 chars, UTF-8, no \p{Cc}\p{Cf}
        public readonly bool $active,
    ) {}
}

/** Port defined by Team 4, implemented by Team 5 (T-30). Production binding until then: UnavailableFacultyIdentity. */
interface FacultyIdentityLookup
{
    /** null = unknown. Callers refuse on null, !active, id mismatch or any Throwable. */
    public function find(int $facultyId): ?FacultyIdentity;
    /** @param list<int> $facultyIds @return array<int, FacultyIdentity> keyed by faculty_id; unknown ids omitted. */
    public function findMany(array $facultyIds): array;
}

/** Verifies nobody: every assignment is refused (owner decision 4). Never replaced by a mock outside tests/. */
final class UnavailableFacultyIdentity implements FacultyIdentityLookup
{
    public function find(int $facultyId): ?FacultyIdentity { return null; }
    public function findMany(array $facultyIds): array { return []; }
}

/** Read-only section fact for Teams 3/5, in-process only. */
final class SectionSummary
{
    public function __construct(
        public readonly int $id, public readonly int $termId,
        public readonly string $courseCode, public readonly string $sectionCode,
    ) {}
}

/** Team 4 → Teams 3/5 (B-04). Callers have already authenticated and authorized their own request. */
interface SectionDirectory
{
    public function findSection(int $sectionId): ?SectionSummary;
    /** @return list<int> Returns [] until T-22. */
    public function approvedStudentIds(int $sectionId): array;
    /** Unknown section or faculty → false. */
    public function isAssigned(int $facultyId, int $sectionId): bool;
    /** @return list<int> ascending */
    public function sectionIdsForFaculty(int $facultyId): array;
    /** @return list<int> in assignment order */
    public function assignedFacultyIds(int $sectionId): array;
}
```

### 6.2 `CatalogRepositoryInterface` additions (Codex)

```php
/** @param list<int> $sectionIds @return array<int, list<int>> each requested id => faculty ids in assignment order ([] if none). One read. */
public function assignedFacultyIds(array $sectionIds): array;
/** @return list<int> ascending */
public function sectionIdsForFaculty(int $facultyId): array;
/** One lock: 404 section, 409 duplicate, 409 cap, 409 conflict; appends one 'assigned' entry. @return array{section: Section, faculty_ids: list<int>} */
public function assignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array;
/** One lock: 404 section, 404 pair; appends one 'unassigned' entry. @return array{section: Section, faculty_ids: list<int>} */
public function unassignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array;
```

The `updateSection` doc gains: "after `$check`, rejects instructor schedule conflicts on the merged row (409 `meetings`)."

### 6.3 Services and controller (Codex)

- **`SectionService::__construct`**:
  - The signature becomes `(CatalogRepositoryInterface $catalog, AuthorizationService $authorization, ?FacultyIdentityLookup $identities = null, ?callable $clock = null)`.
  - `null` becomes `new UnavailableFacultyIdentity()` (fail closed), so the current two-argument bootstrap line keeps working until phase 3.
  - Tests that pass `$clock` positionally must move it to the fourth position.
- **New methods:**
  - `assignInstructor(User $actor, int $sectionId, array $input): array`
  - `unassignInstructor(User $actor, int $sectionId, int $facultyId): array`
- **Private `present(list<Section>)`:** one `assignedFacultyIds` read, then one `findMany` (skipped when there are no ids, and wrapped in `try/catch (\Throwable)`, A9), then `Section::withInstructors()`.
  - `list`, `get` and `update` use it.
  - `create` uses `withInstructors([])`.
- **`Section::withInstructors(list<array{faculty_id:int,name:?string}>): array`** inserts the key after `meetings`. `toArray()` stays as it is for internal use.
- **`SectionController`:**
  - New method `assignInstructor`: `$request->json(['faculty_id'])`, returns 201.
  - New method `unassignInstructor`: `$request->json([])`, returns 200.
- **`RepositorySectionDirectory implements SectionDirectory`** (in `app/Enrollment/Services/`), built on `CatalogRepositoryInterface`. `approvedStudentIds` returns `[]` with the comment `// shortcut: no enrollments until T-22, which replaces this`.

## 7. Concurrency

- Every assign, unassign and section PATCH is one `JsonStore::transaction()`.
- Inside it, the snapshot is validated (§5), then the section exists / duplicate / cap / conflict checks run, then the history entry is appended, then the write.
- There are no nested transactions. The identity lookup runs before the lock (A4).
- The conflict check reads only `$data` inside the callback, never a pre-lock `read()` (mutation M9).
- A PATCH and an assign that race are serialized by the same lock. Whichever runs second sees the other's committed state and gets 409.

## 8. Integration blockers and the B-07 comment

**Proposed new blocker B-11 (Medium): faculty user id ↔ `faculty_id`.** For the lead to add to `INTEGRATION_BLOCKERS.md`.
- B-07 `FacultyStudentVisibility::sharedSections(int $facultyUserId, …)` is keyed by the **user id** (Team 2). Assignments use the Team 5 **`faculty_id`**.
- The mapping needs `FacultyDirectory::findByUserId` (T-30). T-31 consumes it:
  1. `findByUserId(sessionUser.id)`
  2. then `faculty_id`
  3. then `SectionDirectory::sectionIdsForFaculty` / `isAssigned`
- If the mapping is missing or the lookup fails, access is denied.
- Until T-30 and T-31, `DenyAllFacultyVisibility` stays wired. T-21 doesn't touch it.
- Unblocked by: T-30. Consumer: T-31.

**Outdated comment in `app/Students/Contracts/FacultyStudentVisibility.php` (lines 6–10).** Exact replacement:

```php
/**
 * B-07 source of truth for faculty access to student summaries. Implemented in T-31 from
 * Team 4's SectionDirectory (B-04: Team 4 owns instructor-to-section assignments, T-21)
 * and Team 4 approved enrollments (T-22). $facultyUserId is mapped to the Team 5 faculty_id
 * through FacultyDirectory::findByUserId (T-30). Implementations must return only verified,
 * official assignments; [] means "not visible".
 */
```

- **Owner of the edit:** Claude, in T-21 phase 3. It is a comment-only edit, and no code changes.
- This needs the owner to add the file to T-21's allowed files (Q2). Fallback: T-31 applies it.

**Other dependencies (lead updates `API_CONTRACTS.md` §3 / `TASK_BOARD.md` T-30):**
- T-30 swaps the `faculty_identity` binding and updates the source-scan test (§9.6).
- T-30's "Sections now show instructor names" holds once its lookup is bound.

## 9. Tests (Codex, `tests/Enrollment/*`, offline, `php tests/run.php`)

**Test stub:** `tests/Enrollment/fixtures/StubFacultyIdentity.php`.
- Configurable map `id => [name, active]`, plus a `throw` mode.
- It is required explicitly by `EnrollmentSupport.php` and the worker. It lives outside `app/`, so the autoloader can never load it.

**Support:** `enrollmentFixture()` also returns the stub. New helpers:
- `assignInput()`
- `seedAssignment($dir, $sectionId, $facultyId)`: raw store write of a consistent assignment plus its history entry, for HTTP tests.
- `storeBytes($dir)`

### 9.1 Unit: `AssignmentServiceTest.php` (new)

1. Happy path:
   - 201 shape, `instructors[0] = {faculty_id, name}`, key order exact.
   - One history entry with `actor_id` = actor and `at` = clock.
2. Format errors:
   - Missing `faculty_id`, `"12"`, `0`, `-1`, `1.5`, `null` give 422 `faculty_id`.
   - Unknown body field gives 422 at controller level.
3. Fail closed. Each of these gives 422 with the same message, and the store is byte-identical:
   - lookup null
   - inactive
   - id mismatch
   - `find` throws `RuntimeException`
   - `find` throws `Error`
4. Missing section gives 404, and the lookup is never called.
5. Duplicate gives 409. Sixth instructor gives 409 `instructors`. Exactly 5 is accepted.
6. Conflicts:
   - Same term, overlap: 409 `faculty_id`, and the message names the clashing section.
   - Back-to-back: accepted.
   - Same time in another term: accepted.
   - Same time on a different day: accepted.
   - Target or other section with 0 meetings (`tba`): accepted.
   - `tba` with overlapping meetings: 409 (A6).
7. Unassign:
   - 200, and the order of the others is kept.
   - Not assigned gives 404, and a missing section gives 404.
   - Works when the lookup throws or returns null (A7).
   - Appends one `unassigned` entry.
8. Reads:
   - Name resolution through `findMany`.
   - `name: null` when it throws or omits the id.
   - Inactive identity still shows its name.
   - One `findMany` call per list request (the stub counts calls).
   - Section create returns `instructors: []`.
9. Student and faculty actors get 403 on assign and unassign.
10. Section PATCH re-check:
    - Meetings changed to overlap an assigned instructor's other section: 409 `meetings`, store unchanged.
    - Non-overlapping change: accepted.
    - `delivery_mode` only: accepted.
    - Overlap against a section in another term: accepted.

### 9.2 Unit: `SectionDirectoryTest.php` (new)

- `findSection` returns a summary, or null.
- `approvedStudentIds` returns `[]`.
- `isAssigned` true or false, including for an unknown section.
- `sectionIdsForFaculty` ascending.
- `assignedFacultyIds` in order.
- `FacultyIdentity` constructor rejects id 0, an empty name, a 151-char name, and control or bidi characters.

### 9.3 Store: extend `CatalogStoreTest.php`

Each case throws on read **and** write, and the file stays byte-identical:
- `assignments` not a list
- extra or missing row key
- `faculty_id: "3"`
- unknown section
- duplicate pair
- 6 for one section
- a stored conflict
- `assignment_history` not a list
- seq gap
- bad `action`
- `actor_id: 0`
- bad `at`
- `unassigned` without a prior `assigned`
- replay ≠ `assignments` (an assignment without history, and history without an assignment)
- an unknown top-level key (still rejected)

Also: a T-20 store without the new keys loads, and the first assign adds both keys.

### 9.4 HTTP: `AssignmentHttpTest.php` (new), real `public/router.php`

1. The §3.3 matrix over real sessions, with exact bodies for 401, 403 and 404.
2. Missing CSRF on POST and DELETE gives 403 before validation.
3. A non-JSON body gives 415. A DELETE with a JSON field gives 422.
4. `GET /api/sections` and `/{id}` include `instructors` for every role. Seeded assignment → `name: null` (production lookup).
5. DELETE of a seeded assignment: 200, then a second DELETE gives 404.
6. `GET /api/registrar/sections/1/instructors/abc` gives 404. A GET on the DELETE path gives 405 with `Allow: DELETE`.

Update the existing assertions that change by design: `SectionServiceTest` 1 ("no `instructors` key" → `instructors: []`) and any exact-shape assertion in `EnrollmentHttpTest`. Keep "POST section with `instructors` → 422" and add the same check for PATCH section.

### 9.5 Concurrency: extend `EnrollmentConcurrencyTest.php` and `fixtures/enrollment_worker.php`

New worker ops: `assign` and `unassign`, using the stub with ids 10–99 active. 3 rounds each, stderr empty, existing barrier.

| # | Race | Expected |
|---|---|---|
| C6 | 2 workers assign F to two different same-term overlapping sections | Exactly one 201, the other 409 `faculty_id`. F holds one of them. History +1 |
| C7 | A section holds 4 instructors. 3 workers assign distinct faculty | Exactly one ok, the others 409 `instructors`. Count is 5 |
| C8 | 3 workers assign the same (S, F) | One ok, the others 409 duplicate. One history entry |
| C9 | F on S1 (MON 09–10). Worker A assigns F to S2 (MON 10–11). Worker B PATCHes S2 meetings to MON 09:30–10:30 | Exactly one of the two succeeds, never both. The final store passes snapshot validation (no conflict) |
| C10 | 6 workers mixing assign and unassign on distinct pairs | `seq` is 1..n with no gaps. n equals the number of ok results. The replay matches |

### 9.6 Production fail-closed

- **HTTP:**
  1. The registrar creates a term and a section over HTTP.
  2. POST instructors with `faculty_id` 1, 3 and 999 each gives 422 `Unknown or inactive faculty member.`
  3. The store file is byte-identical before and after, and has no `assignments` key.
- **Source scan** (`FacultyIdentityPortTest.php`):
  - Under `app/`, `routes/`, `config/` and `public/`, exactly one class `implements FacultyIdentityLookup`, and it is `UnavailableFacultyIdentity`.
  - `app/bootstrap.php` contains `new UnavailableFacultyIdentity()` and doesn't mention `Stub`.
  - T-30 must update this test when it binds the real lookup.

### 9.7 Mutation checks

Done by Codex, in a scratch copy outside every worktree. Record the results in the implementation report.

| # | Mutation | Must fail |
|---|---|---|
| M9 | Conflict check reads a pre-lock `read()` | C6 |
| M10 | Cap check uses `> 5` (allows 6) | Unit 5 / C7 |
| M11 | Overlap uses `<=` | Back-to-back unit |
| M12 | `updateSection` skips the instructor re-check | Unit 10 / C9 |
| M13 | Ignore `term_id` in the conflict check | Cross-term unit |
| M14 | Treat a lookup `Throwable` as allowed | Unit 3 |
| M15 | `UnavailableFacultyIdentity::find` returns an active identity | §9.6 HTTP |
| M16 | Skip the history append on unassign | Unit 7 / C10 / replay store test |
| M17 | POST instructors guard set to `authenticated` | Matrix |
| M18 | Drop the stored-conflict snapshot check | Store test |
| M19 | Drop `requireRole` in `assignInstructor` | Unit 9 |

## 10. Acceptance criteria

1. The §3.2 endpoints and §3.3 matrix hold over HTTP and at service level.
2. In production wiring, every assign gives 422 and the store is untouched (§9.6).
3. Duplicate gives 409. More than 5 gives 409. Same-term overlap gives 409 and names only the clashing section. Back-to-back, cross-term and no-meeting sections are accepted. All of this also holds under C6–C8.
4. A section PATCH that would create an instructor conflict gives 409 and writes nothing, including under the C9 race.
5. Every successful assign or unassign appends exactly one history entry (actor id, time) in the same transaction. Failures append none. `seq` is gap-free (C10).
6. Only `{section_id, faculty_id}` is stored. Names come from `findMany` at read time, and `null` on failure.
7. A corrupt store (each §9.3 case) gives a generic 500 and is never written. A T-20 store loads unchanged.
8. `SectionDirectory` and `FacultyIdentityLookup` match §6.1 and `API_CONTRACTS.md` §3.
9. `php tests/run.php` is fully green (163 existing tests, with the by-design assertion updates in §9.4, plus the new tests). `php -l` is clean. Apache passes POST and DELETE on both document roots. MySQL mode still fails closed.

## 11. Wiring (Claude, phase 3)

`app/bootstrap.php`, Enrollment block (replaces line 98):

```php
// Team 4: instructor assignments (T-21). Verifies nobody until T-30 binds Team 5's lookup (owner 2026-11-20).
// Never bind a mock here or select one by config/env: mock lookups exist only under tests/.
$container['faculty_identity'] = new UnavailableFacultyIdentity();
$container['section_directory'] = new RepositorySectionDirectory($container['enrollment_catalog']);
$container['section_controller'] = new SectionController(new SectionService(
    $container['enrollment_catalog'], $container['authorization'], $container['faculty_identity']));
```

`routes/enrollment.php`, two lines:

```php
$router->add('POST', '/api/registrar/sections/{id}/instructors', $guard->roles($staff, [$sections, 'assignInstructor']));
$router->add('DELETE', '/api/registrar/sections/{id}/instructors/{facultyId}', $guard->roles($staff, [$sections, 'unassignInstructor']));
```

`README.md`, Enrollment section: the two endpoints, the conflict rule, the 5-instructor cap, the fail-closed production note ("every assignment is refused until T-30"), and the history storage note.

## 12. File ownership

| Phase | File | Action | Owner |
|---|---|---|---|
| 0 | `docs/plans/T-21-instructor-assignments.md` | create | Claude (architect) |
| 1 | `app/Enrollment/Contracts/FacultyIdentity.php` | create | Claude |
| 1 | `app/Enrollment/Contracts/FacultyIdentityLookup.php` | create | Claude |
| 1 | `app/Enrollment/Contracts/UnavailableFacultyIdentity.php` | create | Claude |
| 1 | `app/Enrollment/Contracts/SectionSummary.php` | create | Claude |
| 1 | `app/Enrollment/Contracts/SectionDirectory.php` | create | Claude |
| 2 | `app/Enrollment/Models/Section.php` (`meetings()`, `withInstructors()`) | modify | Codex |
| 2 | `app/Enrollment/Repositories/CatalogRepositoryInterface.php` | modify | Codex |
| 2 | `app/Enrollment/Repositories/MockCatalogRepository.php` | modify | Codex |
| 2 | `app/Enrollment/Services/SectionService.php` | modify | Codex |
| 2 | `app/Enrollment/Services/RepositorySectionDirectory.php` | create | Codex |
| 2 | `app/Enrollment/Controllers/SectionController.php` | modify | Codex |
| 2 | `tests/Enrollment/EnrollmentSupport.php` | modify | Codex |
| 2 | `tests/Enrollment/SectionServiceTest.php` | modify | Codex |
| 2 | `tests/Enrollment/CatalogStoreTest.php` | modify | Codex |
| 2 | `tests/Enrollment/EnrollmentHttpTest.php` | modify | Codex |
| 2 | `tests/Enrollment/EnrollmentConcurrencyTest.php` | modify | Codex |
| 2 | `tests/Enrollment/fixtures/enrollment_worker.php` | modify | Codex |
| 2 | `tests/Enrollment/fixtures/StubFacultyIdentity.php` | create | Codex |
| 2 | `tests/Enrollment/AssignmentServiceTest.php` | create | Codex |
| 2 | `tests/Enrollment/SectionDirectoryTest.php` | create | Codex |
| 2 | `tests/Enrollment/AssignmentHttpTest.php` | create | Codex |
| 2 | `tests/Enrollment/FacultyIdentityPortTest.php` | create | Codex |
| 2 | `ai-coordination/T21_IMPLEMENTATION_REPORT.md` | create | Codex |
| 3 | `app/bootstrap.php` (Enrollment block only) | modify | Claude |
| 3 | `routes/enrollment.php` | modify | Claude |
| 3 | `README.md` (Enrollment section only) | modify | Claude |
| 3 | `app/Students/Contracts/FacultyStudentVisibility.php` (docblock only, §8; needs Q2) | modify | Claude |
| 3 | `ai-coordination/{TASK_BOARD,API_CONTRACTS,INTEGRATION_BLOCKERS,AGENT_TASKS,AGENT_HANDOFF}.md` | modify | Claude |
| 4 | `ai-coordination/AGY_REVIEW_REPORT.md` | modify | AGY (in its own worktree) |

Not touched: `app/Core/**` (A1), `database/**`, `app/Faculty/**`, `routes/faculty.php`, `DenyAllFacultyVisibility.php`.

**Sequence (strictly sequential; no two agents hold the branch at once):**

0. The owner approves the plan, the file list and the §14 defaults.
1. **Claude** (`AIS_SYSTEM`, branch `task/T-21-instructor-assignments` from `8c2b980`):
   - writes the contracts
   - runs `php -l` and the 163 tests
   - commits
2. **Codex** (worktree `..\AIS_CODEX_T21`, branch **`task/T-21-impl`** created from Claude's phase-1 commit):
   - implements and writes all tests
   - runs `php -l`, the full suite and the mutation checks
   - commits and writes the report
   - Expected: the §9.4 HTTP route tests and §9.6 stay **red until phase 3 wiring**. Codex lists them in the report. Everything else is green.
   - Claude makes no commits during this phase.
3. **Claude:**
   - fast-forwards or merges `task/T-21-impl` into the task branch
   - does the wiring, README and docs
   - runs the full suite (all green) and the Apache check
   - reviews the code
   - writes the handoff at the exact commit
   - Failures in Codex-owned files go back to Codex (phase 2 again, new commit).
4. **AGY** (read-only worktree `..\AIS_REVIEW_T21` at the handoff commit) reviews and writes `AGY_REVIEW_REPORT.md`.
5. The owning agent fixes each finding, and AGY re-reviews the new commit.
6. Merge into `main`, by Claude, only with the owner's approval.

**Parallelism:** none across agents. Phase 1 → 2 → 3 is a hard dependency chain (contracts → implementation → wiring). Within phase 2, Codex may write tests alongside the code, since it owns both.

**Runtime and Apache:**
- Each agent sets its own `APP_RUNTIME_PATH` outside all worktrees and outside OneDrive.
- Nobody runs tests or seeders against another agent's runtime.
- Only Claude runs the Apache check (phase 3), one agent at a time. It sends a real `DELETE` with CSRF through both document roots (repository root and `public/`).
- AGY may run Apache only when the owner or Claude has confirmed that no one else is using the ports.

## 13. Risks

| Risk | Mitigation |
|---|---|
| Faculty deactivated between the lookup (pre-lock) and the write (A4) | The window is milliseconds. Deactivation doesn't remove existing assignments anyway. T-30 may add a re-check if needed |
| Stored-conflict validation makes the whole store 500 if a future write path forgets the check | Only two write paths can create conflicts, and both use one helper. M12 and M18 cover it. T-22 adds no meeting writes |
| Section list now does one extra store read and one `findMany` per request | Bounded by `per_page ≤ 100`. Acceptable for development (T-20 risk "growing JSON file") |
| `assignment_history` grows without bound and is replayed on every read | Linear, and fine for development. T-40 moves it to a table |
| Phase-2 HTTP tests are red until phase 3 | Documented and expected. Phase 3 must reach fully green before the handoff |
| A test stub leaking into production | It lives only in `tests/` and isn't autoloadable from `app/`. Covered by the source-scan test and M15 |
| PATCH 409 blocks a legitimate timetable change | The registrar unassigns (audited), patches, then reassigns. The 409 message names the instructor and the section |

No destructive database operations, no schema changes and no deletions of data. The only owner approvals needed are §14 and the plan itself.

**T-40 notes (MySQL, not implemented):**
- Tables:
  - `section_instructors(section_id FK, faculty_id FK, PK(section_id, faculty_id))`
  - `section_instructor_history(seq, at, action ENUM, section_id, faculty_id, actor_id)`
- The cap and conflict checks need `SELECT … FOR UPDATE` on the target section row and on the faculty's assignment rows within the term, in one transaction.
- AUTO_INCREMENT isn't gap-free on rollback. Use a counter row, or relax "gap-free" to "monotonic" with owner approval.
- No `ON DELETE CASCADE`.

## 14. Open questions (owner)

| # | Question | Proposed default (used by this plan) |
|---|---|---|
| Q1 | A `tba` or `online_async` section that does list meetings: does it take part in conflict checks? | **Yes**: the check uses meetings only and ignores the mode (A6). Sections with no meetings never conflict |
| Q2 | Add `app/Students/Contracts/FacultyStudentVisibility.php` (docblock only) to T-21's allowed files? | **Yes**, Claude applies it in phase 3. If not, T-31 applies the §8 text |
| Q3 | Branch layout: Codex on its own `task/T-21-impl` branch, merged by Claude (git can't check out one branch in two worktrees) | **Yes** |
| Q4 | Keep and display existing assignments of faculty who later become inactive? | **Yes**: keep them and show the name. Only new assignments are refused. Staff can unassign |

## 15. Hand-offs

- **T-22:**
  - Implements `approvedStudentIds` in `RepositorySectionDirectory`.
  - Adds `enrollments` to the allowed keys.
  - Must keep the A5 invariant if it adds any meeting-changing path.
- **T-30:**
  - Implements `FacultyIdentityLookup` (it must construct `FacultyIdentity`, which validates).
  - Swaps `$container['faculty_identity']` and updates `FacultyIdentityPortTest`.
  - Provides `findByUserId` (B-11).
- **T-31:** B-07 via `findByUserId`, then `SectionDirectory` (§8). Replaces `DenyAllFacultyVisibility` in bootstrap.
- **Lead doc updates (phase 3):**
  - `TASK_BOARD` T-21: file list per §12, add the 409 conflict criteria.
  - `API_CONTRACTS`: section shape with `instructors`, and the 409 conflict on the instructor and PATCH routes.
  - `INTEGRATION_BLOCKERS`: B-11.
  - `AGENT_TASKS`: T-21 ownership per §12.
