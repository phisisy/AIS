# Database blockers, compatibility and verification (T-60)

> **Status: CONDITIONALLY APPROVED (owner, 2026-11-21; safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0). Implementation and testing against `ais_test` only; no cutover. Original note:** This register supplements `ai-coordination/INTEGRATION_BLOCKERS.md`. It changes no blocker status. Only the owner does that, and Claude records it in Phase 4.

## 1. Blocker register (database view)

| ID | Status today | Database impact | Proposed resolution / handling |
|---|---|---|---|
| **B-03** Team 6 schema not finalized | OPEN, blocks T-40 | All MySQL work | **Proposed resolution:** the owner approves `DATA_DICTIONARY.md` §1–5 (the confirmed + CP tables) and the plan's owner questions. B-03 is then RESOLVED for the **implemented** modules and T-30. T-22, T-32 and D1 tables stay out, and each is added by an additive migration when its plan is approved. Differences from B-03's list: **no `roles` table** (OQ-2); "teaching assignments" = `section_instructors` (Team 4, B-04), so there is no Team 5 table; `users.auth_version` **is** included (B-09 question answered) |
| **B-05** Grade scale & finalization | OPEN, blocks T-32 | `grades.value` domain, correction policy, finalization fields | **No grade DDL.** Open: the scale values (proposed default 1.00–3.00 step 0.25, 5.00, INC, DRP); whether registrar approval is required; whether finalized grades can be corrected (that decides whether `grade_history` needs a correction action); separation of duties (T-32, app logic) |
| **B-08** Enrollment eligibility | OPEN, blocks T-22 | `enrollments.status` set, any period columns | **No enrollment DDL.** The only period control today is `terms.enrollment_open`, which exists. Open: drop/cancel statuses, prerequisites, unit limits. Also conflict C-03: which `academic_status` values may enroll |
| **B-09** Persistent session revocation | DEFERRED | Needs a per-user version column | **Schema part covered:** `users.auth_version INT UNSIGNED NOT NULL` carries the existing T-01 semantics (bumped on password change and deactivation, checked on every request). Sessions stay native PHP files. A DB session store is not proposed |
| **D1** Course catalog owner | Undecided, blocks T-11b | `courses` table and the `sections.course_code` FK | **No table.** `sections.course_code`, `course_title` and `units` stay format-checked values. When D1 is decided: an additive migration plus a data reconciliation plan (T-20 §3.2) |
| **B-11** Faculty user id ↔ `faculty_id` | OPEN on the board; resolved on the provider side by the T-30 plan | Structural | `faculty.user_id UNIQUE NOT NULL FK users.id`; `faculty.id` = `faculty_id`. In SQL, `findByUserId` is one indexed lookup (`uq_faculty_user`) |
| **T-30** (plan approved, not implemented) | — | `faculty`, `faculty_history`, and the target of every `faculty_id` FK | V0005/V0006 are written and tested in `ais_test` only. **Not applied elsewhere until T-30 is merged.** `MySQLFacultyRepository` (Phase 6d) waits for T-30's `FacultyRepositoryInterface` to exist on `main`. The enrollment cutover after T-30 requires faculty on `mysql` |
| **T-22** (proposed) | — | `enrollments`; `approved_count` maintenance; `SectionDirectory::approvedStudentIds` | It shares the `enrollment` lock (T-20 P1). The sketch is in `DATA_DICTIONARY.md` §6.1. It is defined when the T-22 plan is approved |
| **T-32** (proposed) | — | `grades`, `grade_history`, a `grades` lock row | The sketch is in `DATA_DICTIONARY.md` §6.2. Waits for B-05 and T-31 |

## 2. Repository compatibility analysis

Can the MySQL adapter keep **identical** observable semantics for every existing interface method? "Identical" means: the same return values (exact PHP types and key order), the same exceptions (status codes, messages, error keys), the same ordering, and the same atomicity.

### 2.1 Team 2: `UserRepositoryInterface`, `UserAdminRepositoryInterface`, `UserTransactionInterface`

| Method | Same semantics? | How / caveat |
|---|---|---|
| `findById(int)` | Yes | `users` + `user_roles ORDER BY role` → `User` constructor (it validates and throws on a corrupt row) |
| `findByEmail(string)` | Yes | Lowercase and trim the input in PHP (as the mock does), then `WHERE email = ?` under binary collation |
| `search(emailContains, page, perPage)` | Yes | `WHERE email LIKE ? ESCAPE '!'` with an escaped lowercase needle; `ORDER BY id LIMIT/OFFSET`; `total` from `COUNT(*)` in the same read snapshot |
| `transaction(callable)` | Yes | Lock `auth`. Count before and after. 409 `LAST_ADMIN_MESSAGE` + ROLLBACK. An exception from the callable means ROLLBACK and rethrow. Nested write means `RuntimeException` |
| `auditEntries()` | Yes | `ORDER BY id`, with `changes` JSON-decoded. It is unbounded, like the mock (T-05 adds pagination) |
| `tx->find`, `tx->findByEmail` | Yes | They read their own uncommitted writes, as the mock's working copy does |
| `tx->insert` | Yes, except id gaps | 409 duplicate email checked in the transaction first. **The id comes from AUTO_INCREMENT,** so it can differ from the mock's `max + 1` only after a rolled-back insert. No contract or test depends on gap-free user ids |
| `tx->update` | Yes | The same key allowlist (`InvalidArgumentException`) and the same `auth_version` bump rule. Roles are replaced as a set (DELETE + INSERT) |
| `tx->appendAudit` | Yes | The same checks (`SECURITY.md` §4). `id = last_seq + 1` gap-free |
| `MockUserRepository::seed` | Not ported | Seeder-only, not an interface method. MySQL seeding isn't requested (the seeders refuse mysql mode) |

### 2.2 Team 3: `StudentRepositoryInterface`, `ProgramCatalogInterface`

| Method | Same semantics? | How / caveat |
|---|---|---|
| `findById`, `findByUserId` | Yes | One row, built in `Student::FIELDS` order with native types |
| `search(filters, page, perPage)` | Yes, with one documented limit | Exact `program_code` / `academic_status`; `LOWER(col) LIKE ?` on `student_number`, `first_name`, `last_name` with a needle lowered by `mb_strtolower`. **Limit:** rare Unicode case mappings may differ between `LOWER()` and `mb_strtolower` (`ARCHITECTURE.md` §1.1) |
| `create(fields, now, actorId)` | Yes | Lock `students`. **Both** 409 errors can be returned together (user_id and student_number), as the mock does. Initial history entries are seq 1..k |
| `update(id, changes, now, check, actorId)` | Yes | Lock `students`. 404; `$check($merged)` inside the transaction; `Student::fromArray`; tracked-field history appended with `MAX(seq)+1`; `InvalidArgumentException` on a non-mutable key before any DB access |
| `history(studentId, page, perPage)` | Yes | `ORDER BY seq LIMIT/OFFSET`; typed `from`/`to` (int `year_level`); `total` |
| `find(code)`, `all()` | Yes | Exact match under binary collation. `all()` returns `ORDER BY code`. The mock returns file order, but the only caller sorts by code (`AcademicRecordService.php:59`), so this isn't observable |

### 2.3 Team 4: `CatalogRepositoryInterface` (and the `SectionDirectory` built on it)

| Method | Same semantics? | How / caveat |
|---|---|---|
| `terms()` | Yes | `ORDER BY start_date, id` |
| `findTerm(id)` | Yes | |
| `createTerm(fields, now)` | Yes | Lock `enrollment`. 409 `code`. If `is_current`, first `UPDATE terms SET is_current=0, updated_at=? WHERE is_current=1` (the unique marker requires clearing first), then INSERT |
| `updateTerm(id, changes, now, check)` | Yes | Lock. 404. `$check`. Clear the other current terms (and set their `updated_at = now`, as `clearCurrent` does). UPDATE |
| `findSection(id)` | Yes | Section + meetings `ORDER BY position` in one read snapshot. `Section::fromArray` validates (meeting count, overlap, mode pairing) |
| `searchSections(filters, page, perPage)` | Yes | Exact `term_id`/`course_code`; `LOWER()` LIKE on code, title and section code; `ORDER BY term_id, course_code, section_code, id`; meetings fetched for the page ids |
| `createSection(fields, now)` | Yes | Lock. Term existence is checked → 422 `Unknown term.` (not an FK error). 409 natural key. `approved_count=0`. Meetings inserted with `position` |
| `updateSection(id, changes, now, check)` | Yes | Lock. 404. Merged row → `$check` → `Section::fromArray`. **Instructor conflict re-check** for each current instructor in `assigned_seq` order, reporting the first clashing section in id order with the exact message. Meetings replaced (DELETE + INSERT) only if `meetings` changed |
| `assignedFacultyIds(ids)` | Yes | `WHERE section_id IN (…) ORDER BY section_id, assigned_seq`. Every requested id is present with `[]` |
| `sectionIdsForFaculty(fid)` | Yes | `ORDER BY section_id` |
| `assignInstructor(sid, fid, now, actor)` | Yes | Lock. 404 section. 409 duplicate. 409 cap of 5. 409 conflict (same term, overlapping meetings of any delivery mode, first clash in id order, exact message). Then INSERT history (`seq = last_seq+1`), INSERT the assignment with `assigned_seq`. Returns `faculty_ids` in assignment order |
| `unassignInstructor(sid, fid, now, actor)` | Yes | Lock. 404 section. 404 `Instructor assignment not found.`. DELETE the row. INSERT an `unassigned` history row |

### 2.4 Team 5 (T-30 approved): `FacultyRepositoryInterface`, `FacultyIdentityLookup`, `FacultyDirectory`

| Method | Same semantics? | How / caveat |
|---|---|---|
| `findById`, `findByUserId`, `findByIds`, `search` | Yes | Indexed lookups; `ORDER BY id` |
| `create`, `update` | Yes | Lock `faculty`. 409 `user_id` / `employee_number` (both together). Two creation history entries. `seq` from the `faculty` row |
| `RepositoryFacultyIdentity::find`/`findMany` | Yes, **unchanged code** | It stays a service over `FacultyRepositoryInterface` + `UserRepositoryInterface` (F14: one `findById` per distinct account). **The T-30 note's "JOIN users/user_roles" is not adopted** (conflict C-07): it would make Team 5 read Team 2 tables, against ARCHITECTURE.md §4 |

### 2.5 Cross-cutting behaviours

| Behaviour | Mock today | MySQL | Same? |
|---|---|---|---|
| **Corrupt store fails closed** | Every read and write validates the **whole** snapshot; any defect → `RuntimeException` → 500, nothing written | (a) **Per row:** every row passes its PHP model, so a defect is a 500 for requests that touch it. (b) **Structural:** PK/FK/UNIQUE/CHECK make most corruption impossible. (c) **Append-only by privilege:** the app can't rewrite history. (d) **Cross-row invariants** (replay, gap-free, conflicts, cap): `verify.php`, not per request | **No, narrower.** A request that doesn't touch a corrupt row no longer fails. That is the accepted price of not scanning every table per request. **Owner decision OQ-4** |
| **T-21 snapshot replay (A11)** | Replayed on every catalog read | Enforced by construction (one transaction writes the assignment and its history, and the app can't UPDATE/DELETE history) plus `assigned_seq` FK; verified by `verify.php` | Equivalent for app-written data. Hand edits by a privileged account are detected only by `verify.php` (OQ-4) |
| **Gap-free `seq`** | `count + 1` under the file lock | Counter row under the lock (`ARCHITECTURE.md` §7) | **Yes** (default OQ-1) |
| **Uniqueness checked inside the lock** | Yes | Yes: under the lock row, with UNIQUE as the backstop | Yes |
| **A4 window** (T-21 / T-30 §4.4) | Lookup before the enrollment lock | Unchanged: the lookup still runs before the transaction. The FK checks only existence, not status | **Same residual risk, deliberately.** Closing it would mean Team 4 reading Team 5's `faculty.status` inside its transaction (cross-module read, C-07) |
| **Nested store operations** | `RuntimeException` | Write inside an open transaction → the same `RuntimeException` message. Reads inside another repository's transaction are allowed, and multi-statement reads open a `READ ONLY` snapshot only when no transaction is active | Yes |
| **Busy or locked store** | `LOCK_EX` waits indefinitely | Waits up to `innodb_lock_wait_timeout` (50 s), then error 1205 → 500 | Practically the same. Writes are milliseconds long |
| **Concurrent readers during a write** | Shared lock: they wait for the commit | Consistent read: they see the last committed state without waiting | Observably equivalent (linearizable at commit) |
| **Missing store file = empty** | `[]` | Empty tables | Yes |

## 3. Phase 7 verification plan

All MySQL tests are files ending `MySQLTest.php`. They register tests only when `AIS_MYSQL_TESTS=1` and the guard (`SECURITY.md` §6) passes. So `php tests/run.php` without the flag stays fully offline, and its count grows only by the offline tests added in Phases 5–8.

| # | Area | What is proven | Test file (proposed) |
|---|---|---|---|
| V1 | Test DB isolation (offline) | Guard refuses: no flag, wrong `APP_ENV`, `DB_DATABASE` = `ais`, `ais_test2`, `AIS_TEST`, empty; non-local host. No connection attempted | `tests/Database/TestDatabaseGuardTest.php` |
| V2 | Test DB isolation (online) | Connected `DATABASE()` and `CURRENT_USER()` checks; reset statements are qualified `ais_test.` | `tests/Database/TestDatabaseGuardMySQLTest.php` |
| V3 | Runner (offline) | Statement splitting rule; checksum; version ordering; refusal on mismatch, `started`, lower unapplied version; exit codes | `tests/Database/MigrationRunnerTest.php` |
| V4 | Migrations (online) | From empty: all versions apply; **re-run is a no-op**; tamper a file → refuse; simulate an interrupted run (`started`) → refuse; resulting schema matches the expected `information_schema` snapshot (tables, columns, types, collations, PK/FK/UNIQUE, CHECK names) | `tests/Database/MigrationMySQLTest.php` |
| V5 | Constraints (online) | One negative case per CHECK/UNIQUE/FK. Include: lowercase code; code + trailing newline; trailing space under no-pad; second current term; `approved_count > capacity`; position 14; `13:00 → 12:00`; history `year_level` `'9'`; DELETE of a referenced parent → 1451 | `tests/Database/ConstraintMySQLTest.php` |
| V6 | Privileges (online) | As `ais_test_app`: every allowed statement works; UPDATE/DELETE on each history/audit table, DELETE on `students`/`terms`/`sections`/`users`, and any DDL → 1142 | `tests/Database/PrivilegeMySQLTest.php` |
| V7 | **Shared contract tests** | One contract function per interface, run against **both** the mock (offline, always) and MySQL (online): exact arrays, key order, PHP types, ordering, 404/409/422 bodies, the last-admin 409, gap-free seq, assignment order, `remaining`, the meeting round trip, `LIKE` wildcard escaping (`%`, `_`, `!` in the search needle) | `tests/<Module>/<Interface>Contract.php` (shared body) + `…ContractTest.php` (mock) + `…ContractMySQLTest.php` |
| V8 | Concurrency (online, worker processes, own connections, 3 rounds) | Ports of C6–C10 (T-21), F-C01–F-C03 (T-30), the last-admin race (two demotions), the duplicate student create race, the `is_current` race (two terms set current → exactly one). Assert no deadlock errors, gap-free seq, `verify.php` clean | `tests/<Module>/*ConcurrencyMySQLTest.php` |
| V9 | Fail closed (online) | Inject (as `ais_tester`) a row the model rejects (unknown hash algorithm, a timestamp that doesn't round-trip) → the adapter throws and HTTP gives the generic 500 with no SQL in the body; inject a history gap → `verify.php` exits 1 naming the id; DB unreachable → generic 500, security event still recorded | `tests/Database/FailClosedMySQLTest.php` |
| V10 | Import (online) | Fixture runtime → import → interface equality; corrupt JSON → abort, zero rows; orphan `faculty_id` → abort; non-empty target → refuse; `--dry-run` writes nothing; AUTO_INCREMENT continues at MAX+1; `last_seq` set | `tests/Database/ImportMySQLTest.php` |
| V11 | Wiring (offline + online) | `APP_REPOSITORY_*` validation and the combination rules fail closed; mysql never falls back to mock; HTTP smoke per module on `mysql` (real `public/router.php`) | `tests/Core/ConfigTest.php` W1–W5 (Claude), `tests/Database/WiringMySQLTest.php` (Codex; plan §7 path) |
| V12 | Regression | The 184 existing offline tests (plus T-30's, when merged) stay green, unchanged | `php tests/run.php` |

**Mutation checks** (Codex, on a scratch copy, in the T-21/T-30 style). Each one must make a test fail:

| # | Mutation | Must fail |
|---|---|---|
| DB-M1 | Drop the lock-row `FOR UPDATE` | V8 |
| DB-M2 | Use AUTO_INCREMENT for history `seq` | V7/V8 gap-free |
| DB-M3 | Skip the in-transaction last-admin re-count | V7/V8 |
| DB-M4 | Concatenate the search needle into the SQL | V7 escaping + review |
| DB-M5 | Relax the guard to `str_starts_with($db, 'ais_test')` | V1 |
| DB-M6 | Remove a CHECK from a migration | V4 snapshot + V5 |
| DB-M7 | Grant UPDATE on a history table | V6 |
| DB-M8 | Importer skips the orphan pre-check | V10 |
| DB-M9 | Order `assignedFacultyIds` by `faculty_id` | V7 assignment order |

## Owner safeguard S1 — `verify.php` is not per-request validation

`verify.php` checks the cross-row invariants: history replay, gap-free `seq`, meeting overlap, instructor conflicts, the cap and `assigned_seq` links. It runs **on demand**:
- before any cutover
- after every import and every migration
- before every backup
- whenever integrity problems are suspected
- in Phase 7 tests

It is **not equivalent** to the JSON mock's per-request snapshot replay. Between runs, a corrupt row that a request doesn't read won't make that request fail. Per-row model validation, the DB constraints and the append-only grants narrow, but don't close, that gap (OQ-4, accepted).

## Phase 5 verification: remaining limitations (recorded 2026-11-21, owner request)

1. **Strict mode and UTC have not been tested against a server that isn't already configured that way.**
   - `Database::connect` forces `sql_mode` and `time_zone='+00:00'` on every connection (S3).
   - The local server is already strict and UTC globally, so the online test shows the session values but not that the connection settings *override* a lax server.
   - To close this: a test against a server, or a session, whose global defaults are non-strict and non-UTC. Changing global settings needs root and an owner decision.
2. **MariaDB 10.4.32 is development-only** (end-of-life, S7). Production must use a supported release, for example 10.11 LTS, and Phase 7 must be re-run on it before any cutover.
3. **`migrate.php status` on an empty database** prints "No changes." instead of listing the pending migrations. Cosmetic, with no safety impact. It goes to Codex in a later round.

## Phase 5 final online verification (2026-11-21)

- **Accounts:** `ais_tester@127.0.0.1` has `USAGE` plus `ALL ON ais_test.*`. `ais_test_app@127.0.0.1` has `USAGE` plus **exactly the 15 approved table grants** (checked one by one).
- **Suite:** 195/195 with the MariaDB tests enabled (191 offline + 4 online: migrations, constraints and locking, guard and session, privileges). 191/191 offline. `php -l` 124/124.
- **Privilege denials as `ais_test_app`, all error 1142:**
  - UPDATE and DELETE on `audit_logs`, `student_academic_history`, `section_instructor_history` and `faculty_history`
  - DELETE on `users`, `students`, `faculty`, `terms` and `sections`
  - INSERT on `programs`
  - DROP, ALTER and CREATE
- **Hardening finding (owner action):**
  - XAMPP's default `mysql.db` rows give **every** account access to the `test` and `test\_%` databases. A probe as `ais_test_app` on `test` returned 1146 (no such table), not an access denial.
  - This doesn't appear in `SHOW GRANTS` and doesn't affect `ais_test`, but it breaks least privilege.
  - Fix (root, owner): remove the anonymous `test` database rows and drop the `test` database (`SECURITY.md` §8).

## Phase 6 compatibility findings (6a auth, 6b students; 2026-11-21)

| Finding | Impact | Status |
|---|---|---|
| Entity ids (`users.id`, `students.id`) come from AUTO_INCREMENT, so a rolled-back insert can leave a gap | None: no interface promises gap-free entity ids. Audit and history `seq` stay gap-free (lock row or MAX+1 under the lock) | Accepted |
| `user_roles` has no order column; MySQL returns roles sorted | None through the API: `UserService::roles()` sorts and deduplicates before every write. **Phase 8 import:** JSON roles written outside the service may be unsorted, so the import must compare them as sets | Note for Phase 8 |
| `DATETIME` columns accept only the service's UTC `Y-m-d\TH:i:s\Z` timestamps | None: the service is the only writer | Accepted |
| Foreign keys require an existing user (audit actor, student account) and program | None through the API: the services check these first. A direct repository call with an unknown id now fails, where the mock accepted it | Accepted (stricter) |
| `MySQLProgramCatalog::all()` orders by code; the JSON catalog keeps file order | None: the only caller (`AcademicRecordService::programs`) sorts by code itself | Accepted |
| Search: SQL `LOWER()` and PHP `mb_strtolower` may differ for rare Unicode case mappings | Very rare search mismatches for unusual characters; no security impact | Accepted, documented |

