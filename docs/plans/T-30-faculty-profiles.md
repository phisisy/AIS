# T-30 — Faculty profiles and the real `FacultyIdentityLookup` (Team 5) — Plan

- **Status:** **PLAN APPROVED** (owner, 2026-11-20): the plan, the file list (§12) and the Q1–Q11 defaults (§14), with the owner's clarifications in §1.1. **Implementation is NOT yet authorized.** Phase 1 and phase 2, the Codex worktree, merges and pushes all wait for a separate owner authorization.
- **Base:** `main` at `e597a9c` (T-21 merged as `d83e207`; 184 tests green).
- **Branches (proposed):** `task/T-30-faculty-profiles` (Claude, from `e597a9c`) and `task/T-30-impl` (Codex, from Claude's phase-1 commit, in worktree `..\AIS_CODEX_T30`).
- **Patterns to copy:**
  - Team 3 T-10/T-11a: `Student` model with `fromArray` fail-closed, `MockStudentRepository` (one store, uniqueness inside the lock, history in the same transaction), `StudentService` (linked account must be active and hold the role; owner decision 3 retention), `StudentController` allowlists, `RepositoryStudentDirectory`.
  - Team 4 T-21: global gap-free history list with replay check (A10, A11), `$check`-free repository invariants, the fixtures/worker layout.
- **Out of scope:**
  - T-31: rosters, `FacultyStudentVisibility` (B-07 stays `DenyAllFacultyVisibility`), `/api/faculty/classes/{id}/students`.
  - T-32: grades, `GradeReader`, every grade route.
  - T-22: enrollments. Any change to Team 4 code (A4 re-check is not added, §2 F9).
  - A history read endpoint, a DB schema, `database/seeders/dev_faculty.php` (F13). MySQL mode stays fail-closed.

## 1. Owner decisions this plan relies on (binding, already made)

| Source | Decision | Where used |
|---|---|---|
| B-04 | Team 4 owns assignments. Team 5 owns profiles, implements `FacultyIdentityLookup`, keeps no assignment data | §3, §6 |
| T-21 decision 5 | Mock lookups only under `tests/`. Never wired in bootstrap or selected by config/env | §6.3, §9.7 |
| T-21 Q4 / A14 | Retained assignments stay. New ones to inactive faculty get 422. Access needs a confirmed active identity | §4 |
| T-10 decision 3 | Profiles are kept unchanged when the linked account changes | §4.2 |
| B-11 | Mapping user id → `faculty_id` via `FacultyDirectory::findByUserId`; failure denies | §6.1 |
| ARCH §4 | Cross-module access only through the provider's read interface | §6 |

### 1.1 Owner clarifications (2026-11-20, binding)

1. **Employee-number validation is centralized and configurable.**
   - One `EmployeeNumberPolicy` class (`app/Faculty/Services/EmployeeNumberPolicy.php`) copies T-10's `StudentNumberPolicy`.
   - The pattern comes from `FACULTY_EMPLOYEE_NUMBER_PATTERN`: a PCRE body without delimiters or anchors. The default is the placeholder `[A-Z0-9][A-Z0-9-]{2,19}`.
   - Values are always trimmed, uppercased and limited to 1–32 characters of `A-Z 0-9 - /`.
   - An invalid configured pattern fails at startup (generic 500).
   - `FacultyService` validation and the store check (§5.1) both use this one policy; nothing else hard-codes the format. See F3.
2. **Reactivation:**
   - Only registrar/admin may reactivate, via the guarded PATCH with CSRF plus `requireRole` in the service.
   - Reactivation is audited: exactly one history entry with `actor_id`, in the same transaction.
   - Retained assignments grant access again **only while** the profile is active **and** the linked account is active **and** it holds `faculty`. All three are read live by the lookup (F6).
   - Reactivating a profile whose account is inactive, or lacks `faculty`, restores nothing until the account is fixed. Tests: §9.2 case 8 and §9.5 step 6.
3. **The T-21 A4 identity-check race stays an accepted limitation**, with regression coverage: the deterministic §9.2 case 7 and the F-C04 race. Documented in §4.4 and §13.
4. **Staff accounts that also hold `faculty` may hold profiles** (Q9). **T-32 must enforce separation of duties**, for example a user mustn't finalize grades for a section they teach. This is recorded on the task board under T-32.
5. **T-30 concurrency tests are named F-C01 to F-C04.**
6. **The faculty history stays atomic and append-only:** written in the same transaction, gap-free `seq`, replay-validated, never truncated (F11, §5.2).
7. **Task board:** teaching assignments belong to Team 4 (B-04). The T-30 entry is updated accordingly.

## 2. Decisions taken by this plan

| ID | Decision | Why |
|---|---|---|
| F1 | `faculty_id` **is** the profile `id`: int ≥ 1, `max + 1` inside the lock, never reused (there is no delete path). | T-21 already stores it. No reuse means a retained assignment can never attach to a different person. |
| F2 | `user_id` is **unique and immutable**. One profile per account, ever, even after the account is deactivated. | Re-pointing a profile at another account would hand that account every retained assignment (and, from T-32, grade rights). |
| F3 | `employee_number` is **unique and immutable**. Trimmed, uppercased, then validated by the single configurable `EmployeeNumberPolicy` (`FACULTY_EMPLOYEE_NUMBER_PATTERN`, default placeholder `[A-Z0-9][A-Z0-9-]{2,19}`; §1.1-1, Q3). | Same rule as T-10 `student_number`, without the env pattern (nobody asked for it yet). |
| F4 | Stored fields are exactly `id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at`. No email, roles or other Team 2 data is copied. No middle name. | Minimal. Account facts are read live from Team 2. |
| F5 | `first_name` and `last_name` are **1–70** characters (not T-10's 100). `display_name = last_name . ', ' . first_name` (max 142). | `FacultyIdentity` rejects names over 150 characters. With 100 + 100 a valid profile could never be assigned. |
| F6 | **`active === true` iff** profile `status === 'active'` **and** the linked account exists **and** its `status === 'active'` **and** its roles contain `faculty`. Otherwise `active: false` (the name is still returned). Unknown id or id < 1 → `null`. | The recommendation in the brief. Every condition is read live, per call, with no cache (§4.3). |
| F7 | On a corrupt faculty or user store, `find` / `findMany` **throw** (`RuntimeException` from the repository). They catch nothing. On the normal path they never throw. | All T-21 callers already treat a `Throwable` as refused (assign, A8), `name: null` (A9) or deny (A14). DELETE never calls the lookup (A7) and its name read is caught, so DELETE keeps working (tested, §9.2 case 6). Swallowing here would hide corruption from the faculty routes too. |
| F8 | Account deactivation or role loss **changes nothing stored** in Team 5 (T-10 decision 3). | Retention. The live lookup handles access. |
| F9 | **No re-check inside the T-21 assign lock.** The A4 window stays as T-21 accepted it (§4.4). | Closing it needs a Team 4 change and a lookup inside the enrollment lock (the nested-lock risk A4 avoided). A14 already makes an assignment written in the window grant no access. |
| F10 | Profile audit = **own append-only `history` in the faculty store, same transaction**, for `status` and `department` (T-11a pattern). **Team 2's audit log is not used.** | Team 2's audit lives inside `users.json` under its own lock (`UserAdminRepositoryInterface::transaction`). Writing there would give Team 5 write access to Team 2's store, need a second lock, and could not be atomic with the profile change. T-11a and T-21 set the same precedent. Name edits are corrections, not history (T-11a precedent). |
| F11 | `status` changes go through **`PATCH /api/registrar/faculty/{id}`** (field `status`), not a separate `/status` route. | Same as T-10 `academic_status`. One write path. |
| F12 | Reactivation (`inactive → active`) is allowed. It needs no account check at write time, because the lookup checks the account live. | Simple and correct; see the security note in §4.1. |
| F13 | No `database/seeders/dev_faculty.php`, although the task board allows it. | Profiles are created over HTTP in tests and in the Apache probe. Nobody asked for seed data. |
| F14 | `findMany` does **one faculty-store read**, then one `UserRepositoryInterface::findById` per **distinct** linked account. | `UserRepositoryInterface` has no batch read and adding one is a Team 2 change outside T-30. Bounded by the ids on one page (≤ 100 sections × 5). Code comment: `// shortcut: one users read per distinct account; switch to a Team 2 batch read (or a JOIN in T-40) if section lists get slow`. Q8. |
| F15 | `findByUserId` returns the profile **whatever its status**. It is a mapping, not an authorization. | T-31 must still go through `SectionDirectory::isAssigned` / `sectionIdsForFaculty`, which apply A14. Documented on the interface. |
| F16 | `/api/faculty/me` returns the caller's own profile even when the profile is `inactive`. `/api/faculty/classes` then returns `[]` (A14 does that, no extra code). No profile → 404 on both. | The profile holds nothing sensitive. The UI can show "inactive". |
| F17 | Registrar routes live under `/api/registrar/faculty`, not `/api/faculty/{id}`. | Matches `/api/registrar/sections`. Keeps `/api/faculty/*` for the faculty's own routes (no literal/param clash with `me` and `classes`). |
| F18 | Classes are keyed by `findByUserId(session user id)->id`. No route or query parameter ever names a faculty or user id. | ARCH §6 item 3, B-11. Mutation M27. |

## 3. API contract

Existing envelope. Path ids are positive ints (Router). Error messages never echo input. JSON bodies only, unknown fields → 422 `Unknown field.` (controller allowlist via `Request::json`).

### 3.1 Faculty JSON (exact key order)

`{id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at}`. `id` is the `faculty_id`. `status` is `"active"|"inactive"` (profile status only; the account status is not copied).

### 3.2 Endpoints (all new; `routes/faculty.php`)

| Method | Path | Guard | CSRF | Body / query | Success | Errors |
|---|---|---|---|---|---|---|
| GET | `/api/registrar/faculty` | registrar, admin | — | `?search=&status=&page=&per_page=` | 200 `{faculty:[…], pagination:{page, per_page, total}}` | 401, 403, 422 |
| POST | `/api/registrar/faculty` | registrar, admin | ✔ | §3.3 | 201 `"Faculty profile created."` `{faculty}` | 401, 403, 409, 415, 422 |
| GET | `/api/registrar/faculty/{id}` | registrar, admin | — | — | 200 `{faculty}` | 401, 403, 404 |
| PATCH | `/api/registrar/faculty/{id}` | registrar, admin | ✔ | §3.4 | 200 `"Faculty profile updated."` `{faculty}` | 401, 403, 404, 415, 422 |
| GET | `/api/faculty/me` | faculty | — | — | 200 `{faculty}` | 401, 403, 404 |
| GET | `/api/faculty/classes` | faculty | — | `?term_id=` optional | 200 `{classes:[{section_id, term_id, course_code, section_code}]}` ascending `section_id` | 401, 403, 404, 422 |

- 404 body: `{"success":false,"message":"Faculty profile not found.","errors":{}}` for a missing id, and for `me`/`classes` when the caller has no profile.
- `classes`: `sectionIdsForFaculty(faculty_id)`, then `findSection` per id, then the optional `term_id` filter on `SectionSummary::termId`. A null `findSection` is skipped. `term_id` must pass `FILTER_VALIDATE_INT` with min 1, else 422 `{term_id: "Use a positive whole number."}`. No pagination: the list is bounded by one person's assignments. Code comment: `// shortcut: one findSection read per section; add a batch read if a faculty member teaches many sections`.
- List: `search` ≤ 100 chars, UTF-8, case-insensitive substring of `employee_number`, `first_name` or `last_name`. `status` must be `active`/`inactive`. `page` 1–1 000 000 (default 1), `per_page` 1–100 (default 20). Order by `id`. Invalid → 422 per field (T-10 wording).
- **Literal paths:** `/api/registrar/faculty` and `/api/registrar/faculty/{id}` differ in segment count; `me` and `classes` are literal. No router change.

### 3.3 Create validation (all errors collected, then 422; uniqueness 409 inside the lock)

| Field | Required | Rule | Error (422 unless noted) |
|---|---|---|---|
| `user_id` | yes | strict int ≥ 1 (`"12"` rejected). Account (Team 2 `findById`) exists, `active`, roles contain `faculty` | `Provide the id of an existing faculty account.` / `The account must exist, be active and hold the faculty role.` |
| `employee_number` | yes | string; trim, uppercase; `EmployeeNumberPolicy::normalize` (configurable pattern, §1.1-1) | `Use the employee number format.` |
| `first_name`, `last_name` | yes | string; trim; 1–70 chars; valid UTF-8; no `\p{Cc}\p{Cf}` | `Use 1 to 70 characters without control characters.` |
| `department` | yes | same filter, 1–100 chars, free text (Q2) | `Use 1 to 100 characters without control characters.` |
| `status` | no | `active` or `inactive`; default `active` | `Use one of: active, inactive.` |
| any other key (`id`, `created_at`, `faculty_id`, …) | — | controller allowlist | `Unknown field.` |
| (in lock) `user_id` taken | — | — | **409** `This account already has a faculty profile.` |
| (in lock) `employee_number` taken | — | compared after normalization | **409** `This employee number is already in use.` (both 409 errors may appear together) |

Missing fields give `This field is required.` before format checks (T-10 order).

### 3.4 Update validation

- Allowlist: `first_name`, `last_name`, `department`, `status`. Same rules as create.
- `user_id`, `employee_number`, `id` → 422 `Unknown field.` (immutable, F2/F3).
- `{}` → 422 `{body: "Provide at least one field to change."}`.
- Missing id → 404 (checked pre-lock and again in the lock).
- Concurrent PATCHes: last writer wins. Each history entry's `from` is the value **inside the lock** (§7). No 409 on update.
- A no-op value still bumps `updated_at` and appends no history (T-11a).

### 3.5 Authorization matrix (HTTP)

| Caller | `GET/POST /api/registrar/faculty`, `GET/PATCH …/{id}` | `GET /api/faculty/me` | `GET /api/faculty/classes` |
|---|---|---|---|
| Anonymous | 401 | 401 | 401 |
| Inactive account (any role) | 401 (T-01 reload) | 401 | 401 |
| Student | 403 | 403 | 403 |
| Faculty | 403 | 200 own / 404 no profile | 200 own (`[]` if inactive, F16) / 404 no profile |
| Registrar, admin | 200/201 | 403 (unless also holding `faculty`) | 403 (same) |
| Staff, missing/invalid CSRF on POST/PATCH | 403 `Invalid CSRF token.` before validation | — | — |

- Services repeat `requireRole` (defense in depth). Student/faculty actors calling staff service methods directly get 403; a registrar calling `me`/`classes` directly gets 403.
- **"Another faculty's data → 404":** T-30 has no faculty route that takes an id, so there is nothing to probe; ownership is structural (F18). T-31 applies the 404/403 rule to `/classes/{id}/students`.
- No escalation path: Team 5 never writes users or roles. A registrar can link a profile only to an account an admin already gave the `faculty` role.

### 3.6 Changes to `API_CONTRACTS.md` (Claude, phase 3)

- Team 5 table: add the four `/api/registrar/faculty*` rows; `/api/faculty/me` "404 if no profile"; `/api/faculty/classes` "own sections from `SectionDirectory::sectionIdsForFaculty` (active profile + account + `faculty` role), `?term_id=`, 404 if no profile".
- Add the Faculty JSON shape (§3.1).
- §3: add `FacultySummary` fields; note `findByUserId` is a mapping, not authorization (F15); `FacultyIdentityLookup` comment "implemented by `App\Faculty\Services\RepositoryFacultyIdentity` (T-30)".
- Team 4 instructors row: drop "(production: every id until T-30)".
- Grade rows and `/classes/{id}/students` unchanged (T-31/T-32).

## 4. Activation, deactivation and the identity

### 4.1 Profile status

- `active → inactive` (PATCH): one history entry. T-21 assignments are **retained**. From the next lookup on: new assigns → 422 (A8); `isAssigned` / `sectionIdsForFaculty` deny (A14); `/api/faculty/classes` → `[]`; section responses still show the name (A9); DELETE works (A7).
- `inactive → active` (F12): registrar/admin only (guard + CSRF + `requireRole`), with exactly one audited history entry (actor, time). Retained assignments grant access again **only if** the linked account is also active and holds `faculty`, all checked live by the lookup (§1.1-2). When those hold, access returns immediately (A14 is evaluated live). The registrar should review the faculty member's sections before reactivating; there is no automatic unassign (T-21 Q4). Recorded in README and §13.

### 4.2 Team 2 account changes

- Account deactivated: Team 5 stores nothing new (F8). Lookup → `active: false` on the next call. The person's sessions get 401 (T-01 reloads the user per request).
- `faculty` role removed: lookup → `active: false`; `/api/faculty/*` → 403 (guard). Profile kept.
- Role or account restored: access returns with no Team 5 action.

### 4.3 No stale cache

`RepositoryFacultyIdentity` holds no state between calls. Each `find` reads the faculty store and the user store (`JsonStore::read()` under a shared lock). Test: the same lookup instance reflects a user-store change made between two calls (§9.2 case 3).

### 4.4 The T-21 A4 window (residual risk, accepted)

- T-21 runs the lookup **before** the enrollment lock. A deactivation (profile or account) committed after the lookup but before the assignment write lets that one assignment be written.
- Impact: the record exists and is shown on the section. It grants **no access** (A14 re-checks on every access read). New assignments are refused from then on. The registrar can DELETE it.
- Remaining exposure: if the profile is later **reactivated**, that assignment then grants access like any retained one (§4.1).
- What the tests can show: the deterministic case (a test decorator deactivates right after `find` returns, §9.2 case 7) proves the written assignment grants no access. The worker race (F-C04) proves only that every outcome is one of the two allowed ones and the stores stay valid. Neither can prove the window is closed, because it isn't.

### 4.5 After T-30, production instructor assignments become possible

Security implications (README and handoff must state them):
1. Registrar/admin can now bind real faculty to sections. From T-31/T-32 that binding is the authority for rosters and grade entry, so profile creation and `user_id` linking become trust roots (F2, two-party: admin grants the role, registrar links the profile).
2. Instructor display names (`Last, First`) are now shown on every section response to every signed-in role, students included. Department and employee number are not.
3. `faculty_id` is never reused and `user_id` never re-pointed (F1, F2), so retained assignments can't move to another person.
4. Reactivation restores retained access (§4.1). The A4 window remains (§4.4).
5. A staff user who also holds `faculty` can be linked and assigned to sections (Q9, separation of duties).
6. B-07 is unchanged: faculty still see no student data until T-31.
7. Assign refusals keep the single A8 message, so no faculty-id enumeration through Team 4.

## 5. Storage (`$runtime/faculty/faculty.json`)

Top-level keys exactly `["faculty","history"]`. A missing or empty file means no profiles. Writes always store both keys. Any other top-level key → corrupt.

```json
{"faculty":[{"id":1,"user_id":3,"employee_number":"EMP-001","first_name":"Ana","last_name":"Reyes","department":"Computer Science","status":"active","created_at":"2026-11-20T00:00:00Z","updated_at":"2026-11-20T00:00:00Z"}],
 "history":[{"seq":1,"at":"2026-11-20T00:00:00Z","faculty_id":1,"field":"status","from":null,"to":"active","actor_id":2},
            {"seq":2,"at":"2026-11-20T00:00:00Z","faculty_id":1,"field":"department","from":null,"to":"Computer Science","actor_id":2}]}
```

### 5.1 `faculty` validation (fail closed → `RuntimeException('Invalid faculty store.')`, generic 500, nothing written)

- A list. Each row has keys **exactly** §3.1 in that order. `id`, `user_id` strict ints ≥ 1.
- `employee_number`, names, `department`, `status` satisfy the §3.3 rules exactly (already normalized: stored value must equal its own trim/uppercase result).
- `created_at`, `updated_at` match `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z`.
- `id`, `user_id` and `employee_number` are each unique.

### 5.2 `history` validation

- A list. Keys exactly `["seq","at","faculty_id","field","from","to","actor_id"]`. `seq === index + 1` (one global list, T-21 A10).
- `at` format as above. `field` ∈ `status`, `department`. `faculty_id` exists in `faculty`. `actor_id` int ≥ 1.
- `to` is a valid value for the field. `from` is a valid value or `null`; `null` only in the first entry for that `(faculty_id, field)`.
- **Replay (T-21 A11 style):** per `(faculty_id, field)`, each entry's `from` equals the previous entry's `to`, the first entry's `from` is `null`, and the last `to` equals the profile's current value. Every profile has a first entry for both fields.
- Only appended; no update or delete path in code. No names, emails or secrets in history.

## 6. Interfaces

### 6.1 `app/Faculty/Contracts/` (Claude, phase 1)

```php
namespace App\Faculty\Contracts;

/** Read-only Team 5 fact for Teams 3/4 (B-11), in-process only. status is the profile status. */
final class FacultySummary
{
    public function __construct(
        public readonly int $id,               // faculty_id
        public readonly int $userId,
        public readonly string $employeeNumber,
        public readonly string $displayName,   // "Last, First"
        public readonly string $status,        // "active"|"inactive"
    ) {}
}

/** Team 5 → Teams 3/4. A mapping, NOT an authorization: access still goes through
 *  SectionDirectory::isAssigned / sectionIdsForFaculty (A14). Throws on a corrupt store. */
interface FacultyDirectory
{
    /** null when the account has no profile; returns inactive profiles too. */
    public function findByUserId(int $userId): ?FacultySummary;
}
```

### 6.2 Repository (Codex)

```php
namespace App\Faculty\Repositories;

interface FacultyRepositoryInterface
{
    public function findById(int $id): ?Faculty;
    public function findByUserId(int $userId): ?Faculty;
    /** @param list<int> $ids @return array<int, Faculty> keyed by id; unknown ids omitted. One read. */
    public function findByIds(array $ids): array;
    /** @return array{faculty: list<Faculty>, total: int} ordered by id */
    public function search(array $filters, int $page, int $perPage): array;
    /** One lock: 409 user_id / employee_number; appends the two creation history entries. */
    public function create(array $fields, string $now, int $actorId): Faculty;
    /** One lock: 404; appends one entry per changed tracked field. $changes keys ⊆ first_name, last_name, department, status. */
    public function update(int $id, array $changes, string $now, int $actorId): Faculty;
}
```

`MockFacultyRepository` implements it over one `JsonStore` (§5, §7). `App\Faculty\Models\Faculty`: `fromArray` (fail closed, §5.1), `id()`, `userId()`, `employeeNumber()`, `status()`, `displayName()`, `toArray()`.

### 6.3 Services and controller (Codex)

- **`final class RepositoryFacultyIdentity implements FacultyIdentityLookup`** (`app/Faculty/Services/`), constructor `(FacultyRepositoryInterface $faculty, UserRepositoryInterface $users)`. F6, F7, F14. Builds `new FacultyIdentity($f->id(), $f->displayName(), $active)`. `findMany` ignores non-int and < 1 entries and duplicates; `[]` in → `[]` out with no read.
- **`final class RepositoryFacultyDirectory implements FacultyDirectory`** (`app/Faculty/Services/`), constructor `(FacultyRepositoryInterface $faculty)`.
- Two classes rather than one implementing both interfaces: matches `RepositoryStudentDirectory` / `RepositorySectionDirectory`, and keeps the source scan simple.
- **`FacultyService`**, constructor `(FacultyRepositoryInterface $faculty, UserRepositoryInterface $users, AuthorizationService $authorization, SectionDirectory $sections, ?callable $clock = null)`. Methods `list`, `get`, `create`, `update` (STAFF), `me`, `classes` (`['faculty']`). Validation per §3.3–3.4.
- **`FacultyController`**: `index`, `show`, `create` (`json(CREATE_FIELDS)`, 201), `update` (`json(UPDATE_FIELDS)`), `me`, `classes`.
- Dependency direction: Faculty → Enrollment (`SectionDirectory`) and Auth; Enrollment → Faculty only through the port. No cycle.

## 7. Atomicity and concurrency

- Every create and update is one `JsonStore::transaction()` on `faculty.json`. Inside it: validate the snapshot (§5), the uniqueness / existence checks on `$data` (never a pre-lock read, M24), build the row, append history, write.
- The account check on create runs **before** the lock (Team 2 store; no nested locks). An account deactivated in that window gets a profile; harmless, because the lookup checks the account live (F6).
- No nested transactions. The lookup and directory only read.
- Corrupt store → the transaction throws before `writeUnlocked`; file byte-identical; front controller returns the generic 500.

## 8. Integration blockers and dependencies

- **B-11:** resolved on the provider side by `FacultyDirectory::findByUserId` (§6.1). Status in `INTEGRATION_BLOCKERS.md` becomes "Mapping available (T-30); consumer T-31". `DenyAllFacultyVisibility` stays wired; T-31 replaces it.
- **B-03:** no schema; MySQL stays fail-closed. T-40 notes in §13.
- **B-05:** not needed.
- **B-07:** untouched.
- **T-21 (done):** consumed as is; no Team 4 file changes.
- **T-22, T-31, T-32:** nothing beyond the interfaces in §6.1.

## 9. Tests (Codex, `tests/Faculty/*`, offline, `php tests/run.php`)

**Support** `tests/Faculty/FacultySupport.php`: private runtime, seeded users (registrar, admin, two faculty accounts whose user ids differ from their future `faculty_id`s, a student, an inactive faculty, a registrar-only account), repositories, fixed clock, `facultyInput()`, `storeBytes()`, an HTTP server helper reusing `tests/Auth/HttpSupport.php`. Enrollment fixtures come from `tests/Enrollment/EnrollmentSupport.php` (required, not edited).

### 9.1 Unit: `FacultyServiceTest.php`

1. Create: 201 shape and key order exact; two history entries (`status`, `department`, `from: null`) with actor and clock.
2. Required fields, each missing → 422. `user_id` `"12"`, `0`, `-1`, `1.5`, `null` → 422. Account missing, inactive, student-only, registrar-only → the same 422 message.
3. `employee_number`: `" emp-001 "` → `EMP-001`; too short, too long, `EMP 001`, `É01`, leading `-` → 422.
4. Names: empty, spaces only, 71 chars, `\x07`, U+202E, U+200B, invalid UTF-8 → 422; 70+70 chars accepted and `RepositoryFacultyIdentity::find` constructs the identity without throwing.
5. `department`: same, 100 vs 101. `status` invalid → 422; default `active`.
6. 409: same `user_id`; same `employee_number` in another case; both together.
7. Update: `{}` → 422; status change → +1 entry; department change → +1; name change → +0; same status → +0 and `updated_at` bumped; missing id → 404. `user_id`/`employee_number`/`id` → 422 `Unknown field.` at controller level.
8. List: search by number/first/last, case-insensitive; status filter; `page`/`per_page` bounds → 422; order by id.
9. Service-level RBAC: student/faculty → 403 on list/get/create/update; registrar → 403 on `me`/`classes`.
10. `me`: no profile → 404; inactive profile → 200 with `status: inactive`.
11. `classes` (with `RepositorySectionDirectory` over a real catalog store and the real lookup): user 3 ↦ faculty 1; faculty 3 (a different person) is assigned elsewhere and is **not** returned (M27). `term_id` filter; `term_id=abc` → 422; inactive profile → `[]`; no profile → 404; ascending.

### 9.2 Unit: `FacultyIdentityTest.php`

1. `find`: active only when all F6 conditions hold. Profile inactive / account inactive / no `faculty` role / account missing → identity with `active: false` and the name. Unknown, 0, −1 → `null`.
2. `display_name` is `Last, First`.
3. No cache: one instance; deactivate the account in the user store between two calls → second call inactive; restore → active.
4. `findMany`: keyed by id; unknown omitted; duplicates, `"1"`, `0` ignored; `[]` → `[]`. One faculty-repository read (counting decorator in the test).
5. `FacultyDirectory::findByUserId`: summary fields; unknown → null; inactive profile still returned.
6. **Corrupt faculty store with T-21 code:** `find`/`findMany` throw. Through `SectionService`: assign → 422 A8 and enrollment store byte-identical; GET section → `name: null`; **DELETE of a seeded assignment → 200**. `RepositorySectionDirectory` denies.
7. **A4 deterministic:** a test-only decorator (`tests/Faculty/fixtures/DeactivatingFacultyIdentity.php`) returns the real active identity, then sets the profile inactive. Assign → 201 (the accepted window); then `isAssigned`/`sectionIdsForFaculty` deny; a second assign → 422; GET still shows the name.

8. **Reactivation needs all three (§1.1-2):** a profile reactivated while its account is inactive, or lacks `faculty`, still gets `active: false`, `isAssigned` false and `sectionIdsForFaculty` `[]`. Once the account is fixed, it gets `true` and the ids. The reactivation PATCH by a student or faculty → 403, and it writes no history entry.

### 9.3 Store: `FacultyStoreTest.php`

Each case throws on read **and** write, and the file stays byte-identical: unknown top-level key; `faculty` not a list; extra/missing/reordered row key; `id: "1"`; `id: 0`; duplicate `id`; duplicate `user_id`; duplicate `employee_number`; lowercase `employee_number`; control character in a name; 71-char name; bad `status`; bad timestamp; `history` not a list; seq gap; bad `field`; bad `at`; `actor_id: 0`; unknown `faculty_id`; non-null `from` on a first entry; `from` ≠ previous `to`; last `to` ≠ current value; a profile without creation entries. Plus: missing file loads empty; one case over HTTP → generic 500 body.

### 9.4 HTTP: `FacultyHttpTest.php` (real `public/router.php`, real sessions)

1. The §3.5 matrix for all six routes, exact bodies for 401, 403, 404.
2. POST/PATCH without CSRF → 403 before validation. Non-JSON → 415. Unknown field → 422.
3. `GET /api/registrar/faculty/abc` → 404. `PUT /api/registrar/faculty/1` → 405 with `Allow: GET, PATCH` (whatever order the Router emits). `POST /api/faculty/me` → 405.
4. Role removed by admin (`PATCH /api/admin/users/{id}`) → that faculty's next request to `/me` is 403; account deactivated → 401.

### 9.5 Production integration: `FacultyIntegrationHttpTest.php` (real bootstrap, real lookup bound)

1. Registrar creates a term, sections S1 and S2 (non-overlapping), and a profile for user 3 (`faculty_id` 1).
2. Assign 1 to S1 → 201, `instructors: [{faculty_id: 1, name: "Reyes, Ana"}]`.
3. Faculty user 3 logs in: `/me` 200; `/classes` → `[S1]`.
4. Registrar PATCHes status `inactive`: assign to S2 → 422 A8 and enrollment store byte-identical; `/classes` → `[]`; `GET /api/sections/{S1}` still shows the name.
5. DELETE S1/1 → 200; again → 404.
6. Reactivate (registrar PATCH, with CSRF) → exactly one new history entry with the registrar's `actor_id`. Assign to S2 → 201, `/classes` → `[S1, S2]`. A student or faculty PATCH → 403.
7. Admin removes the `faculty` role from user 3 → assign to S1 → 422; restore role → 201.

### 9.6 Concurrency: `FacultyConcurrencyTest.php` + `fixtures/faculty_worker.php`

Existing barrier pattern, 3 rounds each, stderr empty.

| # | Race | Expected |
|---|---|---|
| F-C01 | 3 workers create with the same `employee_number`, distinct accounts | One 201, others 409 `employee_number`. One profile, two history entries |
| F-C02 | 3 workers create for the same `user_id`, distinct numbers | One 201, others 409 `user_id` |
| F-C03 | 6 workers PATCH status/department of one profile | All 200. `seq` 1..n gap-free; replay valid; final values equal the last entries |
| F-C04 | Worker A sets F inactive; worker B assigns F via `SectionService` with the real lookup | Either (B 201 and A 200) or (A 200 and B 422). Both stores pass validation. Afterwards `sectionIdsForFaculty(F) === []` and a new assign → 422. The test cannot force or exclude the A4 interleaving (§4.4) |

### 9.7 Port test (modify `tests/Enrollment/FacultyIdentityPortTest.php`)

- Under `app/`, `routes/`, `config/`, `public/`: the classes implementing `FacultyIdentityLookup` are exactly `['RepositoryFacultyIdentity', 'UnavailableFacultyIdentity']` (sorted). Widen the regex to catch an interface list: `/class\s+(\w+)[^{]*?\bimplements\b[^{]*?\bFacultyIdentityLookup\b/`; any `new class` implementing it fails the test.
- `app/bootstrap.php` contains `new RepositoryFacultyIdentity(`, does **not** contain `new UnavailableFacultyIdentity()`, and no file mentions `Stub` or `DeactivatingFacultyIdentity`.
- Retitle: "production binds only Team 5's lookup".
- `tests/Enrollment/AssignmentHttpTest.php`: **title only** of the "production identity refuses every id" test → "unknown faculty ids are refused without changing the store". Assertions unchanged (still true: empty faculty store).

### 9.8 Mutation checks (Codex, scratch copy outside every worktree; results in the report)

| # | Mutation | Must fail |
|---|---|---|
| M21 | Lookup ignores account `status` | 9.2-1 / 9.5-7 |
| M22 | Lookup ignores the `faculty` role | 9.2-1 / 9.5-7 |
| M23 | Lookup ignores profile `status` | 9.2-1 / 9.5-4 |
| M24 | Uniqueness checks on a pre-lock `read()` | F-C01 / F-C02 |
| M25 | Skip the history append on update | 9.1-7 / F-C03 / replay store test |
| M26 | `employee_number` added to `UPDATE_FIELDS` | 9.1-7 |
| M27 | `classes` uses `$actor->id` as the faculty id | 9.1-11 |
| M28 | `/api/faculty/me` guard set to `authenticated` | 9.4-1 |
| M29 | Bootstrap keeps `UnavailableFacultyIdentity` | 9.5-2 / 9.7 |
| M30 | `findMany` returns `active: true` always | 9.2-4 |
| M31 | Create skips the account role check | 9.1-2 |
| M32 | Snapshot skips the duplicate `user_id` check | 9.3 |
| M33 | Drop `requireRole` in `FacultyService::create` | 9.1-9 |
| M34 | Lookup caches the first answer per id | 9.2-3 |

## 10. Acceptance criteria

1. The §3.2 endpoints and §3.5 matrix hold over HTTP and at service level; exact shapes per §3.1.
2. Validation per §3.3–3.4: strict allowlists, immutable fields → 422, duplicates → 409 (also under F-C01/F-C02), pagination bounds, control/format characters rejected.
3. `FacultyIdentityLookup` reports `active` only per F6, live, with no cache; unknown → null; names always satisfy `FacultyIdentity`.
4. With production wiring: an active profile can be assigned via T-21 HTTP; profile deactivation, account deactivation or role removal blocks new assignments and `/classes`/directory access while keeping the assignment and its name; DELETE works; reactivation restores (§9.5).
5. `findByUserId` maps user id → `faculty_id`; `/classes` never takes an id from the client (M27).
6. Every status/department change appends exactly one history entry with actor and time, in the same transaction; failures append none; `seq` gap-free (F-C03).
7. A corrupt faculty store (each §9.3 case) → generic 500, never written; T-21 assign refuses, names null, DELETE still 200 (§9.2-6).
8. Team 5 stores no assignment data and no Team 2 data beyond `user_id`.
9. `php tests/run.php` fully green: the 184 existing tests (only the §9.7 by-design edits) plus the new ones. `php -l` clean. `apache_check.php` passes on both document roots, and the real-session probe (§11) passes. MySQL mode still fails closed.

## 11. Wiring, docs and Apache (Claude, phase 3)

`app/bootstrap.php`, replacing line 102 (`$container['faculty_identity'] = new UnavailableFacultyIdentity();`) and appending after the section controller:

```php
// Team 5: faculty profiles (T-30). Real identity: profile active + account active + faculty role, read live.
$container['faculty_profiles'] = new MockFacultyRepository(new JsonStore($runtime . '/faculty/faculty.json'));
$container['faculty_directory'] = new RepositoryFacultyDirectory($container['faculty_profiles']);
// Never bind a mock here or select one by config/env: mock lookups exist only under tests/.
$container['faculty_identity'] = new RepositoryFacultyIdentity($container['faculty_profiles'], $container['users']);
// … existing section_directory / section_controller lines unchanged …
$container['faculty_controller'] = new FacultyController(new FacultyService($container['faculty_profiles'],
    $container['users'], $container['authorization'], $container['section_directory']));
```

Update the T-21 comment above it ("Verifies nobody until T-30") and drop the now-unused `use …UnavailableFacultyIdentity;`.

`routes/faculty.php`: the six routes of §3.2 (`$staff = ['registrar','admin']`, `['faculty']` for `me`/`classes`).

`README.md`: Faculty section (endpoints, F6 rule, reactivation note, §4.5 implications); Enrollment section: remove "every assignment is refused until T-30".

Coordination docs: `TASK_BOARD` (T-30 file list and endpoints), `API_CONTRACTS` (§3.6), `INTEGRATION_BLOCKERS` (B-11 status), `AGENT_TASKS` (§12), `AGENT_HANDOFF`.

**Apache (Claude only, one agent at a time):** `php tests/Core/apache_check.php` on both roots, then a one-off real-session probe through private XAMPP Apache on both roots (kept in Claude's scratch folder, not committed, as in T-21, because committing it is a Core-owned test change): anonymous `/api/faculty/me` 401; student 403; faculty without profile 404; registrar POST profile without CSRF 403, with CSRF 201; faculty `/me` 200; assign 201 with the name; `/classes` lists it; PATCH inactive → `/classes` `[]` and assign 422; DELETE 200; PUT 405.

**Employee-number policy (§1.1-1):** bootstrap builds `new EmployeeNumberPolicy(Environment::get('FACULTY_EMPLOYEE_NUMBER_PATTERN'))` once and passes it to `FacultyService` and `MockFacultyRepository`, so the service and the store check share one rule. An invalid pattern fails at startup. `.env.example` gets a commented `# FACULTY_EMPLOYEE_NUMBER_PATTERN=...` line, and the README states that the default is a development placeholder, as it does for `STUDENT_NUMBER_PATTERN`.

## 12. File ownership

| Phase | File | Action | Owner |
|---|---|---|---|
| 0 | `docs/plans/T-30-faculty-profiles.md` | create | Claude (architect) |
| 1 | `app/Faculty/Contracts/FacultyDirectory.php` | create | Claude |
| 1 | `app/Faculty/Contracts/FacultySummary.php` | create | Claude |
| 2 | `app/Faculty/Models/Faculty.php` | create | Codex |
| 2 | `app/Faculty/Repositories/FacultyRepositoryInterface.php` | create | Codex |
| 2 | `app/Faculty/Repositories/MockFacultyRepository.php` | create | Codex |
| 2 | `app/Faculty/Services/FacultyService.php` | create | Codex |
| 2 | `app/Faculty/Services/EmployeeNumberPolicy.php` | create | Codex |
| 2 | `app/Faculty/Services/RepositoryFacultyIdentity.php` | create | Codex |
| 2 | `app/Faculty/Services/RepositoryFacultyDirectory.php` | create | Codex |
| 2 | `app/Faculty/Controllers/FacultyController.php` | create | Codex |
| 2 | `tests/Faculty/FacultySupport.php` | create | Codex |
| 2 | `tests/Faculty/FacultyServiceTest.php` | create | Codex |
| 2 | `tests/Faculty/FacultyIdentityTest.php` | create | Codex |
| 2 | `tests/Faculty/FacultyStoreTest.php` | create | Codex |
| 2 | `tests/Faculty/FacultyHttpTest.php` | create | Codex |
| 2 | `tests/Faculty/FacultyIntegrationHttpTest.php` | create | Codex |
| 2 | `tests/Faculty/FacultyConcurrencyTest.php` | create | Codex |
| 2 | `tests/Faculty/fixtures/faculty_worker.php` | create | Codex |
| 2 | `tests/Faculty/fixtures/DeactivatingFacultyIdentity.php` | create | Codex |
| 2 | `tests/Enrollment/FacultyIdentityPortTest.php` | modify | Codex |
| 2 | `tests/Enrollment/AssignmentHttpTest.php` (one test title only) | modify | Codex |
| 2 | `ai-coordination/T30_IMPLEMENTATION_REPORT.md` | create | Codex |
| 3 | `app/bootstrap.php` (Faculty block + identity binding) | modify | Claude |
| 3 | `routes/faculty.php` | modify | Claude |
| 3 | `.env.example` (`FACULTY_EMPLOYEE_NUMBER_PATTERN` line only) | modify | Claude |
| 3 | `README.md` (Faculty and Enrollment sections) | modify | Claude |
| 3 | `ai-coordination/{TASK_BOARD,API_CONTRACTS,INTEGRATION_BLOCKERS,AGENT_TASKS,AGENT_HANDOFF}.md` | modify | Claude |
| 4 | `ai-coordination/AGY_REVIEW_REPORT.md` (or `CODEX_REVIEW_REPORT.md`, fallback) | modify | Independent reviewer |

Not touched: `app/Core/**`, `app/Auth/**`, `app/Students/**` (incl. `DenyAllFacultyVisibility.php`), `app/Enrollment/**`, `tests/Enrollment/` other than the two files above, `database/**`.

**Sequence (strictly sequential; one agent holds the branch at a time):**

0. ✔ The owner approved the plan, §12 and §14 (2026-11-20). **Phases 1–6 need separate owner implementation authorization.**
1. **Claude** (`AIS_SYSTEM`, `task/T-30-faculty-profiles` from `e597a9c`): contracts, `php -l`, 184 tests, commit. Creates `..\AIS_CODEX_T30` on `task/T-30-impl` from that commit (with owner approval).
2. **Codex** (`..\AIS_CODEX_T30`): implements, writes all tests, runs `php -l`, the suite and the mutation checks, writes the report. **Codex runs no git write commands** (sandbox limit). Expected red until phase 3: §9.4, §9.5 and the §9.7 port test (they need the routes and binding); the report lists them. Claude makes no commits and edits none of Codex's files meanwhile.
   - **Commit on Codex's behalf (Claude):** confirm Codex has exited; `git -C ..\AIS_CODEX_T30 status` and `diff --name-only` match the phase-2 rows exactly (anything else goes back to Codex); review; run the suite with Claude's own runtime; commit **unedited** on `task/T-30-impl` with "Codex changes, committed by Claude (sandbox git limit)".
3. **Claude:** merge `task/T-30-impl` into the task branch; wiring, routes, README, docs; full suite green; lint; Apache check and probe (§11); code review; handoff at the exact commit.
4. **Independent review:** AGY in read-only `..\AIS_REVIEW_T30` at the handoff commit → `AGY_REVIEW_REPORT.md`. If AGY is unavailable, a **fresh Codex session** → `CODEX_REVIEW_REPORT.md`, which must state the independence caveat (Codex implemented phase 2); the owner decides if that suffices.
5. Fixes by the file's owner (Codex files: phase 2 again, committed by Claude as above); re-review of the new commit.
6. Merge into `main` by Claude, only with owner approval.

**Parallelism:** none across agents (contracts → implementation → wiring is a hard chain). Inside phase 2 Codex may write code and tests together; inside phase 3 everything is Claude's.

**Runtime:** each agent sets its own `APP_RUNTIME_PATH` outside all worktrees and outside OneDrive. Nobody runs tests against another agent's runtime. Only Claude runs Apache, unless the owner or Claude clears the ports for the reviewer.

## 13. Risks

| Risk | Mitigation |
|---|---|
| A4 window: assignment written for faculty deactivated milliseconds earlier | No access granted (A14), visible on the section, DELETE-able. Documented (§4.4), tested deterministically (§9.2-7) |
| Reactivation silently restores access to retained sections | README and handoff note; registrar reviews before reactivating (§4.1, Q6) |
| Section lists now read `faculty.json` plus one `users.json` read per distinct instructor | Bounded by `per_page ≤ 100` × 5; shortcut comment (F14); T-40 JOIN |
| Wrong `user_id` linked at creation | Requires an active `faculty`-role account (admin-granted); `user_id` immutable, so a mistake is fixed by deactivating that profile (and a new account), never by re-pointing |
| Port test regex misses an implementation | Widened regex plus `new class` check (§9.7); M29 |
| History grows unbounded and is replayed on every read | Only staff status/department edits write it; linear; T-40 table |
| Phase-2 HTTP and port tests red until wiring | Expected; phase 3 must be fully green before handoff |

No destructive database operations, no schema changes, no data deletion. Approvals needed: this plan and §14.

**T-40 notes (MySQL, not implemented):** `faculty(id PK, user_id UNIQUE FK users, employee_number UNIQUE, first_name, last_name, department, status ENUM, created_at, updated_at)`; `faculty_history(seq, at, faculty_id FK, field ENUM, from, to, actor_id)`. No `ON DELETE CASCADE`. Uniqueness by unique indexes in the create transaction. `findMany` becomes one query joining `users`/`user_roles`. Gap-free `seq` needs a counter row (same note as T-21).

## 14. Owner questions: all decided (2026-11-20, the defaults below plus §1.1)

| # | Question | Proposed default |
|---|---|---|
| Q1 | Must the account hold `faculty` (and be active) at profile creation? Profiles for accounts that aren't faculty yet? | **Yes, required; no** pre-creation (T-10 parity) |
| Q2 | Department: free text or catalog? | Free text, 1–100 chars. A catalog later if asked |
| Q3 | Employee number format | **Decided:** centralized and configurable now (`EmployeeNumberPolicy`, `FACULTY_EMPLOYEE_NUMBER_PATTERN`). Default placeholder `[A-Z0-9][A-Z0-9-]{2,19}` (§1.1-1) |
| Q4 | Faculty-visible fields on `/me` | The full §3.1 profile (nothing sensitive in it) |
| Q5 | Bound on profile history; history read endpoint | No cap (T-11a D4 parity); no read endpoint in T-30 |
| Q6 | Allow reactivation? | **Decided:** yes, registrar/admin only and audited. Retained access returns only while the profile, the account and the `faculty` role are all active. No auto-unassign (§1.1-2) |
| Q7 | Close the A4 window with an in-lock re-check in T-21? | No (Team 4 change, nested-lock risk; A14 covers access) |
| Q8 | Add a Team 2 batch read (`findByIds`) for `findMany`? | No; per-distinct-account `findById` with a shortcut comment |
| Q9 | May a registrar/admin who also holds `faculty` get a profile and be assigned? | **Decided:** yes. **T-32 must enforce separation of duties** (recorded on the task board) |
| Q10 | Display name format | `Last, First` (matches `StudentSummary`) |
| Q11 | Reviewer | AGY; fallback fresh Codex session with the independence caveat |

## 15. Hand-offs

- **T-31:** `findByUserId(session user id)` → `faculty_id` → `SectionDirectory::isAssigned` / `sectionIdsForFaculty`; null or any `Throwable` → deny. Replaces `DenyAllFacultyVisibility` and adds `/classes/{id}/students` to `FacultyController`.
- **T-32:** grades key on `faculty_id` from the same mapping; Q9 decides separation of duties.
- **T-40:** §13 notes.
