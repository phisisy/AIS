# Task Board — AIS Backend

Statuses: `PROPOSED` · `APPROVED` · `IN_PROGRESS` · `READY_FOR_REVIEW` · `READY_FOR_AGY_REVIEW` · `READY_FOR_CODEX_REVIEW` · `IN_REVIEW` · `CHANGES_REQUESTED` · `VERIFIED` · `BLOCKED`

Rules: only the project owner sets `APPROVED`. Codex works only on `APPROVED` tasks, edits only the listed
files, and only one `IN_PROGRESS` task may touch a given file. Tests are offline (`php tests/run.php`, mock repositories) unless stated otherwise.
All tasks inherit the security checklist in `ARCHITECTURE.md` §6.

## Summary (priority order)

| ID | Team | Title | Depends on | Status |
|---|---|---|---|---|
| T-00 | Shared | Core HTTP skeleton + test runner | — (B-01, B-02 resolved) | VERIFIED |
| T-01 | 2 | Authentication & sessions | T-00 ✔, B-06 ✔ | VERIFIED |
| T-02 | 2 | RBAC middleware & authorization service | T-01 ✔ | VERIFIED |
| T-03 | 2 | User administration + audit logging | T-02 ✔ | VERIFIED (merged `8451cd4`) |
| T-04 | 2 | Security-event logging for refused admin-route access | T-03 ✔ | VERIFIED (merged `c280aa7`) |
| T-05 | 2 | Read-only audit-log endpoint | T-03 ✔ | PROPOSED (planning approved; not to be built yet) |
| T-10 | 3 | Student profiles | T-02 ✔, B-07 ✔ | VERIFIED (merged `07b4c83`; see `T10_IMPLEMENTATION_REPORT.md`) |
| T-11a | 3 | Academic records + StudentDirectory | T-10 ✔ | VERIFIED (merged `d062a5e`; implementation `0b91668`; see `T11A_IMPLEMENTATION_REPORT.md`) |
| T-11b | 3 | Course catalog, curriculum, real enrollment/grade summaries | T-11a, D1, T-22, T-32 | PROPOSED (not approved) |
| T-06 | 2 | Protected admin registry + owner-only recovery CLI | T-03 ✔ | PROPOSED (design conditionally approved; `PROPOSAL_ADMIN_PROTECTION_RECOVERY.md` v2; implementation not approved) |
| T-20 | 4 | Terms & sections catalog | T-02 ✔ | **MERGED** into `main` as `8c2b980` (2026-11-20). AGY APPROVE on `81097d5`, Codex APPROVE on `60ac236`; 163/163 tests, 104/104 lint, Apache PASS on `main`. Not pushed. See `T20_IMPLEMENTATION_REPORT.md` §13 |
| T-21 | 4 | SectionDirectory, faculty identity port, instructor assignments | T-20 ✔, B-04 ✔ | **MERGED** into `main` as `d83e207` (2026-11-20). Re-review #2 APPROVE on `417ba6a`; 184/184 tests, 115/115 lint, Apache PASS on `main`. Not pushed |
| T-22 | 4 | Enrollment requests, approval & schedule | T-11, T-21 | PROPOSED |
| T-30 | 5 | Faculty profiles + FacultyIdentityLookup (teaching assignments belong to Team 4, T-21) | T-21 ✔, B-04 ✔ | PLAN APPROVED (owner 2026-11-20; `docs/plans/T-30-faculty-profiles.md`); implementation not authorized |
| T-31 | 5 | Class rosters | T-22, T-30 | PROPOSED |
| T-32 | 5 | Grade entry, submission, finalization | T-31, B-05 | PROPOSED |
| T-39 | All | Cross-module integration tests | T-32 | PROPOSED |
| T-40 | 2–5 | MySQL repositories | B-03 → T-60 | Folded into T-60 Phases 6–7 (file list amended, OQ-14) |
| T-60 | 6 | MariaDB foundation: schema, migrations, tools, MySQL adapters (ais_test only) | T-21 ✔ | IN_PROGRESS. Phase 5 verified. Phase 6a auth (`2c158c2`) and 6b students (Codex commit) verified online on ais_test (224/224). Faculty waits for T-30; enrollment waits for T-22; 6c catalog (T-20/T-21 data) awaits owner ruling. No wiring, no cutover, no merge |

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
- **Status:** VERIFIED (Claude, 2026-11-17; see `T03_IMPLEMENTATION_REPORT.md`). Owner decision 2 applied (`0253cc0`: admin-to-admin resets blocked). **Merged into `main` as `8451cd4`** after the owner's 7 pre-merge checks; 84/84 tests on `main`.
- **Deferred (owner decision 4):** admin dashboard endpoint, to be coordinated with Team 1.

### T-04 — Security-event logging for refused admin-route access (PROPOSED)
- **Team:** 2
- **Description:** When the route guard refuses a request to an admin endpoint (401 anonymous, 403 wrong role, 403 CSRF), record a security event. Authentication and authorization behaviour stays unchanged.
- **Owner requirements (decision 3):** record timestamp, route identifier (method + route pattern, not the raw URL), outcome, and a safe actor identifier when available (user id). Never record passwords, tokens, session IDs or request bodies. Bounded growth (e.g. fixed-size ring or rotation). Prevent log injection (structured JSON fields only, no raw client strings). No leakage in responses. A logging failure must never turn a refusal into an allow.
- **Files changed:** `app/Auth/Services/SecurityEventLog.php` (new), `app/Auth/Middleware/RouteGuard.php`, `app/Core/JsonStore.php` (opt-in non-blocking transaction; default unchanged), `app/bootstrap.php`, `routes/auth.php`, `tests/Auth/SecurityEvent*Test.php`, `README.md`.
- **Status:** VERIFIED (Claude, 2026-11-17; see CLAUDE_REVIEW.md). Re-verification Low fixed in `17d58dc`. **Merged into `main` as `c280aa7`** after the owner's 7 pre-merge checks; 91/91 tests on `main`.
- **Follow-up (owner, planning only):** protected admin registry + owner-only recovery CLI. See `PROPOSAL_ADMIN_PROTECTION_RECOVERY.md`. Not to be implemented during T-10.

### T-05 — Read-only audit-log endpoint (PROPOSED, planning only)
- **Team:** 2
- **Owner requirements (decision 5):** admin authorization; pagination; filtering (e.g. action, outcome, actor_id, target_id, date range); safe response fields only (the stored entry has no secrets by construction); no unauthorized record disclosure.
- **Status:** PROPOSED (planning approved, implementation not approved).

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
- **Superseded by:** `API_CONTRACT_T10_STUDENTS.md` (owner decisions 2026-11-17). Faculty receive **404** (not 403) for anything not visible, and access is deny-all until T-31. Year level is limited by the program maximum. Owner decision 3 adds profile retention.
- **Status:** VERIFIED. **Merged into `main` as `07b4c83`** after the owner's 8 pre-merge checks; 112/112 tests on `main`. See `T10_IMPLEMENTATION_REPORT.md`.

### T-11 — Academic records + StudentDirectory
- **Team:** 3
- **Description:** Academic record read endpoint, programs list (mock), and the `StudentDirectory` interface + implementation for other modules.
- **Files allowed to change:** `app/Students/**`, `routes/students.php`, `tests/Students/*`
- **Dependencies:** T-10, B-07
- **Acceptance criteria:** `/api/students/{id}/academic-record` respects the same ownership rules. It contains no grades or enrollment copies. `StudentDirectory` returns summaries only (no private fields beyond the contract).
- **Required tests:** academic record ownership, StudentDirectory unit tests (found, not found, by user ID).
- **Owner decisions (2026-11-17):** scope split D5. **T-11a APPROVED**: academic record retrieval (staff, self), faculty refused, academic change history written atomically with profile updates, read-only `/api/programs`, in-process `StudentDirectory`, explicit unavailable enrollment/grade summaries, tests. D4: **no history cap**, paginated responses. T-11b not approved.
- **Extra files (T-11a):** `app/bootstrap.php` (Students wiring), `README.md`, `ai-coordination/API_CONTRACTS.md`, `ai-coordination/PROPOSAL_T11_ACADEMIC_RECORDS.md`, `ai-coordination/T11A_IMPLEMENTATION_REPORT.md`.
- **Status:** T-11a VERIFIED. Independent review (AGY, 2026-11-17): APPROVE, no Critical/High/Medium; L-01 is the documented file-store limitation. **Merged into `main` as `d062a5e`** with owner approval; 124/124 tests and Apache PASS on `main`. Not pushed. T-11b PROPOSED.

### T-20 — Terms & sections catalog
- **Owner approval (2026-11-17):** APPROVED. Plan: `docs/plans/T-20-terms-sections.md` (adds registrar term create/update). No T-21/T-22 work. Commit to `task/T-20-terms-sections` only; no merge without owner approval.
- **B-04 (owner, 2026-11-17): RESOLVED.** Team 4 owns the authoritative instructor-to-section assignment; Team 5 owns faculty profiles and grades and consumes assignments via an approved interface, keeping no conflicting assignment. Instructor ids must reference valid faculty identities; authorization server-side; changes validated and audited; no duplicated faculty data; no Team 6 schema changes. Implemented in T-21 (see revised T-21/T-30/T-31).
- **Team:** 4
- **Description:** Term and Section models (with meetings), repositories + mock, `/api/terms`, `/api/sections`, registrar section create/update.
- **Files allowed to change:** `app/Enrollment/**` (excluding `Contracts/`), `routes/enrollment.php`, `app/bootstrap.php` (Enrollment wiring only), `database/seeders/dev_sections.php`, `tests/Enrollment/*`
- **Dependencies:** T-02
- **Acceptance criteria:** Filter by term, course or search. Remaining slots computed from approved count. Meetings validated (day enum, `start < end`, HH:MM). A section's own meetings must not overlap. Capacity ≥ 1 and not below approved count (409). Registrar/admin only for writes.
- **Required tests:** listing/filtering, meeting validation, capacity rules, role matrix.
- **Status:** **MERGED** into `main` as `8c2b980` (owner authorization 2026-11-20, after the mandatory AGY review: APPROVE on `81097d5`, no Critical/High/Medium, no blockers). Post-merge on `main`: 163/163 tests, 104/104 `php -l`, Apache PASS on both document roots. Not pushed.

### T-21 — SectionDirectory, faculty identity port, instructor assignments
- **Team:** 4
- **Owner approval (2026-11-20):** APPROVED. Plan: `docs/plans/T-21-instructor-assignments.md`, with Q1–Q4 decided and the owner's additions in §1.1.
- **Description:**
  - The `SectionDirectory` contract and `SectionSummary`.
  - The `FacultyIdentityLookup` port and `FacultyIdentity` (Team 5 implements it in T-30). Production uses `UnavailableFacultyIdentity`, which refuses every assignment.
  - `POST`/`DELETE /api/registrar/sections/{id}/instructors`.
  - `instructors` in the section JSON.
  - Append-only `assignment_history` in the same transaction.
  - Instructor schedule conflict prevention.
- **Files allowed to change:** plan §12:
  - Claude: `app/Enrollment/Contracts/*`, `app/bootstrap.php` (Enrollment block), `routes/enrollment.php`, `README.md` (Enrollment section), `app/Students/Contracts/FacultyStudentVisibility.php` (docblock only, owner Q2), the plan and `ai-coordination/*`.
  - Codex: `app/Enrollment/{Models/Section,Repositories/*,Services/SectionService,Services/RepositorySectionDirectory,Controllers/SectionController}.php`, `tests/Enrollment/**` and `ai-coordination/T21_IMPLEMENTATION_REPORT.md`.
- **Dependencies:** T-20 ✔, B-04 ✔. Real faculty verification needs T-30 (until then production refuses every assignment, by owner decision). B-11 affects T-31.
- **Acceptance criteria (plan §10, plus the owner's requirements):**
  - Registrar/admin only, with CSRF. Unknown, inactive or unverifiable faculty → 422 (fail closed).
  - Duplicate → 409. More than 5 instructors per section → 409.
  - Overlapping meetings for the same instructor in the same term → **409**, checked atomically in the store lock. All actual meetings count, including those of `tba` and `online_async` sections (Q1).
  - A section PATCH that would create a conflict → 409.
  - Exactly one audit entry per successful change, in the same transaction; failures write none.
  - Corrupt assignment or history data fails closed.
  - Retained assignments of inactive faculty are kept and shown, but grant no access (Q4, A14). New assignments to inactive faculty → 422.
  - Test doubles only under `tests/`.
  - T-20 exact-response tests updated without weakening them. Backward-compatible store loading.
- **Required tests:** plan §9: unit, directory, store, HTTP matrix, concurrency C6–C10, production fail-closed, mutations M9–M20.
- **Status:** **MERGED** into `main` as `d83e207` (owner authorization, 2026-11-20).
  - **Reviews:** review #1 (Codex, `54d1a6b`) returned REQUEST_CHANGES on C10 test reliability and a coverage gap; both were fixed in `2bc6a90` (tests only). Re-review #2 returned APPROVE on `417ba6a`.
  - **On `main` after the merge:** 184/184 tests, 115/115 `php -l`, Apache PASS on both roots.
  - **Real-session DELETE probe through Apache:** passed at `417ba6a`.
  - Not pushed.

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
- **Note from T-20 (owner decision 2026-11-17):** sections have `delivery_mode` (`scheduled`/`tba`/`online_async`). TBA and online-async sections may have no meetings, so they never clash and must still appear in `/api/schedules/me` without time slots. See `docs/plans/T-20-terms-sections.md` §16.
- **Status:** PROPOSED

### T-30 — Faculty profiles & FacultyIdentityLookup (teaching assignments are Team 4's, T-21)
- **Team:** 5
- **Description (revised for B-04):** Faculty model, repository + mock, `/api/faculty/me`, `/api/faculty/classes` (built from Team 4's `SectionDirectory::sectionIdsForFaculty`), `FacultyDirectory::findByUserId`, and the real `FacultyIdentityLookup` implementation. **No** TeachingAssignment model or assignment endpoint: Team 4 owns assignments (B-04).
- **Files allowed to change:** `app/Faculty/**`, `routes/faculty.php`, `app/bootstrap.php` (Faculty wiring + swap the `FacultyIdentityLookup` binding), `database/seeders/dev_faculty.php`, `tests/Faculty/*`
- **Dependencies:** T-21, B-04 ✔
- **Acceptance criteria:** Faculty sees only own profile and classes. `FacultyIdentityLookup` reports only real, active faculty. Sections now show instructor names. Team 5 keeps no assignment data.
- **Required tests:** ownership, role matrix, FacultyIdentityLookup output.
- **Plan (approved 2026-11-20):** `docs/plans/T-30-faculty-profiles.md`.
  - Faculty profiles linked 1:1 to accounts; `findByUserId` resolves B-11.
  - The real `RepositoryFacultyIdentity`, active = profile active AND account active AND `faculty` role.
  - Registrar/admin profile routes, `/api/faculty/me` and `/api/faculty/classes`.
  - Atomic append-only history; a configurable `EmployeeNumberPolicy`.
  - **No assignment data: Team 4 owns teaching assignments (B-04, T-21).**
- **Status:** PLAN APPROVED. Implementation (phases 1–6) awaits separate owner authorization.

### T-31 — Class rosters
- **Team:** 5
- **Description:** `/api/faculty/classes/{id}/students`, built from `SectionDirectory::approvedStudentIds` and `StudentDirectory`. The assignment check is `SectionDirectory::isAssigned` (Team 4 source, B-04); the B-07 "officially assigned" fact also comes from Team 4.
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
- **Separation of duties (owner, 2026-11-20, from T-30 Q9):** staff accounts that also hold `faculty` may hold faculty profiles and be assigned to sections. T-32 must therefore stop a user acting as both instructor and approver on the same grades; for example, nobody may finalize grades for a section they teach.
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
- **Status:** Superseded by T-60 Phases 6–7 (owner, 2026-11-21). The file list is amended per `docs/plans/T-60-database-architecture.md` §7. B-03 is resolved by the T-60 design. No cutover without separate approval.
