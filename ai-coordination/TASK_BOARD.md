# Task Board — AIS Backend

Statuses: `PROPOSED` · `APPROVED` · `IN_PROGRESS` · `READY_FOR_REVIEW` · `CHANGES_REQUESTED` · `VERIFIED` · `BLOCKED`

Rules: only the project owner sets `APPROVED`. Codex works only on `APPROVED` tasks, edits only the listed
files, and only one `IN_PROGRESS` task may touch a given file. Tests are offline (`php tests/run.php`, mock repositories) unless stated otherwise.
All tasks inherit the security checklist in `ARCHITECTURE.md` §6.

## Summary (priority order)

| ID | Team | Title | Depends on | Status |
|---|---|---|---|---|
| T-00 | Shared | Core HTTP skeleton + test runner | — (B-01, B-02 resolved) | VERIFIED |
| T-01 | 2 | Authentication & sessions | T-00 ✔, B-06 ✔ | VERIFIED |
| T-02 | 2 | RBAC middleware & authorization service | T-01 ✔ | VERIFIED |
| T-03 | 2 | User administration + audit logging | T-02 ✔ | VERIFIED |
| T-10 | 3 | Student profiles | T-02 | PROPOSED |
| T-11 | 3 | Academic records + StudentDirectory | T-10, B-07 | PROPOSED |
| T-20 | 4 | Terms & sections catalog | T-02 | PROPOSED |
| T-21 | 4 | SectionDirectory + InstructorLookup interface | T-20, B-04 | PROPOSED |
| T-22 | 4 | Enrollment requests, approval & schedule | T-11, T-21 | PROPOSED |
| T-30 | 5 | Faculty profiles & teaching assignments | T-21, B-04 | PROPOSED |
| T-31 | 5 | Class rosters | T-22, T-30 | PROPOSED |
| T-32 | 5 | Grade entry, submission, finalization | T-31, B-05 | PROPOSED |
| T-39 | All | Cross-module integration tests | T-32 | PROPOSED |
| T-40 | 2–5 | MySQL repositories | B-03 | BLOCKED |

Teams 3, 4 and 5 can run **in parallel only where their files don't overlap**. They do overlap on
`app/bootstrap.php`, so bootstrap/route registration edits must happen one task at a time.

---

### T-00 — Core HTTP skeleton + test runner
- **Team:** Shared (Team 2 lead)
- **Description:** Front controller, autoloader, container bootstrap, Request/Response/Router/HttpException, `.env` loader, PDO factory (not used yet), JSON file store with locking, plain-PHP test runner.
- **Files allowed to change:** `app/autoload.php`, `app/bootstrap.php`, `app/Core/*.php`, `config/app.php`, `config/database.php`, `public/index.php`, `public/router.php`, `public/.htaccess`, `.htaccess`, `.env.example`, `.gitignore`, `routes/` (empty registration files), `tests/run.php`, `tests/Support.php`, `tests/Core/*`, `README.md`
- **Dependencies:** none (B-01, B-02 resolved)
- **Branch:** `task/T-00-core`, created and checked out by Claude. Codex edits files only and does not run git write commands (see PROJECT_CONTEXT "Git workflow").
- **Acceptance criteria:**
  1. `GET /` returns the JSON success envelope. Unknown path → 404, wrong method → 405 with `Allow`.
  2. Non-JSON body → 415, malformed JSON → 400, body over 64 KB → 413.
  3. Uncaught exception → 500 `"An internal error occurred."` with no message or trace leaked.
  4. Router matches `{param}` as positive integers only. Literal routes win over param routes.
  5. `APP_REPOSITORY` other than `mock`/`mysql` → fail closed. `.env` is git-ignored.
  6. Runs under XAMPP Apache (`.htaccess`) and `php -S 127.0.0.1:8000 public/router.php`.
- **Required tests:** router (match, 404, 405, param validation), request parsing (415/400/413), response envelope, error handler hides internals, JsonStore concurrent write safety, config fail-closed. `php -l` on all files.
- **Status:** VERIFIED (Claude, 2026-11-17; see CLAUDE_REVIEW.md. Apache check passes when run outside Codex's sandbox)

### T-01 — Authentication & sessions
- **Team:** 2
- **Description:** User model, `UserRepositoryInterface` + mock repository, Session wrapper, AuthService (login/logout/requireUser), login rate limiter, CSRF token, `/api/auth/*` routes, dev seeder with fictional accounts.
- **Files allowed to change:** `app/Auth/**`, `routes/auth.php`, `app/bootstrap.php` (Auth wiring only), `database/seeders/dev_users.php`, `tests/Auth/*`. For criterion 7 only: `app/Core/JsonStore.php` (bounded `rename()` retry), `config/app.php` (`APP_ENV` validation), `.env.example`, `README.md`, `tests/Core/StorageTest.php`, `tests/Core/ConfigTest.php`
- **Dependencies:** T-00 (VERIFIED, merged), B-06 (resolved: email)
- **Branch:** `task/T-01-auth`, created from `main` at `c2a5604` and checked out by Claude. Codex runs no git write commands.
- **Acceptance criteria:**
  1. Contract endpoints `csrf`, `login`, `logout`, `me` behave per `API_CONTRACTS.md`.
  2. The same 401 body for unknown email, wrong password, inactive account, and bad types. Dummy hash verification for unknown users.
  3. Session ID regenerated on login. HttpOnly, SameSite=Lax, strict mode. Idle (30 min) and absolute (8 h) timeouts.
  4. `requireUser()` reloads the user every request and rejects inactive users or changed `auth_version`.
  5. Rate limit: 5 per IP+email and 30 per IP per 15 min → 429 + `Retry-After`.
  6. Responses never contain password hashes or session IDs.
  7. (From T-00 review) `APP_ENV` is validated and unknown values fail closed. Session and JSON store files work when the workspace is on OneDrive: use a bounded `rename()` retry, or document `APP_RUNTIME_PATH` outside OneDrive in README/.env.example.
- **Required tests:** login success/failure matrix, CSRF missing/invalid, session fixation (ID changes), timeouts, rate limit, deactivated user mid-session → 401, HTTP-level login→me→logout flow. All 30 T-00 tests must still pass.
- **Status:** VERIFIED (Claude, 2026-11-17; implemented under takeover, see CLAUDE_REVIEW.md)

### T-02 — RBAC middleware & authorization service
- **Team:** 2
- **Description:** Route guard (authentication → role → CSRF, in that order), `AuthorizationService::requireRole`, ownership helper for module services. Multi-role users, no hierarchy.
- **Files allowed to change:** `app/Auth/Middleware/*`, `app/Auth/Services/AuthorizationService.php`, `app/bootstrap.php` (authorization/guard wiring only), `tests/Auth/*` (new `Authorization*` tests, test-only fixtures, and a backward-compatible extension of `HttpSupport.php`), `README.md`, ai-coordination docs
- **Dependencies:** T-01 (VERIFIED, merged `c434a27`)
- **Branch:** `task/T-02-rbac` from `main` at `c434a27`
- **Acceptance criteria (owner requirements 2026-11-17):**
  1–4. Student, faculty, registrar and admin each reach exactly the routes whose allowlist names their role.
  5. Multi-role users get the union of their roles. No hierarchy (admin is not a superuser).
  6. Routes are protected server-side by the guard. Deny by default: an empty or unknown-only allowlist grants nobody; a typo'd allowlist fails at registration.
  7. Anonymous → 401, wrong role → 403, missing/invalid CSRF on a state-changing method → 403 (checked only after auth and role).
  8. Inactive users → 401, including on direct `requireRole` calls.
  9. Role changes take effect on the next request in the same session, both demotion and promotion. Deactivation destroys the session.
  10. No privilege escalation: client role headers, query or body are ignored. Unknown stored roles fail closed (generic 500). Ownership helper supports horizontal checks.
  Errors reveal no role names or internals.
- **Required tests:** role matrix (5 users × 6 allowlists over HTTP), multi-role, unknown roles (allowlist and stored), inactive, guard order, CSRF, role-change consistency, escalation attempts, ownership helper. All 49 existing tests still pass.
- **Status:** VERIFIED (Claude, 2026-11-17; see CLAUDE_REVIEW.md). Not merged; awaiting owner approval

### T-03 — User administration + audit logging
- **Team:** 2
- **Description:** Admin dashboard, user list/search/detail/create/update/status/reset-password, audit log write + read-only list endpoint.
- **Files allowed to change:** `app/Auth/Controllers/{Admin,User,AuditLog}Controller.php`, `app/Auth/Services/{User,Audit}Service.php`, `app/Auth/Repositories/*` (audit additions), `routes/auth.php`, `tests/Auth/Admin*`, `tests/Auth/Audit*`
- **Dependencies:** T-02
- **Acceptance criteria:**
  1. All `/api/admin/*` endpoints admin-only, with CSRF on writes.
  2. Unknown fields → 422. Duplicate email → 409. Password 12–72 bytes.
  3. Last active admin can't be deactivated or demoted → 409. Mock repository serializes writes, so this is race-safe.
  4. Password reset requires the admin's current password and bumps the target's `auth_version`, so their existing sessions are rejected.
  5. Every successful mutation writes exactly one audit entry in the same transaction (actor, action, target, safe before/after). No secrets.
  6. Audit list: admin-only, paginated, read-only.
- **Required tests:** each endpoint × (admin, non-admin, anonymous), validation cases, last-admin, reset invalidates target session, audit written on success and absent on failure, audit has no secrets.
- **Owner scope (2026-11-17):** owner list A1–A10, B, C, D and E supersede the dashboard and audit-list endpoint; both are deferred (no unnecessary endpoints). Branch `task/T-03-admin` from `main` at `96052c2`. Extra files: `app/bootstrap.php` (wiring), `tests/Auth/fixtures/admin_worker.php`, `tests/Auth/AdminSupport.php`, `README.md`.
- **Status:** VERIFIED (Claude, 2026-11-17; see `T03_IMPLEMENTATION_REPORT.md`). Committed on `task/T-03-admin` only; awaiting owner review. Not merged.

### T-10 — Student profiles
- **Team:** 3
- **Description:** Student model, repository interface + mock, StudentService, `/api/students` list/create/me/show/update.
- **Files allowed to change:** `app/Students/**` (excluding `Contracts/`), `routes/students.php`, `app/bootstrap.php` (Students wiring only), `database/seeders/dev_students.php`, `tests/Students/*`
- **Dependencies:** T-02
- **Acceptance criteria:**
  1. Registrar/admin can create, list, search and update. Unique `student_number` (409). One profile per `user_id` (409).
  2. Linked account must exist, be active and have role `student`. Checked via the Team 2 repository read method, not by writing users.
  3. `student_number` and `user_id` can't be changed by PATCH (422).
  4. Student: `/me` returns own profile, `/{id}` of another student → 404 (no ID probing).
  5. Faculty access per B-07 (until T-30 exists, faculty → 403).
- **Required tests:** CRUD happy paths, validation (year level 1–6, status enum, required names), duplicates, ownership (student A reads B → 404), role matrix.
- **Status:** PROPOSED

### T-11 — Academic records + StudentDirectory
- **Team:** 3
- **Description:** Academic record read endpoint, programs list (mock), and the `StudentDirectory` interface + implementation for other modules.
- **Files allowed to change:** `app/Students/**`, `routes/students.php`, `tests/Students/*`
- **Dependencies:** T-10, B-07
- **Acceptance criteria:** `/api/students/{id}/academic-record` respects the same ownership rules. It contains no grades or enrollment copies. `StudentDirectory` returns summaries only (no private fields beyond the contract).
- **Required tests:** academic record ownership, StudentDirectory unit tests (found, not found, by user ID).
- **Status:** PROPOSED

### T-20 — Terms & sections catalog
- **Team:** 4
- **Description:** Term and Section models (with meetings), repositories + mock, `/api/terms`, `/api/sections`, registrar section create/update.
- **Files allowed to change:** `app/Enrollment/**` (excluding `Contracts/`), `routes/enrollment.php`, `app/bootstrap.php` (Enrollment wiring only), `database/seeders/dev_sections.php`, `tests/Enrollment/*`
- **Dependencies:** T-02
- **Acceptance criteria:** Filter by term, course or search. Remaining slots computed from approved count. Meetings validated (day enum, `start < end`, HH:MM). A section's own meetings must not overlap. Capacity ≥ 1 and not below approved count (409). Registrar/admin only for writes.
- **Required tests:** listing/filtering, meeting validation, capacity rules, role matrix.
- **Status:** PROPOSED

### T-21 — SectionDirectory + InstructorLookup interface
- **Team:** 4
- **Description:** Define `SectionDirectory` (implemented by Team 4) and `InstructorLookup` (interface only, with a null implementation that returns `[]` until T-30).
- **Files allowed to change:** `app/Enrollment/Contracts/*`, `app/Enrollment/Services/SectionService.php`, `tests/Enrollment/*`
- **Dependencies:** T-20, B-04
- **Acceptance criteria:** Interfaces match `API_CONTRACTS.md` §3. Sections show `instructors: []` until Team 5 is wired.
- **Required tests:** directory unit tests. Section JSON includes `instructors`.
- **Status:** PROPOSED

### T-22 — Enrollment requests, approval & schedule
- **Team:** 4
- **Description:** Enrollment model, repository + mock, EnrollmentService (rules 1–6), student and registrar endpoints, `/api/schedules/me`.
- **Files allowed to change:** `app/Enrollment/**` (excluding `Contracts/`), `routes/enrollment.php`, `tests/Enrollment/*`
- **Dependencies:** T-11, T-21
- **Acceptance criteria:**
  1. Student enrolls with only `{section_id}`. Student identity comes from the session via `StudentDirectory::findByUserId`. Any client `student_id` → 422.
  2. Duplicate (409), full section (409), schedule clash (409, names the clashing section), closed term (409), ineligible status (409).
  3. Capacity is checked again on approval, inside the same transaction. Two concurrent approvals for the last seat → one wins.
  4. Only `pending` can change. Only registrar/admin can approve or reject.
  5. Schedule shows approved enrollments for the term with day, time, room, course and instructor.
- **Required tests:** each rule, the approval race (two processes against the mock store), ownership (student can't see others' enrollments), role matrix, schedule output.
- **Status:** PROPOSED

### T-30 — Faculty profiles & teaching assignments
- **Team:** 5
- **Description:** Faculty and TeachingAssignment models, repositories + mock, `/api/faculty/me`, `/api/faculty/classes`, registrar assignment endpoint, `FacultyDirectory`, and the real `InstructorLookup` implementation.
- **Files allowed to change:** `app/Faculty/**`, `routes/faculty.php`, `app/bootstrap.php` (Faculty wiring + swap the InstructorLookup binding), `database/seeders/dev_faculty.php`, `tests/Faculty/*`
- **Dependencies:** T-21, B-04
- **Acceptance criteria:** Faculty sees only own profile and classes. Assignments need an existing faculty profile and section. Duplicate assignment → 409. Sections now show instructors.
- **Required tests:** ownership, role matrix, duplicate assignment, InstructorLookup output.
- **Status:** PROPOSED

### T-31 — Class rosters
- **Team:** 5
- **Description:** `/api/faculty/classes/{id}/students`, built from `SectionDirectory::approvedStudentIds` and `StudentDirectory`.
- **Files allowed to change:** `app/Faculty/**`, `routes/faculty.php`, `tests/Faculty/*`
- **Dependencies:** T-22, T-30
- **Acceptance criteria:** Unassigned faculty → 403. Only approved enrollments appear. Search by name/number. Summary fields only.
- **Required tests:** assigned vs unassigned faculty, pending/rejected excluded, search.
- **Status:** PROPOSED

### T-32 — Grade entry, submission, finalization
- **Team:** 5
- **Description:** Grade model, repository + mock, GradeService, faculty grade endpoints, registrar review/finalize, `/api/grades/me`, `GradeReader`.
- **Files allowed to change:** `app/Faculty/**`, `routes/faculty.php`, `tests/Faculty/*`
- **Dependencies:** T-31, B-05
- **Acceptance criteria:**
  1. Faculty can grade only students on their own roster. Value validated against the approved scale.
  2. Only `draft` grades can be edited. Submit requires every roster student graded, then the whole section goes to `submitted` atomically.
  3. Registrar/admin finalize submitted sections. No edits after `submitted`.
  4. `/api/grades/me` returns only own `finalized` grades. Draft or submitted never reaches students.
  5. Every grade change is audited via Team 2's audit service (actor, grade ID, old/new value).
- **Required tests:** grade privacy (student never sees draft/submitted, never sees others'), unassigned faculty → 403, invalid values, state transitions, incomplete roster blocks submit, audit entries.
- **Status:** PROPOSED

### T-39 — Cross-module integration tests
- **Team:** All (QA)
- **Description:** End-to-end HTTP scenario: admin creates accounts → registrar creates student, faculty, section and assignment → student enrolls → registrar approves → faculty grades and submits → registrar finalizes → student sees the grade. Plus negative cross-module access checks.
- **Files allowed to change:** `tests/Integration/*`
- **Dependencies:** T-32
- **Acceptance criteria:** Full scenario passes over HTTP with mock repositories. Every "wrong role / wrong owner" variant returns 401/403/404 as specified.
- **Required tests:** the scenario itself.
- **Status:** PROPOSED

### T-40 — MySQL repositories
- **Team:** 2–5 (each its own module)
- **Description:** PDO implementations of every repository interface against Team 6's approved schema. Tests run only against local `ais_test`.
- **Files allowed to change:** `app/*/Repositories/MySQL*.php`, `tests/*/MySQL*`, `config/database.php`
- **Dependencies:** B-03 (Team 6 schema approval)
- **Acceptance criteria:** The same contract tests pass for mock and MySQL. Prepared statements only. Transactions with row locks for last-admin, capacity and grade submission. The test harness refuses any database other than `ais_test`. No schema DDL in application code.
- **Required tests:** shared repository contract tests run against both implementations.
- **Status:** BLOCKED (B-03)
