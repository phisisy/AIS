# Agent handoffs

Add the newest handoff at the top. A handoff is a request. It doesn't mean the receiving agent has read or accepted it.

---

## T-60 Phase 7.1 → CODEX: wiring tests, fail-closed tests, HTTP harness switch, N1 (prepared by Claude, 2026-11-22) — ASSIGNED, not confirmed

**Authorization:** owner, 2026-11-22, test-only wiring. The owner's decisions:
- OQ-A: connect at startup (test-only), with accurate docs.
- OQ-B: no per-request schema check.
- **OQ-C approved:** an optional switch in `tests/Auth/HttpSupport.php`, with behaviour unchanged when off and no unrelated auth changes.
- OQ-D: Claude's Apache probe (done).
- OQ-E: 10 s lock wait (done).
- OQ-F: strict test-only gate (done).
- OQ-G: T-30 rule as a configuration check (done).
- **OQ-H: Codex fixes N1.**
- OQ-I: seeders unchanged.
- **Mock-only HTTP exceptions:** each one listed by exact test name, with why it can't run on MySQL and what MySQL-equivalent coverage exists. **No silent skips, no weakened assertions.** The final list needs the owner's approval at G2.
- **A fresh, isolated runtime directory for EVERY MySQL HTTP test run**, so no session or file state is reused across repository configurations.

**Not authorized:** MySQL in the running app, live data import, faculty, enrollments or grades, merge, push, cutover.

**Claude's part (done, `3f00830`):**
- `app/bootstrap.php`: per-module selection, the test-only gate, one shared PDO, no fallback, the T-30 rule.
- `Database::SESSION_INIT` lock wait.
- `tests/Core/ConfigTest.php` W1–W5.
- The `tests/Core/apache_check.php` probe.
- Docs.

**Don't edit those files.**

**Branch and worktree:** `task/T-60-impl` in `..\AIS_CODEX_T60`, fast-forwarded to this commit. No git writes by Codex.

### Files you may create or edit (and nothing else)
- `tests/Database/WiringMySQLTest.php` (new): V11
- `tests/Database/FailClosedMySQLTest.php` (new): V9
- `tests/Auth/HttpSupport.php`: **only** the optional MySQL switch (OQ-C); default behaviour byte-for-byte the same when off
- `tests/Database/HttpOnMySQLTest.php` (new, optional): the runner that re-runs the existing HTTP suites on MySQL through the switch, if that's cleaner than editing the harness alone
- N1: `tests/Database/ImportOfflineTest.php` and `tests/Database/ImportMySQLTest.php` (test-only fix; no change to `database/tools/*` unless a test proves it necessary, and then stop and report)
- `ai-coordination/T60_IMPLEMENTATION_REPORT.md` (append): include the **mock-only exception list**

### Requirements
- **V11, `WiringMySQLTest`:** for each combination C1 (auth), C2 (auth+students), C3 (auth+enrollment) and C4 (all three; faculty always mock), run the real `public/router.php` through `php -S` with a **fresh temp `APP_RUNTIME_PATH` per run**, `APP_ENV=testing`, `DB_USERNAME=ais_test_app` and the app password from `AIS_MYSQL_TEST_APP_PASSWORD`.
  - **Setup:** a guarded reset and migrations, with the tester-seeded users (and programs) the flow needs. Use `JsonImporter` or tester inserts; never the app account for setup.
  - **Assertions:**
    - login works;
    - a guarded read works;
    - one audited write per MySQL module lands in `ais_test` (checked as the tester);
    - **the module's JSON store file is never created** (no fallback);
    - modules on mock still write their JSON;
    - `sessions/`, `login-attempts.json` and `security-events.json` are still written to files;
    - **one PDO shared by all the MySQL adapters** in the container (in-process bootstrap check);
    - deactivating a user bumps `auth_version`, and that user's other session gets 401;
    - an instructor assign gets 422 and writes nothing (Unavailable lookup).
  - Run each combination ≥ 3 rounds.
- **V9, `FailClosedMySQLTest`:**
  - Model-invalid rows injected by the tester (an unknown hash algorithm, a timestamp that doesn't round-trip, a bad meeting) → a generic 500 with no SQL, host or user in the body.
  - A wrong app password, and a closed port → a generic 500.
  - The OQ-A outage behaviour as documented in `docs/database/SECURITY.md` §5: every request gets 500, and anonymous refusals aren't security-logged during an outage.
  - No fallback: the module's JSON file is never created.
- **OQ-C harness switch:**
  - Opt in through an explicit env var, for example `AIS_HTTP_MYSQL=C1|C2|C3|C4`, honoured only when `MySQLTestSupport::enabled()` and the app password are present.
  - The existing HTTP suites must re-run unchanged, with data seeded as today and then imported into a freshly reset and migrated `ais_test` (tester, `JsonImporter`).
  - Every server start must get a **fresh runtime dir**, enforced in the harness: refuse to reuse a dir that already has sessions or stores.
  - With the switch off, the harness must behave exactly as before; the offline suite count must stay the same.
- **Mock-only list:** run the HTTP suites per combination, then record **every** failing test by exact name, with the reason (for example it reads or corrupts raw JSON stores, or seeds after the server starts) and its MySQL-equivalent coverage. **Don't skip silently**: either it runs on MySQL, or it appears in the list. Don't weaken any assertion.
- **N1:**
  - In `ImportOfflineTest.php`, the database/host mismatch tests and the root and `ais_test_app` migration-account tests must seed the source files (for example `phase8OfflineFiles($runtime,'auth')`), so they reach `assertOfflineTarget`, and assert the **exact** stderr message ("Target or migration account refused.").
  - In `ImportMySQLTest.php`, assert the exact stderr message in the exit-2 refusal tests.
  - Make sure each test now fails if the guard it targets is removed (mutation, scratch copy).
- **Mutations** (scratch copy outside every worktree; report results):
  - WM1: bind `MockUserRepository` when auth is on mysql → V11 fails (JSON created).
  - WM2: a second `Database::connect` per adapter → the V11 shared-PDO check fails.
  - WM3: drop the faculty clause from the gate → `ConfigTest` W2 fails.
  - WM4: catch the connect failure and fall back → W4/V9 fail.
  - WM5: connect before the gate → W3 fails.
  - Plus N1: remove `assertOfflineTarget`'s checks → the N1 tests fail.
- **Online tests** register only when `MySQLTestSupport::enabled()` and `AIS_MYSQL_TEST_APP_PASSWORD` is set. Use the guarded reset only; `ais_test` only; never print credentials.
- **MariaDB is unreachable from the Codex sandbox:** write the online tests and report them NOT RUN. Claude runs them. Run the offline suite (223 must still pass) and `php -l`.

---

## T-60 Phase 8 → CODEX: `verify.php` + `import_json.php` (prepared by Claude, 2026-11-21) — ASSIGNED, not confirmed

**Authorization:** owner, 2026-11-21. **Tooling only**, against `ais_test` only.
- Not authorized: wiring/bootstrap, `APP_REPOSITORY=mysql`, `ais` or any production database, importing the live runtime, cutover, a faculty repository, T-22, merge, push.
- **No change to any existing `app/` file.** If a repository defect shows up, stop and report it.
- Branch and worktree: `task/T-60-impl` in `..\AIS_CODEX_T60`, fast-forwarded to this commit. No git writes by Codex.

### Task A — plan (Claude; approved design: `CONSTRAINTS_AND_MIGRATIONS.md` §4–§5, `BLOCKERS.md`, `SECURITY.md` §6)

- **Supported modules, in this order:** `auth`, then `students`, then `enrollment`.
  - `auth`: `users`, `user_roles`, `audit_logs`, plus the `auth` lock row.
  - `students`: `programs` (from `programs.json`), `students`, `student_academic_history`.
  - `enrollment`: `terms`, `sections`, `section_meetings`, `section_instructors`, `section_instructor_history`.
- **Deferred:**
  - `faculty`: no JSON faculty store exists before T-30. `--module=faculty` → exit 2 "deferred until T-30".
  - T-22 enrollments and grades: no tables.
  - Rate limiters, security events and sessions stay file-based.
- **Preconditions** (refuse with exit 2):
  - schema fully `applied`;
  - the module's target tables empty and its lock `last_seq = 0`;
  - dependencies imported (students and enrollment need users; enrollment assignments need faculty rows);
  - the target is `ais_test` only (as `migrate.php`), checked against `DB_DATABASE` and the live `SELECT DATABASE()`, `@@port` and `CURRENT_USER()`;
  - CLI only;
  - the migration account (`DB_MIGRATION_*`; in `ais_test` that's `ais_tester`), never root.
  - "App stopped" can't be checked automatically, so it's printed as a reminder, not enforced.
- **Single-use, not idempotent:** a second import into non-empty tables is refused (exit 2). It never merges, overwrites or deletes.
- **Steps per module:**
  1. Validate the whole source with the existing mock (full-snapshot validation). Any corruption → exit 1, nothing written.
  2. Read the raw JSON via `JsonStore::read()`.
  3. `BEGIN`, lock the row, then INSERT with the original ids and seqs. Roles are inserted sorted, and roles are **compared as sets** wherever JSON and MySQL are compared.
  4. Set `last_seq` to the imported stream maximum. **I1: the auth counter = `MAX(audit_logs.id)`.**
  5. Verify inside the transaction: row counts; interface equality, mock vs MySQL adapter on the same connection (§5.3 step 5; roles compared as sets); and the module's verifier checks.
  6. `COMMIT` only if everything passes, otherwise `ROLLBACK`. `--dry-run` always rolls back.
  - **Orphan `faculty_id` → abort** (OQ-8).
  - No silent dropping, no fabricated relationships, no auto-repair, no conflict resolution.
- **Output:** counts and check names only. Offending **ids** only; never emails, names, hashes or values.
- **Exit codes:** 0 ok or clean dry run, 1 aborted and rolled back, 2 refused.
- **`verify.php --target=ais_test`:**
  - Read-only, `SELECT` only. It wraps the reads in one REPEATABLE READ snapshot and runs no write statement.
  - All the checks in `CONSTRAINTS_AND_MIGRATIONS.md` §4: per-student history replay and last `to_value` = current column; assignment history 1..n, replay = `section_instructors`, `assigned_seq` the latest; ≤ 5 per section and no same-term overlap per instructor; scheduled sections have ≥ 1 meeting and no self-overlap; audit ids 1..n; `last_seq` = stream max for every lock row; every user has ≥ 1 role; `year_level` ≤ program max; at most one current term; every row passes its PHP model. Faculty-history checks only if rows exist.
  - Foreign keys and duplicates are enforced by constraints; an orphan and duplicate scan is optional.
  - Output: check name, PASS or FAIL, offending ids only. Exit 0 clean, 1 violations, 2 error (sanitized).
  - It must work with `SELECT` only, so it can run as `ais_test_app`.

### Files you may create (plan §7 row 8), and nothing else
- `database/verify.php` and `database/import_json.php` (thin CLIs)
- `database/tools/IntegrityVerifier.php` and `database/tools/JsonImporter.php` (logic; required like `MigrationRunner.php`)
- `tests/Database/VerifyMySQLTest.php` and `tests/Database/ImportMySQLTest.php` (online, guarded)
- `tests/Database/ImportOfflineTest.php` (argument parsing and refusals that need no DB)
- the report append

### Test matrix (owner Task E; all online tests on `ais_test`, guarded, with a guarded reset before each)

**Source:** each test builds a **disposable JSON runtime in a temp dir**, using the existing mock repositories and services (or the dev seeders run with `APP_RUNTIME_PATH` set to the temp dir). Never `C:/ais-runtime` or any configured runtime. The temp dir is deleted afterwards.

| # | Case | Expected |
|---|---|---|
| E1 | Valid import of all 3 modules (users with multiple roles and an audit trail; students with history; terms, sections with meetings of every `delivery_mode`, assignments with history, faculty rows pre-seeded by the tester) | exit 0; counts equal; verifier clean |
| E2 | IDs preserved: users, students, terms, sections, the faculty_ids in assignments; the next AUTO_INCREMENT insert gets MAX+1 | equal ids |
| E3 | History and audit preserved, with their order (seq, `at`, actor, `from`/`to`) | equal |
| E4 | Duplicate ids or business keys in the source, or a target not empty | exit 1 (source) or 2 (target); nothing written |
| E5 | Missing foreign key: a student `user_id` with no user; an assignment `faculty_id` with no faculty row | exit 1; full rollback |
| E6 | Invalid data: a bad email, a bad timestamp, an unknown role, a malformed meeting | exit 1; nothing written |
| E7 | A failure injected after some INSERTs | **full rollback**: all the module's tables empty, `last_seq` 0 |
| E8 | Repeat import | exit 2, refused; data unchanged |
| E9 | I1: the auth `last_seq` = `MAX(audit_logs.id)` after import; a new audited write through `MySQLUserRepository` gets id MAX+1 | ok |
| E10 | Counts per table equal the JSON counts | ok |
| E11 | Semantic equivalence: mock vs MySQL interface outputs equal, roles as sets | ok |
| E12 | Safety: refuses `--target` ≠ `ais_test`, a mismatched `DB_DATABASE` or live database, or a non-loopback host; never resets; never writes the source runtime (byte-identical before and after); `--dry-run` writes nothing | ok |
| V1 | `verify.php` on a clean imported DB | exit 0, all PASS |
| V2 | Corruption injected by the tester, one per check: history gap; history/current mismatch; assignment replay mismatch; `assigned_seq` wrong; 6th instructor; same-term overlap; scheduled with no meeting; audit id gap; `last_seq` drift; user without a role; `year_level` > program max; two current terms; an invalid model row | exit 1; the failing check named; ids only |
| V3 | `verify.php` writes nothing (checksum of every table before and after) and runs as `ais_test_app` | ok |
| V4 | Database error, for example a wrong password | exit 2; sanitized message |

Codex can't reach MariaDB: write the online tests, run the offline suite (211+) and `php -l`, and report the online tests as NOT RUN. Claude runs everything online.

---

## T-60 Phase 7.2 → CODEX: MariaDB concurrency and transaction-integrity tests (prepared by Claude, 2026-11-21) — ASSIGNED, not confirmed

- **Authorization:** owner, 2026-11-21. **Tests only.**
  - Don't change any existing repository, service, model or interface. If a test exposes a defect, **stop and report it**; don't fix the adapter.
  - Not authorized: wiring, `APP_REPOSITORY=mysql`, `ais` or import, a faculty repository, T-22, merge, push.
  - No automatic retries.
- **Branch and worktree:** `task/T-60-impl` in `..\AIS_CODEX_T60`, fast-forwarded to this commit. No git writes by Codex.
- **Files you may create (plan §7, row 7.2, minus wiring), and nothing else:**
  - `tests/Database/fixtures/mysql_worker.php`: a CLI worker for the concurrency tests
  - `tests/Database/MySQLConcurrencySupport.php`: the barrier and race helper; a new file is allowed
  - `tests/Auth/UserConcurrencyMySQLTest.php`
  - `tests/Students/StudentConcurrencyMySQLTest.php`
  - `tests/Enrollment/CatalogConcurrencyMySQLTest.php`
  - `tests/Database/TransactionIntegrityMySQLTest.php`: deadlock, rollback and orphans
  - an append to `ai-coordination/T60_IMPLEMENTATION_REPORT.md`
  - `WiringMySQLTest.php` is **out of scope** (no wiring).
- **Harness design** (copy the existing JSON race helpers' style: `tests/Enrollment/EnrollmentConcurrencyTest.php` `raceEnrollmentWorkers`, `tests/Auth/AdminConcurrencyTest.php`):
  - **Separate PHP worker processes** (`proc_open`, `PHP_BINARY`). PDO calls block, so in-process concurrency isn't possible.
  - The workers inherit the guarded env. Each one connects **as `ais_test_app` via `MySQLTestSupport::connectApp()`**, runs one repository operation through the real MySQL adapter, and prints one JSON line: `ok`, `status`, `message`, and `class` for unexpected exceptions. Never print SQL, driver text or env values.
  - **Setup and verification:** the parent uses `MySQLTestSupport::connect()` (`ais_tester`), with a guarded reset plus migrations, then fixtures, then the race, then checks on the final state.
  - **Deterministic sync:**
    - (1) A start barrier: workers write a ready-flag, then wait for a start-flag file, with a bounded poll (≤ 15 s) and fail-safe exit.
    - (2) **Controlled contention:** where ordering matters, the parent (owner connection) takes `SELECT … FROM repository_locks WHERE name=? FOR UPDATE` in an open transaction **before** releasing the workers. It confirms they are blocked (for example through `information_schema.INNODB_TRX` or `PROCESSLIST` as `ais_tester`; if there's no PROCESS privilege, a bounded wait for "not finished"), then commits, and the workers proceed one at a time.
    - **Not sleep-only:** sleeps may only be bounded poll intervals.
  - **Bounded waits:** set `innodb_lock_wait_timeout` (session) to a small bound (for example 10 s) in the workers. Every `proc_open` has a hard overall timeout, and on timeout the parent kills the workers and fails the test. Always close connections and roll back the parent's open transactions in `finally`.
  - **Run each race ≥ 3 rounds.** Record the observed orderings.
- **Required invariants (one assertion group each; report PASS, FAIL or UNVERIFIED):**
  - **Auth:**
    - A1: concurrent updates of different fields on one user are both kept (no lost update).
    - A2: competing role changes: the final roles equal one complete write's set, never a mix; `auth_version` bumps match the committed changes.
    - A3: **two active admins, two simultaneous demotions (or deactivations): exactly one succeeds, the other gets 409 `LAST_ADMIN_MESSAGE`, and active admins never reach 0.**
    - A4: audit ids are gap-free and match committed writes exactly; no orphan audit rows from failed writes; `repository_locks('auth').last_seq = MAX(audit_logs.id)`.
  - **Students:**
    - S1: concurrent updates of different fields on the same student: both kept.
    - S2: per-student history `seq` is gap-free 1..n, with entries equal to the committed tracked changes.
    - S3: a duplicate student number, or a duplicate `user_id` create race: exactly one wins, the others get 409.
    - S4: a failing update (thrown `$check`) racing successful ones leaves no partial row or history.
  - **Catalog:**
    - C1: two terms set current concurrently: exactly one is current at the end.
    - C2: concurrent section PATCHes of different fields: all kept.
    - C3: **the same faculty assigned concurrently to two overlapping sections in one term: exactly one wins, the other gets 409.**
    - C4: concurrent assignments to one section with 4 existing instructors: exactly one 5th succeeds, the rest get 409 at the cap.
    - C5: `section_instructor_history` seq is gap-free, matches committed assigns and unassigns, and replays to the current `section_instructors` (order via `assigned_seq`).
    - C6: **a section meeting PATCH racing an assignment that would conflict: the final state has no instructor conflict** (one 409), whatever the order.
  - **Transaction integrity:**
    - T1: an atomic commit or rollback for a failure injected mid-operation, through the owner connection or a thrown callback, has no partial rows in any table.
    - T2: locks are released after a commit and after a rollback; a follow-up write proceeds immediately.
    - T3: **a deliberate deadlock between two worker connections** (for example A locks auth then students and B locks students then auth, built with tester-level row locks where the app grants don't allow it). MariaDB reports 1213 for exactly one of them; the victim's repository call fails with the sanitized `RuntimeException` and rolls back completely; the other commits. Record whether a deadlock can arise **through the adapters alone**. Expected: no, since each operation takes one store lock row; say whether that's proven or only argued.
    - T4: a lock-wait timeout (1205) from a held lock gives a sanitized failure with nothing written.
- **Everything runs only against `ais_test`.** The guard is re-checked before every reset. Never connect to another database.
- **Codex can't reach MariaDB.** Write the tests, make sure they register only when `MySQLTestSupport::enabled()` and the app password is present, run the offline suite (211 must stay green) and `php -l`, and report the online tests as NOT RUN. Claude runs them online and records the results.

---

## T-60 review fixes (A) + Phase 6c (B) → CODEX (prepared by Claude, 2026-11-21) — ASSIGNED, not confirmed

- **Authorization:** owner, 2026-11-21.
  - Independent review of 6a/6b: **APPROVE** (`ai-coordination/T60_6AB_REVIEW_REPORT.md`).
  - 6c is authorized for the existing T-20/T-21 catalog: terms, sections, meetings, instructor assignments, assignment history.
  - **Not authorized:** T-22 enrollment requests, grades, any T-22 schema, a faculty repository (waits for T-30), wiring/bootstrap, `APP_REPOSITORY=mysql`, `ais` or import, merge, push.
- **Branch and worktree:** `task/T-60-impl` in `..\AIS_CODEX_T60`, fast-forwarded to this commit. No git writes by Codex. Report parts A and B **separately**.

### Part A: review fixes for 6a/6b (tests only; no adapter behaviour change unless a test proves a bug)

Files: `tests/Database/MySQLTestSupport.php`, `tests/Auth/UserRepositoryContract*.php` and `tests/Students/StudentRepositoryContract*.php` (existing), plus the report append.

- **M1:**
  - Add a guarded helper `MySQLTestSupport::connectApp(): PDO`: user `ais_test_app`, password from env `AIS_MYSQL_TEST_APP_PASSWORD`, same host, port and database. It runs `assertConnection` and requires `CURRENT_USER()` to start with `ais_test_app@`.
  - In both MySQL contract factories, keep the owner (`ais_tester`) connection for reset, migrations and fixtures, but **build the repository under test on the app connection**.
  - If `AIS_MYSQL_TEST_APP_PASSWORD` is unset, the MySQL contract tests must fail loudly rather than fall back to the owner account.
- **M2:** add searches for `'%'` alone and `'_'` alone, each expecting exactly the one fixture row whose value literally contains that character. Make sure the fixtures have such rows and other rows without them, so an unescaped implementation would fail.
- **L3, MySQL-only cases** (fault injection through the owner connection):
  - (a) A `PDOException` inside `MySQLUserRepository::transaction()`, for example a callback that inserts a duplicate primary key through `appendAudit` after moving `last_seq`, comes out as a sanitized `RuntimeException`, with nothing written and `last_seq` unmoved.
  - (b) A student write where the `students` row succeeds and then the history insert fails: everything is rolled back.
  - (c) `appendAudit` with a timestamp not in `…Z` format is refused, and `last_seq` is unmoved.
  - (d) A corrupt history row injected by the owner fails closed with `RuntimeException` on read.

### Part B: Phase 6c, `MySQLCatalogRepository`

Files you may create:
- `app/Enrollment/Repositories/MySQLCatalogRepository.php`
- `tests/Enrollment/CatalogRepositoryContract.php`
- `tests/Enrollment/CatalogRepositoryContractTest.php`: the mock (`MockCatalogRepository`), offline
- `tests/Enrollment/CatalogRepositoryContractMySQLTest.php`: owner connection for setup; **repository on the app connection** (A/M1)
- the report append

Requirements:
- **Interface:** `CatalogRepositoryInterface`, unchanged. Copy `MockCatalogRepository`'s observable behaviour exactly:
  - terms: ordering, unique code 409, the single `is_current` switch, the merged-row `$check` inside the lock
  - sections: unique triple 409, unknown term 422, `delivery_mode`, meetings (order preserved via `position`, replaced as a whole on PATCH), capacity ≥ `approved_count` via `$check`, and `remaining` computed, not stored
  - search filters, order and pagination with escaped `LIKE`
  - `findSection`, `assignedFacultyIds` (assignment order via `assigned_seq`), `sectionIdsForFaculty`
  - `assignInstructor` / `unassignInstructor`: 404/409 messages; the 5-instructor cap; the **instructor schedule-conflict 409** (same term, overlapping meetings on the same day, back-to-back allowed, every meeting counts whatever `delivery_mode` says); the same conflict check on section PATCH in the same lock; gap-free `section_instructor_history` seq from the `repository_locks` `enrollment` row; exactly one history row per success and none on failure
- **Locking:**
  - Every write takes `repository_locks` name `enrollment` `FOR UPDATE` first.
  - All decision reads happen after the lock.
  - No `PDOException`/`Throwable` is swallowed inside callbacks (`ARCHITECTURE.md` §6.3).
- **Errors and statements:**
  - Prepared statements only.
  - Generic `RuntimeException('Catalog repository unavailable.')` on driver errors; fixed `HttpException` messages pass through.
  - Rows fail closed via `Term::fromArray` / `Section::fromArray`.
  - Injected PDO; no env access or connecting; no DDL.
- **Foreign keys:**
  - `section_instructors.faculty_id` → `faculty.id`, so the MySQL fixture inserts faculty rows through the owner connection first.
  - **Don't create a faculty repository.**
  - Document the stricter foreign-key behaviour as a deviation.
- **Grants:** every statement must fit `app_grants.sql`. That's proven by M1, which runs it as `ais_test_app`.
- **Out of scope:** T-22 `approved_count` writes. Read-only `approved_count` is fine.

### Checks for both parts
- Offline: `php tests/run.php` green (205 + new offline cases); `php -l` on everything.
- Online: write the tests; they will be NOT RUN by Codex, because there's no DB access. Claude runs them on `ais_test`.
- Report: files, the behaviour mapping, deviations, counts.

---

## T-60 Phase 6b → CODEX: MySQL student repository + program catalog (prepared by Claude, 2026-11-21) — ASSIGNED, not confirmed

- **Prerequisite done:** 6a (auth) verified online on `ais_test` (7/7 MySQL contract cases) and merged into the lead branch (`9f391b3`).
- **Same rules as the 6a handoff below:** owner-authorized; `ais_test` only; no git writes by Codex; no faculty, enrollment or catalog work; no wiring.
- **Files you may create (plan §7, row 6b), and nothing else:**
  - `app/Students/Repositories/MySQLStudentRepository.php`
  - `app/Students/Repositories/MySQLProgramCatalog.php`
  - `tests/Students/StudentRepositoryContract.php`
  - `tests/Students/StudentRepositoryContractTest.php`: mock (`MockStudentRepository` + `JsonProgramCatalog`), offline, always
  - `tests/Students/StudentRepositoryContractMySQLTest.php`: guarded, only when `MySQLTestSupport::enabled()`; reset + migrate per test; programs seeded through the tester connection, because `ais_test_app` can't write `programs`
  - an append to `ai-coordination/T60_IMPLEMENTATION_REPORT.md`
- **Interfaces unchanged:** `StudentRepositoryInterface` and `ProgramCatalogInterface`. Copy the observable behaviour of `MockStudentRepository` and `JsonProgramCatalog` exactly:
  - `search` filters, ordering, pagination and total
  - `create` uniqueness 409s (student number, user) with the same messages
  - `update` with the `$check` callback **inside** the lock, on the merged row, so a thrown check aborts with nothing written
  - immutable fields; `history` order, pagination and `total`
  - creation and update history entries per tracked field (`program_code`, `year_level`, `academic_status`), with the same `actor_id` semantics (0 = system), never truncated
  - the program foreign key and existence behaviour
- **Design specs:**
  - `ARCHITECTURE.md` §6: every write locks `repository_locks` name `students` `FOR UPDATE`.
  - `ARCHITECTURE.md` §7: per-student gap-free `seq` = `MAX(seq)+1` under that lock.
  - `DATA_DICTIONARY.md`: `programs`, `students`, `student_academic_history`.
  - `BLOCKERS.md` §2: rows validated through `Student::fromArray` and `Program`, failing closed.
  - Prepared statements only; escaped `LIKE`; generic `RuntimeException` on driver errors; an injected `PDO`; no env access or connecting; no DDL.
- **Students reference `users`:** the MySQL contract fixture must create the referenced user rows first. Use plain tester-connection inserts or the 6a `MySQLUserRepository`; don't change 6a files.
- **Codex can't reach MariaDB.** Write the online test and report it as NOT RUN; Claude runs it against `ais_test`. Offline: `php tests/run.php` green (198 + the new offline contract tests). `php -l` on everything.

---

## T-60 Phase 6a → CODEX: MySQL user repository (prepared by Claude, 2026-11-21) — ASSIGNED, not confirmed

- **Authorization:** owner, 2026-11-21. Phase 6 runs incrementally, **auth (6a) first, then students (6b)**.
  - **Not authorized:** faculty (waits for T-30 to merge); enrollment repositories (wait for T-22 approval; the 6c catalog scope is an open owner question).
  - **Also not authorized:** `APP_REPOSITORY=mysql` for the app, bootstrap wiring, an `ais` database or data import, merge, push.
- **Branch and worktree:** `task/T-60-impl` in `..\AIS_CODEX_T60`, fast-forwarded by Claude to the T-60 lead branch at the commit that adds this handoff. **No git writes by Codex.**
- **Files you may create (plan §7, row 6a), and nothing else:**
  - `app/Auth/Repositories/MySQLUserRepository.php` and `app/Auth/Repositories/MySQLUserTransaction.php`
  - `tests/Auth/UserRepositoryContract.php`: shared contract bodies, taking a factory
  - `tests/Auth/UserRepositoryContractTest.php`: runs the contract against `MockUserRepository`, offline, always
  - `tests/Auth/UserRepositoryContractMySQLTest.php`: runs the same contract against MySQL; registers only when `MySQLTestSupport::enabled()`; guarded reset plus migrations before each test
  - an append to `ai-coordination/T60_IMPLEMENTATION_REPORT.md`
- **Interfaces are unchanged** (S10): `UserRepositoryInterface`, `UserAdminRepositoryInterface` (including `LAST_ADMIN_MESSAGE`) and `UserTransactionInterface` (including `AUDIT_KEYS`). Copy `MockUserRepository`'s **observable behaviour** exactly: return values, exception types and messages, ordering, the audit entry shape, auth_version bumps, the last-admin rule. Don't edit any existing file in `app/`, including the mock, the models, the services and `Database.php`.
- **Design specs:**
  - `docs/database/ARCHITECTURE.md` §6 (one `repository_locks` row per store: `SELECT … FOR UPDATE` on `name='auth'` at the start of every write transaction), §7 (gap-free `audit_logs.id` from the lock row's `last_seq`), §10.
  - `DATA_DICTIONARY.md` (`users`, `user_roles`, `audit_logs`).
  - `BLOCKERS.md` §2, the compatibility table: per-row validation through `User::fromArray`, fail closed.
  - `SECURITY.md`: prepared statements only, and no secrets in audit rows.
- **Rules:**
  - Construct the repository from an injected `PDO`; never read env or connect inside it.
  - Every write is one transaction, rolled back on any exception.
  - No DDL in application code.
  - Map driver errors to the same exceptions the mock throws (for example a duplicate email → the same `HttpException` 409 the mock gives).
  - Never leak SQL or driver text.
- **Tests:**
  - The contract covers every interface method: found and not found, email normalisation, search pagination and ordering, insert/update, role replacement, the last-admin protection, the audit append and its keys, `auditEntries` order, and rollback on a thrown operation leaving no partial write.
  - A **concurrency test is not in 6a** (it's Phase 7.2); keep transactions safe for it.
  - Offline: `php tests/run.php` must stay green with no DB (186 existing + 5 T-60 + the new offline contract tests).
- **Codex can't reach MariaDB** (sandbox, no network). Write the online test and report it as NOT RUN. Claude runs it against `ais_test` with the guarded environment. Never connect to or create any database yourself.
- **Report:** files, the behaviour mapping (mock vs MySQL), deviations, the offline counts and the expected online coverage.

---

## T-60 Phase 5 → CODEX (prepared by Claude, 2026-11-21) — DONE; ONLINE-VERIFIED by Claude on ais_test

> **Online verification (2026-11-21):**
> - Pre-checks passed: target, account `ais_tester@127.0.0.1`, guard, strict/UTC/nopad_bin.
> - Guarded reset, then V0001–V0006 applied; a repeat `up` was a no-op.
> - A tampered checksum was refused, and `--target=ais` was refused.
> - 194/194 tests with the online tests enabled.
> - Defect V0002 `ck_users_id` (MariaDB 10.4 error 1901) fixed by Codex in `7770ced`.
> - The privilege test is not run yet: it needs root-applied grants for `ais_test_app`, and the env name `AIS_MYSQL_TEST_APP_PASSWORD`.

- **Authorization:** owner, 2026-11-21. Phases 4–8 against **`ais_test` only**. No cutover, no `APP_REPOSITORY=mysql` for the app, no merge, no push. Safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0 are binding.
- **Branch and worktree:** `task/T-60-impl` in `..\AIS_CODEX_T60`, created by Claude from the T-60 lead branch at the commit that adds this handoff (code = Phase 4 `cf4af7d`). **No git writes by Codex**; Claude commits after verification.
- **Scope, Phase 5 only** (plan §6–§7): `database/migrations/V0001__infrastructure.sql` … `V0006__section_instructors.sql`, `database/grants/app_grants.sql`, `database/migrate.php`, `database/tools/MigrationRunner.php`, `tests/Database/{MySQLTestSupport,TestDatabaseGuardTest,TestDatabaseGuardMySQLTest,MigrationRunnerTest,MigrationMySQLTest,ConstraintMySQLTest,PrivilegeMySQLTest}.php`, `ai-coordination/T60_IMPLEMENTATION_REPORT.md`. Nothing else.
- **Specs:**
  - DDL: `docs/database/DATA_DICTIONARY.md` §7. Confirmed tables only; no T-22, T-32 or D1 DDL (OQ-11).
  - Migrations and runner: `CONSTRAINTS_AND_MIGRATIONS.md`.
  - Guard and grants: `SECURITY.md` §1.1 and §6, **conditions 1–8** (S2: an independent `AIS_MYSQL_TEST_TARGET` plus a live re-check immediately before every reset).
  - CLI contract: plan §4.3.
- **Must use** `App\Core\Database::connect()`, so every connection gets strict `sql_mode` and UTC (S3). A MySQL test asserts `@@SESSION.sql_mode` and `@@SESSION.time_zone` on a real connection.
- **S8, real-MariaDB tests:** CHECK-constraint REGEXP behaviour (including the `\z` escaping), binary-collation equality and `LOWER() LIKE` search, uniqueness on generated columns, gap-free history sequencing via `repository_locks`, duplicate and foreign-key violations, and RESTRICT on delete.
- **S5:** V0005/V0006 (faculty) apply to `ais_test` only. The runner refuses them on any other target until T-30 is merged (documented rule; test it).
- **Offline by default:** `php tests/run.php` must stay green **with no database**, because the MySQL test files register no tests unless `AIS_MYSQL_TESTS=1` and the guard passes. The offline guard and runner tests always run.
- **Online tests** need the owner's MariaDB setup. If it isn't ready, write the online tests and report them as **not yet run**. Don't fake results, and don't start, configure or connect to any database other than `ais_test`.
- **Never:** connect as root; touch an `ais` database; edit interfaces, models, services, mock repositories, bootstrap, config or routes.

---

## T-21 → RE-REVIEW of corrected commit (prepared by Claude, 2026-11-20) — REVIEWED: APPROVE

> **Re-review #2:** separate session, worktree `..\AIS_REVIEW_T21_R2`, result **APPROVE** on exactly `417ba6a76421d3dfdf19cd6a1d0b4f9736baa89e` (`T21_REREVIEW_REPORT.md`).
> - Tests: 184/184. Lint: 115/115.
> - C10 and C11 ran 48 rounds each.
> - Mutations M9, M13–M17 were independently killed. With review #1, all of M9–M20 are now independently verified.
> - No production code changed in the corrections.
> - **Apache gap:** Claude re-ran `apache_check.php` and the real-session DELETE probe at `417ba6a` before merging. Both pass on both roots. The probe stays uncommitted (it would be a Core-owned test change), so this is an accepted documentation limitation.

- **Why:** review #1, a fresh Codex session reviewing `54d1a6b`, returned **REQUEST_CHANGES** (`CODEX_T21_REVIEW_REPORT.md`).
  - **Medium:** C10 depended on scheduling.
  - **Low:** there was no race test for assign and unassign of the same pair.
  - No production defect was found.
- **Fix:** made by Codex (the test owner) and committed by Claude on Codex's behalf (sandbox git limit; Codex had exited, the change was reviewed and the suite run).
  - Commit **`2bc6a90`**, merged into the task branch as **`e46b543`**.
  - **Delta vs `54d1a6b`:** only `tests/Enrollment/EnrollmentConcurrencyTest.php` and `tests/Enrollment/fixtures/enrollment_worker.php`, plus docs. **No production code changed.**
  - **C10:** the three new assignments go to a second section whose meetings don't overlap section 1's. All six writes must still succeed, with `seq` 1..9 and the exact final state on both sections.
  - **New C11:** assign vs unassign of one pair, three rounds. Both permitted outcomes are asserted against history and replay.
  - **Worker:** it now also reports the error message.
- **Exact re-review target:** the head of `task/T-21-instructor-assignments` at the commit that adds this section. Everything after `e46b543` changes only `ai-coordination/*.md`. Report the full hash you reviewed. Minimum scope: `54d1a6b..<head>`. A full re-check of `229618b..<head>` is welcome.
- **Verified by Claude at `e46b543` (2026-11-20):**
  - `php tests/run.php`: **184 passed, 0 failed**, including C10 and C11.
  - `php -l`: 115 files, 0 failures.
  - `tests/Core/apache_check.php`: PASS on both document roots.
  - Real DELETE probe through private Apache: PASS on both roots (anonymous 401, no CSRF 403, unassigned 404, missing section 404, production assign 422, `instructors` `[]`, `PUT` 405).
- **Open from review #1, no fix requested:**
  - Mutation checks are reproduced independently for only 6 of 12 (M10, M11, M12, M18, M19, M20); the rest were checked by inspection.
  - The one-off Apache DELETE probe isn't committed. It lives in Claude's scratch folder; committing it would need a Core-owned test change.
  - Accepted limitations: the A4 window, and history that isn't tamper-evident against someone able to rewrite both lists.
- **Reviewer:** the owner assigns one (the fresh Codex review session or AGY when available). Review in a separate worktree at the new head and write the report to the reviewer's report file. Claude has no channel to the reviewers.

---

## T-21 → independent review (prepared by Claude, 2026-11-20) — REASSIGNED to a fresh CODEX review session

> **Review outcome #1 (2026-11-20):** fresh Codex review of `54d1a6b` → **REQUEST_CHANGES** (`CODEX_T21_REVIEW_REPORT.md`).
> - **Medium:** C10 test depends on scheduling (cap 409 possible on correct code).
> - **Low:** no same-pair assign/unassign race test.
> - No production defect.
> - Claude verified the Medium finding; both items are assigned to Codex (test owner). A new review of the corrected commit follows.

> **Reviewer change (owner, 2026-11-20):**
> - **AGY** was assigned first. It hasn't started and is now `UNAVAILABLE_USAGE_LIMIT`.
> - **Replacement:** the owner assigned a **fresh Codex review session** in the separate worktree `..\AIS_REVIEW_T21`, detached at `54d1a6b`.
> - **Report file:** `ai-coordination/CODEX_REVIEW_REPORT.md`. Add the newest report at the top and keep the T-20 report. `AGY_REVIEW_REPORT.md` stays as history.
> - **Independence caveat:** Codex implemented T-21 phase 2 (`99c92b6`, `cc1dbf5`). A Codex review of that code isn't fully author-independent. The report must say so, and the owner decides whether it is enough.
> - **Target:** this handoff's review target is `54d1a6b`. Later commits on the branch change only `ai-coordination/*.md`.

- **Branch:** `task/T-21-instructor-assignments`
- **Exact review target:** the head of that branch at the commit that adds this section. Report the full hash.
  - Last code commit: **`e92f328`** (phase 3). Everything after it changes only `ai-coordination/*.md`.
  - **Review range:** `229618b..<head>`, where `229618b` is `main`.
  - If code changes after your review, the review has to be repeated.
- **Spec:** `docs/plans/T-21-instructor-assignments.md`, approved at `ce47dae`: §1.1 owner additions, A1–A14, §3–§10.
- **Owner decisions:**
  - Q1–Q4.
  - **DELETE response:** removal never depends on identity verification or name lookup. Remaining names are resolved after the removal commits, and a lookup failure gives `null` without rolling back.
- **Commits:**

| Commit | Author | What |
|---|---|---|
| `ce47dae` | Claude | approved plan; contracts in `app/Enrollment/Contracts/` (`FacultyIdentity`, `FacultyIdentityLookup`, `UnavailableFacultyIdentity`, `SectionSummary`, `SectionDirectory`); B-11 |
| `c810188` | Claude | Codex handoff |
| `99c92b6` | Codex (committed by Claude*) | phase 2: model, repository, services, `RepositorySectionDirectory`, controller, tests, implementation report |
| `cc1dbf5` | Codex (committed by Claude*) | DELETE names per the owner decision, plus 3 regression tests |
| `ab251da` | Claude | merge of `task/T-21-impl` |
| `e92f328` | Claude | phase 3: bootstrap (explicit `UnavailableFacultyIdentity`), the 2 routes, README, `API_CONTRACTS.md`, the comment-only fix in `FacultyStudentVisibility.php` |

\* Codex's sandbox can't write a linked worktree's git metadata. Claude committed Codex's changes unedited, after confirming Codex had exited, reviewing the changes, checking scope and running the suite (owner policy, 2026-11-20).

### Verified by Claude at `e92f328` (2026-11-20)

| Check | Result |
|---|---|
| `php tests/run.php` | **183 passed, 0 failed.** The 4 phase-2 expected failures are resolved by the wiring, with no assertion changed. |
| `php -l` | 115 files, 0 failures |
| `tests/Core/apache_check.php` | PASS, public and repository document roots |
| Real DELETE through private XAMPP Apache, both document roots, real session + CSRF (one-off probe, not committed) | anonymous 401; no CSRF 403; unassigned pair 404 "Instructor assignment not found."; missing section 404; production assign 422 "Unknown or inactive faculty member."; `instructors` `[]`; `PUT` 405 |
| Successful DELETE 200 | Not possible in production, where nobody can be assigned. Covered over real HTTP (PHP server) by Codex's seeded-store test `Assignment HTTP: reads expose instructors and seeded DELETE audits once` |
| Concurrency C6–C10 | pass within the suite (Codex: 3 rounds each) |
| Mutation checks M9–M20 | **12/12 killed**, run by Codex in a scratch copy with phase-3 wiring simulated. **Not re-run by Claude.** M17 survived at first; Codex strengthened the matrix so a denied role gets 403 before body validation, and it was killed on rerun. See `T21_IMPLEMENTATION_REPORT.md`. |

### Claude's review of `99c92b6` and `cc1dbf5` (summary)

- **Scope:** only Codex's assigned files. Contracts, bootstrap, routes, Students and Core untouched.
- **Authorization:** guard (registrar/admin + CSRF) plus `requireRole` in the service. The body allowlist is `faculty_id` only, and DELETE refuses any body field.
- **Identity:** checked before the lock (A4). Null, inactive, a mismatch or a `Throwable` all give the same 422 (A8). Unassign never calls `find()`.
- **Conflicts:** one helper for assign and section PATCH. Same term, same day, `a.start < b.end && b.start < a.end`. Every meeting counts, whatever the mode (Q1). The 409 names only the clashing section.
- **Atomicity:** the existence, duplicate, cap (5), conflict and history-append steps all happen in one `JsonStore` transaction.
- **Snapshot:** validates both new keys (exact shapes, known sections, no duplicates, at most 5, no stored conflict), checks that history `seq` is gap-free, and replays history against `assignments`. T-20 stores load unchanged.
- **A14:** `isAssigned` and `sectionIdsForFaculty` require a confirmed active identity; `assignedFacultyIds` isn't filtered.
- **T-20 tests:** the exact-response tests now assert the full key order **and** `instructors === []`. They're stricter, not weaker.
- **Findings:** none blocking. The only change was the DELETE names, required by the owner's decision.

### Focus areas for AGY

1. **RBAC and CSRF** on the two new routes; the service-level `requireRole`; faculty and students can't assign or unassign.
2. **Production fail-closed:** the only `FacultyIdentityLookup` in `app/` is `UnavailableFacultyIdentity`; the stub lives under `tests/` only; nothing selects a lookup by config or env.
3. **Conflict detection:** correctness and atomicity (C6, C9), the PATCH re-check, cross-term and back-to-back cases, and meetings of `tba` and `online_async` sections counting.
4. **Audit history:** append-only, gap-free, exactly one entry per success, none on failure; replay validation.
5. **Corrupt store handling:** a generic 500, nothing written.
6. **A14:** retained assignments of inactive faculty grant no access.
7. **DELETE:** never blocked by identity or name lookup.
8. **API compatibility with T-20** (only the additive `instructors` key) and T-00 to T-20 regression.

### Known limitations (documented)

- **Production assigns nothing until T-30.** That's by design.
- **B-11** (user id ↔ `faculty_id`) blocks T-31.
- **The A4 window:** the identity is checked before the lock, so a faculty member deactivated in those milliseconds could still be assigned.
- Replay and conflict validation scale linearly with store size (until T-40).
- L-3 (Core error keys).
- DELETE 200 is covered only with a seeded store.

### Review request

- Read-only review in a separate worktree, for example `git worktree add ..\AIS_REVIEW_T21 <head>`. Use your own `APP_RUNTIME_PATH`, and coordinate with Claude before running the Apache check.
- Write the report to `ai-coordination/AGY_REVIEW_REPORT.md`, with the exact commit and a verdict.
- Claude has no channel to AGY; the owner starts it.

---

## T-21 phase 2 → CODEX implementation (prepared by Claude, 2026-11-20) — ASSIGNED, not confirmed

- **Owner authorization:** T-21 implementation approved on 2026-11-20. Codex implements the backend and the tests.
- **Spec (authoritative):** `docs/plans/T-21-instructor-assignments.md`, approved revision at `ce47dae`. Follow §1.1, §2 (A1–A14), §3–§7, §9 and §12 exactly.
- **Baseline:** branch `task/T-21-instructor-assignments` at the commit that adds this handoff. Its code is identical to phase-1 commit `ce47dae`, which contains Claude's contracts in `app/Enrollment/Contracts/`. `main` is at `229618b`.
- **Your branch and worktree:** `task/T-21-impl` in `..\AIS_CODEX_T21`. Claude creates both from that commit. Work and commit only there.

### Files you own (phase 2; plan §12) — edit nothing else

- `app/Enrollment/Models/Section.php`
- `app/Enrollment/Repositories/CatalogRepositoryInterface.php`
- `app/Enrollment/Repositories/MockCatalogRepository.php`
- `app/Enrollment/Services/SectionService.php`
- `app/Enrollment/Services/RepositorySectionDirectory.php` (new)
- `app/Enrollment/Controllers/SectionController.php`
- `tests/Enrollment/**`: the existing files, plus the new `AssignmentServiceTest.php`, `SectionDirectoryTest.php`, `AssignmentHttpTest.php`, `FacultyIdentityPortTest.php` and `fixtures/StubFacultyIdentity.php`
- `ai-coordination/T21_IMPLEMENTATION_REPORT.md` (new)

### Files you must not touch

- `app/Enrollment/Contracts/*`. These are Claude's. If a contract change is needed, stop and report.
- `app/bootstrap.php`, `routes/enrollment.php`, `README.md`, `app/Students/**`, `app/Core/**`, `app/Auth/**`, `database/**`.
- `ai-coordination/*` except your report, and `docs/**`.
- Any other agent's worktree.

Bootstrap and routes wiring is Claude's phase 3. Until then:
- `SectionService` takes the optional `?FacultyIdentityLookup` as its third argument. `null` means `UnavailableFacultyIdentity`.
- `RepositorySectionDirectory` takes `(CatalogRepositoryInterface, FacultyIdentityLookup)`.

### Binding requirements

1. **Ownership:** Team 4 owns the assignments. Store only `{section_id, faculty_id}`, with no faculty data copied. Names come from `FacultyIdentityLookup::findMany` at read time; if it fails, `name` is `null` (A9).
2. **Fail closed in production:** with `UnavailableFacultyIdentity`, every assign gets 422 and nothing is written. Test doubles exist **only** under `tests/`; never in `app/`, never selected by config or env.
3. **Identity refusal:** null, inactive, an id mismatch or any `Throwable` all give 422 `{faculty_id: "Unknown or inactive faculty member."}` (A8). The lookup runs before the lock (A4). Unassign never calls the lookup (A7).
4. **Conflict rule:** same `term_id`, any overlapping meeting (same day, `a.start < b.end && b.start < a.end`) gives **409**. Every actual meeting counts, including those of `tba` and `online_async` sections (Q1). Sections with no meetings never conflict. The 409 names only the clashing section.
   - The check runs atomically in the single store lock, as one helper shared by `assignInstructor` and `updateSection` (A3, A5).
   - A section PATCH that would create a conflict gives 409 and writes nothing.
5. **Cap and duplicates:** at most 5 instructors per section; a 6th gives 409. A duplicate pair gives 409.
6. **Audit:** the append-only `assignment_history` (`seq` gap-free, with `at`, `action`, `section_id`, `faculty_id`, `actor_id`) is written in the same transaction. Failures write nothing.
7. **Store:**
   - New top-level keys `assignments` and `assignment_history`. If they're missing, they're empty, so T-20 stores load unchanged.
   - Validation fails closed on: an unknown section, a duplicate, more than 5, a stored conflict, malformed history, or a history replay that doesn't match `assignments` (A11).
8. **Inactive faculty (Q4, A14):**
   - Retained assignments and history are kept and shown.
   - New assignments to inactive faculty are refused.
   - `RepositorySectionDirectory::isAssigned` and `sectionIdsForFaculty` count an assignment only when `find()` returns an active identity with the same id, and fail closed otherwise. `assignedFacultyIds` isn't filtered.
9. **Authorization:** registrar/admin only (the guard is wired in phase 3), plus `requireRole` in the service. Section reads stay open to any signed-in user.
10. **T-20 tests:** update the exact-response tests for the new `instructors` key **without weakening them**: keep exact key order and value equality, and don't relax anything to a subset. Grep `tests/Enrollment/` for every exact section-shape assertion.
11. **No schema or DB changes.** No T-22, T-30 or T-31 work. `approvedStudentIds` returns `[]` with the `shortcut:` comment.

### Required tests and checks (plan §9)

- Unit tests (§9.1), directory tests including A14 (§9.2), store tests (§9.3), and the HTTP tests (§9.4).
- Concurrency races C6–C10 with worker processes (§9.5).
- The production fail-closed test and the source-scan test (§9.6).
- Mutation checks M9–M20 (§9.7), in a scratch copy **outside every worktree**.
- Run `php tests/run.php` (all 163 existing tests must still pass) and `php -l` on every PHP file.
- **Expected red until phase 3:** the HTTP route tests (§9.4) and the production-wiring test (§9.6) need Claude's bootstrap and routes wiring. List each one by name in your report. Everything else must be green.
- **Runtime:** set `APP_RUNTIME_PATH` to a scratch folder outside all worktrees and outside OneDrive. Don't run the Apache check; Claude runs it in phase 3.

### Deliverables

- Commit on `task/T-21-impl`. Commits only there; no merge, no push, no history rewriting.
- Write `ai-coordination/T21_IMPLEMENTATION_REPORT.md`, covering:
  - files changed and any deviations from the plan, with the reason
  - test counts (pass, fail, and the expected-red list)
  - the `php -l` result
  - mutation results
  - risks
  - the exact commit hash
- Then stop. Claude reviews and integrates. AGY reviews after phase 3.

---

## T-20 — MANDATORY AGY review before merge (owner decision, 2026-11-20) — REVIEWED, MERGED

> **Outcome:** AGY APPROVE on `81097d5` (`AGY_REVIEW_REPORT.md`). Merged into `main` as `8c2b980` on 2026-11-20. Not pushed.

- **Owner decision:** AGY's independent review is mandatory. T-20 may be merged locally only if all of the following hold:
  - AGY reports **APPROVE** on the **exact final revision**.
  - No Critical, High or Medium findings, and no merge blockers, remain unresolved.
  - Tests, `php -l` and the Apache check pass.
  - The tree is clean.
  - The merge brings in no unrelated changes.
- **Exact review target:** the head of `task/T-20-terms-sections` at the commit that adds this section. Report the full hash you reviewed.
  - The last code change is `0624eb7`. Everything after it changes only `ai-coordination/*.md`.
  - Review range: `592d912..<head>`.
  - If anything other than docs changes after your review, the review has to be repeated.
- **Background:** Codex reviewed `60ac236` (APPROVE) but wrote the phase-1 backend (`b04fec4`), so that review isn't fully independent of the author.
- **Not started:** Claude has no channel to AGY. The owner starts AGY. Write the report to `ai-coordination/AGY_REVIEW_REPORT.md` using its template.
- **Worktree:** review in a separate worktree, for example `git worktree add ..\AIS_REVIEW_T20 <head>`. Use your own `APP_RUNTIME_PATH`. Don't run the seeder against a shared runtime. Coordinate the Apache check, because it uses fixed ports.
  - **Priority files for an author-independent review:** `app/Enrollment/{Models,Repositories,Services,Controllers}/*`, `routes/enrollment.php`, and the Enrollment wiring in `app/bootstrap.php`.
  - **Focus areas:** the same as the Codex handoff below.
- **If the owner skips it:** T-20 merges on the strength of the Codex APPROVE, with the caveat on record.
- **Status:** not assigned to AGY. Claude has no channel to AGY; the owner starts it.
- **Re-verified at `41e8a56` (2026-11-20):** 163/163 tests, 104/104 lint, Apache PASS on both document roots, clean tree.

---

## T-20 — Terms & Sections → CODEX independent review (prepared by Claude, 2026-11-17) — REVIEWED

> **Outcome:** Codex APPROVE on `60ac236` (`CODEX_REVIEW_REPORT.md`). `60ac236..6bec6e6` changes only `ai-coordination/*.md`. The post-review integration check passed (see `T20_IMPLEMENTATION_REPORT.md` §12).

- **Status:** READY_FOR_CODEX_REVIEW.
  - The reviewer was reassigned from AGY (`UNAVAILABLE_USAGE_LIMIT`) to Codex by the owner.
  - **Not started:** Codex hasn't confirmed.
  - The AGY handoff below is superseded but kept.
- **Branch:** `task/T-20-terms-sections`
- **Exact review target:**
  - Code: **`0624eb7`**, the last commit that changes code or tests. It was verified by Claude: 163/163 tests, 104/104 lint, Apache PASS. 163/163 was re-run at `60ac236` on 2026-11-17.
  - The commits after it (`60ac236` and the Codex-reassignment commit) change only `ai-coordination/*.md`.
  - **Review range:** `592d912..task/T-20-terms-sections` (base `main` `592d912`). Report the exact head hash you reviewed.
- **Independence caveat:**
  - **Codex wrote the phase-1 backend in `b04fec4`:** `app/Enrollment/{Models,Repositories,Services,Controllers}/*`, `routes/enrollment.php`, the Enrollment wiring in `app/bootstrap.php`, and the README Enrollment section.
  - Claude later changed `Section.php`, `SectionService.php`, `SectionController.php` and `MockCatalogRepository.php` in `0624eb7`. Claude's backend-developer agent made the L-1 fix to `MockCatalogRepository.php`.
  - Codex didn't write the tests (Claude's qa-engineer), the seeder (Claude's database-engineer) or the plan (Claude's architect).
  - Please review in a fresh session, without relying on memory of writing the code, and state the caveat in the report.

### Worktree safety

- Review in a **separate worktree**. The owner can create one, for example `git worktree add ..\AIS_REVIEW_T20 task/T-20-terms-sections`. Don't use the shared working tree at `AIS_SYSTEM`.
- Claude won't edit `task/T-20-terms-sections` until the review is reported, unless the owner says otherwise.
- Test runs write runtime files, so point `APP_RUNTIME_PATH` at a scratch folder outside both trees. Don't run the seeder against a shared runtime.
- **No application source edits, commits, merges or pushes.** The only file Codex writes is `ai-coordination/CODEX_REVIEW_REPORT.md`, and only in its own worktree. The owner or Claude copies the report back.

### What to review

- **Scope:** everything in `592d912..HEAD`. The details of the original implementation and of the policy change (`delivery_mode`) are in the superseded handoffs below and in `T20_IMPLEMENTATION_REPORT.md` (§1–§11).
- **Specs:**
  - `docs/plans/T-20-terms-sections.md` (§3 API, P9, §5–§6, §14–§16)
  - `ai-coordination/API_CONTRACTS.md` (Team 4 section and `Section` shape)
  - `ai-coordination/ARCHITECTURE.md` §6 (security checklist)
- **Focus areas:**
  1. **Authorization:** RBAC and CSRF on all 7 routes. The service-level `requireRole` on writes. Anonymous, student and faculty can't write.
  2. **Mass assignment:** allowlists at the controller, service and repository layers. `approved_count`, `remaining`, `id`, `term_id`, `course_code`, `section_code`, `code` are not client-writable, and `delivery_mode` is the only new writable field.
  3. **Concurrency:** these checks all run inside one lock:
     - unique term code
     - a single current term
     - the term exists
     - unique section triple
     - capacity ≥ `approved_count`
     - the mode/meetings rule on the merged row during PATCH
  4. **Fail-closed storage:** `snapshot()` and `fromArray`, including duplicate natural keys, invalid or missing `delivery_mode`, and a `scheduled` row with no meetings. Failures write nothing.
  5. **Delivery mode:** scheduled needs at least 1 meeting; only an explicit `tba` or `online_async` may have 0; no TBA inference from an empty list; overlap unchanged for every mode.
  6. **Input validation:** meetings as `stdClass` with exact keys, HH:MM times, the Unicode control and format character filter, and array-valued query parameters refused.
  7. **Regression:** T-00 to T-11a behaviour unchanged.
  8. **Seeder:** command line only, mock repository only, never overwrites, and reports a partial failure.

### Checks to run independently

- `php tests/run.php`. Expected: 163 passed.
- `php -l` on every PHP file. Expected: 104 files.
- `php tests/Core/apache_check.php`. This needs XAMPP and must run outside a sandbox.
- Any additional authorization, contract or concurrency probes you think are needed. Keep them in your worktree or a scratch folder.

### Known limitations (already documented, no need to re-report)

- L-3: "Unknown field" errors use client-supplied keys (Core, predates T-20).
- I-3: the store accepts only the top-level keys `terms` and `sections`, so T-21 and T-22 must extend the list.
- D1: course data stored on the section.
- Whole-store reads on every request.
- Mutation M4 is equivalent: the service repeats the role check.
- Claude's security-reviewer wasn't re-run on `0624eb7`.

### Report

Write to `ai-coordination/CODEX_REVIEW_REPORT.md` using its template, with:

- task ID, branch and exact commit hash
- the tests you ran
- findings by severity, with evidence and file paths
- recommended corrections
- the independence caveat
- a verdict: `APPROVE`, `CHANGES_REQUESTED` or `BLOCKED`

Claude will treat the review as complete only after reading that report.

---

## T-20 — Terms & Sections, after policy alignment → AGY review (prepared by Claude, 2026-11-17) — SUPERSEDED (AGY unavailable; reassigned to Codex above)

- **Status:** READY_FOR_AGY_REVIEW. This replaces the `909a000` handoff below.
- **Branch:** `task/T-20-terms-sections`
- **Commits to review:**
  - `b04fec4`: the original implementation.
  - `909a000`: hash record.
  - **`0624eb7`: policy alignment (delivery_mode), the QA-found bug fix, decision records and the coordination files.**
  - The final handoff/status commit, which comes after `0624eb7`, changes Markdown only.
  - Diff base: `main` `592d912`. **Review range: `592d912..task/T-20-terms-sections`.** Pay particular attention to `909a000..0624eb7`.
- **Owner decisions applied:** Q1–Q7 (see `T20_IMPLEMENTATION_REPORT.md` §11 and plan §14–§16).

### Verified by Claude at `0624eb7` (2026-11-17)

| Check | Result |
|---|---|
| `php tests/run.php` | 163 passed, 0 failed |
| `php -l`, every PHP file | 104 files, 0 failures |
| `php tests/Core/apache_check.php` (XAMPP) | PASS, public and repository document roots |
| QA mutation checks for the new rules | all killed |

### What changed since `909a000`

- **New section field `delivery_mode`:** one of `scheduled` (the default), `tba` or `online_async`. Optional on create; PATCH can change it.
- **Meetings:**
  - `scheduled` needs 1–14 meetings.
  - `tba` and `online_async` may have 0–14.
  - An empty list is never treated as TBA (422).
  - The overlap check is unchanged.
- **PATCH:** the mode/meetings rule is checked on the merged row inside the lock (`SectionService::update`, the `$check` closure).
- **Stored rows:** `Section::fromArray` now requires a valid `delivery_mode` and at least one meeting for `scheduled` rows. A store without the field fails closed (generic 500).
- **Bug fix:** on create, a per-item meeting error no longer adds a misleading "needs a meeting" error.
- **Tests:** about 6 new or extended tests: service, HTTP over real sessions, and 6 new corrupt-store cases.
- **Decisions recorded:** future archival requirements (plan §15), the impact on T-21 and T-22 (plan §16), and the 5-instructor cap for T-21.
- **New files:** the coordination files `AGENT_*.md` and `AGY_REVIEW_REPORT.md`.

### Security-sensitive areas (delta)

1. **Mass assignment:** `delivery_mode` was added to the controller, service and repository allowlists. Check that nothing else was widened.
2. **Merged-row check:** it must run inside the store lock. A rejected PATCH must leave the file byte-identical.
3. **Strict validation:** `delivery_mode` must accept only the three exact strings. Check that `null`, `"TBA"`, arrays and other values are refused.
4. **Fail-closed rows:** `Section::fromArray` and `snapshot()` must still refuse invalid stored rows.
5. Everything listed in the earlier handoff below still applies.

### Known limitations (delta)

- Claude's own security-reviewer was **not** re-run for this change. AGY's review is the security review for it.
- Two older tests were loosened while the bug was open. The new regression test asserts the exact behaviour.
- The earlier limitations still apply: L-3, I-3, D1, store performance, and mutation M4.

### Review request for AGY

1. Read-only review of `592d912..task/T-20-terms-sections`, focusing on `909a000..0624eb7`. Check it against the plan (§3.2, P9, §14–§16) and `API_CONTRACTS.md`.
2. Independently run `php tests/run.php`, `php -l` and `php tests/Core/apache_check.php`.
   - Preferably do this in a separate worktree or clone at the branch head, because the test runs write runtime files.
   - Don't run the seeder against the shared `runtime/`.
3. Check for regressions in T-00 to T-11a.
4. Write the report to `ai-coordination/AGY_REVIEW_REPORT.md`, including the exact commit reviewed and a verdict.

---

## T-20 — Terms & Sections → AGY review (prepared by Claude, 2026-11-17) — SUPERSEDED by the handoff above

- **Status:** READY_FOR_AGY_REVIEW
- **Branch:** `task/T-20-terms-sections`
- **Commits to review:**
  - `b04fec4`: implementation, tests, seeder, plan, B-04 docs.
  - `909a000`: records the hash in the report and task board.
  - Diff base: `main` `592d912`. The review range is `592d912..909a000`.
- **Owner decision still open:**
  - The owner has mentioned support for **TBA and online asynchronous sections**. It is **not implemented**: `SectionService::meetings()` requires 1–14 meetings (plan P9 and Q3).
  - If the owner approves that change, Claude will implement it in a new commit, and **this handoff will be replaced**. AGY should then review the new commit; an approval of `909a000` wouldn't cover it.
  - The owner should say whether AGY reviews `909a000` now or waits.

### Verified by Claude at `909a000` (2026-11-17)

| Check | Result |
|---|---|
| `php tests/run.php` | 158 passed, 0 failed (re-run at `909a000`) |
| `php -l`, every PHP file | 104 files, 0 failures (run before the commit; the second commit changed only Markdown) |
| `php tests/Core/apache_check.php` (XAMPP) | PASS, public and repository document roots (run before the commit) |
| `git status` | clean |

### Implementation summary

- **Terms:**
  - `GET /api/terms` for anyone signed in.
  - `POST /api/registrar/terms` and `PATCH /api/registrar/terms/{id}` for registrar and admin, with CSRF.
  - Term `code` is unique and can't be changed. At most one term is current, switched atomically. Dates must be real, with `start < end` on the merged row. Terms carry an `enrollment_open` flag.
- **Sections:**
  - `GET /api/sections` (filters `term_id`, `course`, `search`, and `page`/`per_page`) and `GET /api/sections/{id}` for anyone signed in.
  - `POST /api/registrar/sections` and `PATCH /api/registrar/sections/{id}` for registrar and admin, with CSRF.
  - `(term_id, course_code, section_code)` must be unique; those three fields can't be changed.
  - The term must exist (422 otherwise).
  - Meetings: 1–14 per section, with no overlap between a section's own meetings.
  - Capacity is 1–1000 and never below `approved_count`. `approved_count` can't be set by clients. `remaining` is computed.
- **Storage:** one JSON store, `runtime/enrollment/enrollment.json`. Every rule that depends on other rows is checked inside one exclusive-lock transaction, and the snapshot is validated so a bad store fails closed (generic 500).
- **Not included:** T-21 (instructors) and T-22 (enrollments). There is no `instructors` key yet, and no database or schema change.
- **Full detail:** `docs/plans/T-20-terms-sections.md` and `ai-coordination/T20_IMPLEMENTATION_REPORT.md`.

### Files changed (`592d912..909a000`)

- `app/Enrollment/Models/{Term,Section}.php`
- `app/Enrollment/Repositories/{CatalogRepositoryInterface,MockCatalogRepository}.php`
- `app/Enrollment/Services/{TermService,SectionService}.php`
- `app/Enrollment/Controllers/{TermController,SectionController}.php`
- `routes/enrollment.php`
- `app/bootstrap.php` (Enrollment wiring only)
- `database/seeders/dev_sections.php`
- `tests/Enrollment/*` (6 test files plus `fixtures/enrollment_worker.php`)
- `README.md` (Enrollment section)
- `docs/plans/T-20-terms-sections.md`
- `ai-coordination/{TASK_BOARD,API_CONTRACTS,ARCHITECTURE,INTEGRATION_BLOCKERS,T20_IMPLEMENTATION_REPORT}.md`

### Security-sensitive areas to focus on

1. **RBAC and CSRF** on all 7 routes (`routes/enrollment.php`), plus the service-level `requireRole` in `TermService` and `SectionService`.
2. **Mass assignment:** check the allowlists at the controller, service and repository (`*_MUTABLE`) layers. Clients must not be able to set `approved_count`, `remaining`, `id` or the fixed fields.
3. **Locking:** these checks must all run inside the lock in `MockCatalogRepository`:
   - unique term code
   - a single current term (`clearCurrent`)
   - the term exists
   - unique section triple
   - capacity ≥ `approved_count` on the merged row (the `$check` closure)
4. **Fail-closed snapshot validation** (`snapshot()`), including the L-1 duplicate natural-key checks. Nothing should be written when a write fails.
5. **Input validation:** meetings must be `stdClass` objects with exact keys; check the HH:MM rules, the overlap rule, the Unicode control and format character filter, and that array-valued query parameters are refused.
6. **Seeder:** command line only, mock repository only, never overwrites, and the L-2 partial-failure reporting.

### Known limitations and open items

- **L-3 (Core, predates T-20):** "Unknown field" errors use client-supplied keys. The frontend must render them as text only.
- **I-3:** the snapshot allows only `terms` and `sections` as top-level keys, so T-21 and T-22 must extend that list.
- **D1:** course data is stored on the section and checked for format only.
- **Store performance:** every request reads the whole store (fine until T-40).
- **Mutation M4:** a weakened route guard isn't caught on its own, because the service-level check returns the same 403.
- **Seeder:** the partial-failure path wasn't exercised.
- **Owner questions Q1–Q7 (plan §14):** still unconfirmed. Q3 (TBA/online) is now pending as a requested change.

### Review request for AGY

1. Read-only review of `592d912..909a000`: security, RBAC, API contract against `docs/plans/T-20-terms-sections.md` §3, and concurrency and fail-closed behaviour.
2. Independently run `php tests/run.php`, `php -l` and `php tests/Core/apache_check.php`.
   - Preferably do this in a separate worktree or clone at `909a000`, because the test runs write runtime files and the working tree is shared.
   - Don't run the seeder against the shared `runtime/`.
3. Confirm that T-00 to T-11a behaviour is unchanged (regression).
4. Write the report to `ai-coordination/AGY_REVIEW_REPORT.md` using its template, with a verdict.
