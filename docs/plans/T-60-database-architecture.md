# T-60: Database architecture (Team 6), plan

- **Status: PROPOSED — not approved.** Planning and documentation only. No schema, database, account, migration, SQL execution or application change exists or is authorized. The owner approves this plan and the owner questions (§5) before any Phase 4+ work. Every phase that touches a non-test database needs its own approval.
- **Branch:** `task/T-60-database-architecture`, from `main` at `c17ee31` (184 tests green).
- **Resolves (proposed):** B-03 for the implemented modules and T-30. That unblocks T-40 (MySQL repositories).
- **Documents:**

  | Document | Contents |
  |---|---|
  | `docs/database/ARCHITECTURE.md` | Engine, collation, conventions, time, CHECK vs ENUM, deletion, transactions, seq, audit, out-of-DB data, adapter strategy |
  | `docs/database/ERD.md` | Mermaid ERD and the relationships in prose |
  | `docs/database/DATA_DICTIONARY.md` | Every table and column, source, status; proposed DDL text |
  | `docs/database/CONSTRAINTS_AND_MIGRATIONS.md` | Constraint matrix, migration order, runner, rollback, verification, JSON import |
  | `docs/database/SECURITY.md` | Accounts and GRANTs, credentials, prepared statements, test guard, backups, authorization |
  | `docs/database/BLOCKERS.md` | Blocker register, per-method compatibility, Phase 7 verification plan |

## 1. Goal and acceptance criteria

**Goal:** a reviewed, owner-approvable MySQL/MariaDB schema and an implementation route that:
- keeps every existing repository interface, contract and test unchanged;
- reproduces the mock repositories' atomicity and invariants;
- migrates the JSON stores without data loss;
- never touches a database other than `ais_test` without approval.

**Acceptance criteria for this planning task (T-60, Phases 0–3):**

1. The seven files above exist, and each starts with **"PROPOSED — not approved"**.
2. Every stored field of the implemented models appears in `DATA_DICTIONARY.md` with its type, nullability, constraints, a source reference and a status: `User`, `roles`, audit entries, `Program`, `Student`, student history, `Term`, `Section` (including `meetings` and `delivery_mode`), `assignments`, `assignment_history`. So do the T-30 `Faculty` fields (§3.1) and faculty history (§5.2), marked CP.
3. Every method of `UserRepositoryInterface`, `UserAdminRepositoryInterface`, `UserTransactionInterface`, `StudentRepositoryInterface`, `ProgramCatalogInterface`, `CatalogRepositoryInterface` and T-30's `FacultyRepositoryInterface` has a compatibility row in `BLOCKERS.md` §2.
4. `ERD.md` contains Mermaid `erDiagram` blocks. Provisional entities are visibly separate and suffixed `_PROVISIONAL`.
5. Every owner question (§5) has a proposed default.
6. `git status` shows changes only under `docs/database/` and this file. No SQL was executed, and no database or account was created.

**Exit criteria for Phases 4–10** are listed per phase in §6.

## 2. Phase 0: compatibility matrix

Key:
- **Status:** C = confirmed (implemented code), CP = confirmed by an approved plan (T-30, not implemented), P = provisional, F = stays file-based, I = infrastructure.
- **Priority** = migration and cutover order.

| Entity | Existing model / contract | Current storage | Proposed table(s) | Relationships | Unique constraints | Owning team | Priority | Status |
|---|---|---|---|---|---|---|---|---|
| User account | `App\Auth\Models\User`; `UserRepositoryInterface` | `$runtime/auth/users.json` `users[]` | `users` | 1:0..1 students, 1:0..1 faculty, 1:N audit (actor), 1:N history (actor) | `email` | 2 | 1 | C |
| Roles | `User::$roles`, `AuthorizationService::ROLES` | Embedded `users[].roles` | `user_roles` (no `roles` table, OQ-2) | N:1 users | PK `(user_id, role)` | 2 | 1 | C |
| Admin audit log | `UserTransactionInterface::appendAudit`, `auditEntries` | `users.json` `audit[]` (same lock as users) | `audit_logs` | N:1 users (actor). `target_id` has no FK | PK `id` (gap-free) | 2 | 1 | C |
| Login rate limits | `LoginRateLimiter` (concrete `JsonStore`) | `auth/login-attempts.json` | — | — | — | 2 | — | F (ARCH §9) |
| Re-auth rate limits | `LoginRateLimiter` | `auth/reauth-attempts.json` | — | — | — | 2 | — | F |
| Security events | `SecurityEventLog` (T-04, bounded ring) | `auth/security-events.json` | — | — | — | 2 | — | F |
| Sessions | `Session` (native PHP), `auth_version` check | `$runtime/sessions` | — (`users.auth_version` column covers B-09's schema need) | — | — | 2 | — | F |
| Program | `App\Students\Models\Program`; `ProgramCatalogInterface` (read-only) | `students/programs.json` | `programs` | 1:N students | PK `code` | 3 (D1 may change) | 2 | C |
| Student profile | `App\Students\Models\Student`; `StudentRepositoryInterface` | `students/students.json` `students[]` | `students` | N:1 users (UNIQUE), N:1 programs, 1:N history | `user_id`, `student_number` | 3 | 2 | C |
| Academic history | `StudentRepositoryInterface::history` | `students.json` `history{id:[…]}` | `student_academic_history` | N:1 students; `actor_id` has no FK (0 = system) | PK `(student_id, seq)` | 3 | 2 | C |
| Term | `App\Enrollment\Models\Term`; `CatalogRepositoryInterface` | `enrollment/enrollment.json` `terms[]` | `terms` | 1:N sections | `code`; at most one current (generated `current_marker`) | 4 | 3 | C |
| Section | `App\Enrollment\Models\Section` | `enrollment.json` `sections[]` | `sections` (`delivery_mode` column) | N:1 terms, 1:N meetings, M:N faculty | `(term_id, course_code, section_code)` | 4 | 3 | C |
| Meeting | `Section::$meetings` (embedded) | `sections[].meetings[]` | `section_meetings` | N:1 sections | PK `(section_id, position)`, ≤ 14 | 4 | 3 | C |
| Instructor assignment | `assignInstructor`, `assignedFacultyIds`; `SectionDirectory` | `enrollment.json` `assignments[]` | `section_instructors` (+ `assigned_seq` for order) | M:N sections ↔ faculty; 1:1 to its `assigned` history row | PK `(section_id, faculty_id)`; `assigned_seq` | 4 (B-04) | 5 | C (FK target CP) |
| Assignment history | T-21 A10/A11 | `enrollment.json` `assignment_history[]` | `section_instructor_history` | N:1 sections, faculty, users (actor) | PK `seq` (global, gap-free) | 4 | 5 | C |
| Faculty profile | T-30 plan §3.1, F1–F5; `FacultyRepositoryInterface` (planned) | Planned `faculty/faculty.json` | `faculty` | N:1 users (UNIQUE), 1:N assignments, 1:N history | `user_id`, `employee_number` | 5 | 4 | CP |
| Faculty history | T-30 §5.2 | Planned `faculty.json` `history[]` | `faculty_history` | N:1 faculty, N:1 users (actor) | PK `seq` (global, gap-free) | 5 | 4 | CP |
| Enrollment | `API_CONTRACTS.md` shape; T-22 | — (T-22 adds it to `enrollment.json`) | `enrollments` (sketch only) | N:1 students, sections; decided_by → users | one pending/approved per (student, section) | 4 | 6 (deferred) | P |
| Grade, grade history | `API_CONTRACTS.md` shape; T-32 | — | `grades`, `grade_history` (sketch only) | N:1 sections, students | `(section_id, student_id)` | 5 | 6 (deferred) | P |
| Course catalog | D1 undecided | — (`course_code` is free text on sections) | `courses` (future) | 1:N sections (future FK) | `code` | D1 | deferred | P |
| Locks and counters | `JsonStore` exclusive lock per file | `*.json.lock` | `repository_locks` | — | PK `name` | 6 | 0 | I |
| Migration tracking | — | — | `schema_migrations` | — | PK `version` | 6 | 0 | I |

## 3. Key design decisions

| ID | Decision | Rationale | Detail |
|---|---|---|---|
| D-01 | **One lock row per former JSON store** (`repository_locks`, `SELECT … FOR UPDATE`) as the write boundary | Provably equal to today's reviewed single-lock atomicity. It avoids gap-lock deadlocks, and writes are short | ARCH §6 |
| D-02 | **Gap-free `seq`** from the same lock row (global streams) or `MAX+1` under the lock (per student) | Keeps T-21 A10, T-30 §1.1-6 and tests C10/F-C03 unchanged (OQ-1) | ARCH §7 |
| D-03 | **`utf8mb4_nopad_bin` everywhere**; case-insensitive search via `LOWER() LIKE` with an escaped needle | SQL equality = PHP `===`; REGEXP CHECKs case-sensitive; accent-sensitive search parity (OQ-9) | ARCH §1.1 |
| D-04 | **CHECK, not ENUM**, for value sets; the app still validates first | Strict failure, text ordering, no silent `''` | ARCH §4 |
| D-05 | **`user_roles` + CHECK; no `roles` table** | Fixed set in code; fails closed; a table would imply configurability that doesn't exist (OQ-2) | ARCH §4 |
| D-06 | **`DATETIME` UTC, app-supplied** time; no DB clock functions; `CHAR(5)` meeting times; no DEFAULTs | Exact round trip of contract strings; deterministic tests; forgotten columns fail loudly | ARCH §2–3 |
| D-07 | **`ON DELETE RESTRICT` everywhere; no cascades; append-only history by GRANT** | No hard deletes of academic data (T-20 Q5, T-10 d3, T-30 F1); history can't be rewritten by the app | ARCH §5, SECURITY §1 |
| D-08 | **`section_instructors.faculty_id` FK → `faculty.id`**. Importer aborts on orphans. Enrollment goes to MySQL after faculty once T-30 is wired | Orphans exist only in mock/test data. In production the lookup fails closed, so there are no assignments without a profile (OQ-8) | ERD §4 |
| D-09 | **Rate limiter, security events, sessions stay file-based** | Concrete `JsonStore` dependencies; non-blocking best-effort design; independence from DB outages; B-09 deferred (OQ-3) | ARCH §9 |
| D-10 | **Adapters implement the existing interfaces unchanged**; models stay the validators; mocks stay | No service, contract or test churn; shared contract tests prove parity | ARCH §10 |
| D-11 | **Per-module backend selection** (`APP_REPOSITORY_<MODULE>`), FK-ordered, fail-closed; no dual-write; no mysql→mock fallback | Incremental cutover with a clear rollback point (OQ-14) | §4.2 |
| D-12 | **No triggers, procedures or events**; cross-row rules stay in the app, inside the lock, plus `verify.php` | Business logic stays reviewable PHP; forward-only migrations stay simple | C&M §1.4 |
| D-13 | **Narrowed "corrupt store fails closed"**: per-row model validation + constraints + privileges + `verify.php`, instead of a whole-table scan per request | The scan doesn't scale and can't be reproduced cheaply. It is an explicit owner trade-off (OQ-4) | BLOCKERS §2.5 |
| D-14 | **Provisional tables are sketches, not DDL** (T-22, T-32, D1) | B-05, B-08 and D1 are open; no invented business rules (OQ-11) | DD §6 |
| D-15 | **Forward-only, checksummed migrations** with `started`/`applied` tracking and manual recovery; backups before non-test changes | DDL isn't transactional in MariaDB | C&M §3 |

## 4. Interfaces and contracts

### 4.1 Application ↔ repository

- **No interface changes.** These stay exactly as they are on `main`, and so do `FacultyRepositoryInterface`, `FacultyIdentityLookup` and `FacultyDirectory` (T-30, as approved):
  - `UserRepositoryInterface`, `UserAdminRepositoryInterface` (+ `LAST_ADMIN_MESSAGE`), `UserTransactionInterface` (+ `AUDIT_KEYS`)
  - `StudentRepositoryInterface`, `ProgramCatalogInterface`
  - `CatalogRepositoryInterface`, `SectionDirectory`, `StudentDirectory`
- **No HTTP API change** and no frontend change. Response shapes, status codes and messages are identical in both backends (BLOCKERS §2, V7).

### 4.2 Configuration contract (Phase 4, Claude)

| Variable | Values | Rule |
|---|---|---|
| `APP_REPOSITORY` | `mock` \| `mysql` (existing) | The default for every module. Unknown → fail closed (unchanged) |
| `APP_REPOSITORY_AUTH`, `_STUDENTS`, `_ENROLLMENT`, `_FACULTY` | `mock` \| `mysql`, optional | Override per module. Unknown → fail closed |
| Combination rules | — | students, faculty or enrollment = mysql ⇒ auth = mysql. Enrollment = mysql **and** the real faculty lookup wired (T-30) ⇒ faculty = mysql. Violations → `RuntimeException('Invalid repository configuration.')` at startup |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | existing | App account only |
| `DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD` | new, placeholders in `.env.example` | **Read only by CLI tools**, never by `app/bootstrap.php` |
| `AIS_MYSQL_TESTS` | `1` or unset | MySQL tests register only when set and the guard passes |

The bootstrap removes the blanket `throw` for mysql mode only when the wiring for the selected modules exists (Phase 7.1). Until then, mysql mode keeps failing closed.

### 4.3 CLI contracts (Codex)

| Command | Account | Exit codes |
|---|---|---|
| `php database/migrate.php status\|up --target=<db>` | migrator / tester | 0 ok, 1 error, 2 refused (checksum, `started`, order, target mismatch, server prerequisites) |
| `php database/import_json.php --module=<m> --runtime=<path> --target=<db> [--dry-run]` | migrator | 0 committed (or dry run clean), 1 aborted and rolled back, 2 refused (preconditions) |
| `php database/verify.php --target=<db>` | app or any SELECT account | 0 clean, 1 violations (ids only), 2 error |

All three are CLI-only (`PHP_SAPI` check), print no values or credentials, and refuse a `--target` that differs from `DB_DATABASE` or from `SELECT DATABASE()`.

### 4.4 Database contract

- **Schema:** `DATA_DICTIONARY.md` §1–5 and §7.
- **App grants:** `SECURITY.md` §1.1.
- **Server settings:** §9.

## 5. Owner-approval questions

| # | Question | Proposed default |
|---|---|---|
| OQ-1 | Keep **gap-free** history `seq` (counter row) or relax to strictly increasing (AUTO_INCREMENT)? | **Keep gap-free.** No change to the T-21/T-30 contracts |
| OQ-2 | Drop B-03's `roles` table in favour of `user_roles(role CHECK …)`? | **Yes, no `roles` table** |
| OQ-3 | Keep the rate limiter, security events and sessions file-based? | **Yes.** Revisit only for a multi-server deployment |
| OQ-4 | Accept the narrowed "corrupt store fails closed" (per-row validation + constraints + append-only grants + `verify.php`)? When does `verify.php` run? | **Accept.** Run it after every migration and import, before every backup, and in Phase 7 tests |
| OQ-5 | Standing approval for the test harness to drop and re-create tables **in `ais_test` only**? | **Yes**, confined by the guard and grants. Never any other database |
| OQ-6 | Have `Database::connect` set `sql_mode` (`STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`) and `time_zone='+00:00'` per connection? | **Yes.** Adds `app/Core/Database.php` to the T-40 file list (Claude, Phase 4) |
| OQ-7 | Write the faculty migrations (V0005/V0006) now and test them in `ais_test`, but apply them elsewhere only after T-30 is merged? | **Yes** |
| OQ-8 | At import, if an assignment or history row names a `faculty_id` with no faculty profile? | **Abort the import.** No dropping, no placeholder profiles |
| OQ-9 | Collation: `utf8mb4_nopad_bin` everywhere (search stays case-insensitive and accent-sensitive, as today), or `utf8mb4_unicode_ci` for names (accent-insensitive search, a behaviour change)? | **`utf8mb4_nopad_bin`** |
| OQ-10 | Cutover imports run with the app stopped (no maintenance mode exists)? | **Yes** |
| OQ-11 | Defer all DDL for T-22 enrollments, T-32 grades and the D1 course catalog until their plans are approved? | **Defer.** Sketches only |
| OQ-12 | MariaDB 10.4 is end-of-life. Production version? | **10.4 for local development only.** Choose a supported LTS (for example 10.11) before any production cutover, and re-run Phase 7 on it |
| OQ-13 | Names: databases `ais`/`ais_test`; accounts `ais_app`, `ais_migrator`, `ais_tester`, `ais_test_app`, `ais_backup` | **As proposed** |
| OQ-14 | Per-module selection via `APP_REPOSITORY_<MODULE>`, and amend the T-40 file list (§7) accordingly? | **Yes** |
| OQ-15 | Branching: one Codex worktree `..\AIS_CODEX_T60` on `task/T-60-impl` for Phases 5–8 (covering T-40)? Reviewer? | **Yes.** Reviewer AGY; fallback a fresh Codex session with the independence caveat |

## 6. Implementation sequence (Phases 4–10)

Phases 0–3 are this planning task: the matrix, architecture and ERD, the dictionary and constraints, and security and blockers. They end at the **owner approval gate**.

Roles:
- **Claude:** coordination, contracts, configuration, integration wiring, docs, and commits on Codex's behalf.
- **Codex:** migration tooling, SQL migrations, adapters and DB tests, in its own worktree. Codex runs **no git write commands** (sandbox limit). Claude commits Codex's work **unedited**, after checking that `git diff --name-only` matches §7 exactly, with "Codex changes, committed by Claude (sandbox git limit)".
- **Reviewer:** AGY in a read-only worktree. Fallback: a fresh Codex session, with the independence caveat stated in its report.

Each agent sets its own `APP_RUNTIME_PATH` outside every worktree and outside OneDrive. **Only `ais_test` is used through Phase 10.**

| Phase | Owner | Work | Exit criteria |
|---|---|---|---|
| **4** Contracts & environment | Claude (docs, config); **owner** (DB install and accounts) | Record the approved decisions in `ai-coordination` docs (TASK_BOARD T-60/T-40, B-03, ARCHITECTURE §5/§7, the API_CONTRACTS Student-shape supersession note for C-03, AGENT_TASKS). Add the `APP_REPOSITORY_*` parsing and combination rules to `config/app.php`, with tests. If OQ-6 is approved, add the init command to `Database::connect`. Update `.env.example` placeholders and the README Database section. The owner installs and hardens MariaDB and creates `ais_test`, `ais_tester` and `ais_test_app` per `SECURITY.md` (the `ais` database waits) | 184 + new config tests green; `php -l` clean; mysql mode still fails closed in bootstrap; owner confirms `ais_test` reachable with `ais_tester` |
| **5** Migration tooling & schema | Codex | Runner, V0001–V0006, app grant script, test guard, offline runner and guard tests, online migration, constraint and privilege tests | Offline suite green without a DB; with `AIS_MYSQL_TESTS=1`: V1–V6 green; re-run no-op; checksum tamper refused |
| **6** MySQL adapters (T-40) | Codex | 6a Auth, 6b Students, 6c Enrollment, **6d Faculty only after T-30 is merged** (otherwise deferred to a follow-up). Shared contract bodies run against mock and MySQL (V7) | V7 green for both backends; no interface or service file changed |
| **7** Wiring & verification | 7.1 Claude, then 7.2 Codex | 7.1: bootstrap creates one PDO when any module is on mysql and binds the adapters per module (mysql never falls back to mock). 7.2: concurrency (V8), fail-closed (V9) and wiring smoke (V11) tests, and the DB-M1–M9 mutation checks | V8, V9, V11 green over 3 rounds; mutations all detected; the offline suite is unchanged by default |
| **8** Import & verify tools | Codex | `import_json.php`, `verify.php`, V10 tests; a dry run against a copy of a development runtime (into `ais_test`) | V10 green; dry run of all modules clean; `verify.php` clean |
| **9** Independent review | AGY (fallback fresh Codex) | Security (grants, injection, guard, secrets, error leakage), integration and compatibility review against `BLOCKERS.md` §2 | APPROVE, or findings assigned to file owners |
| **10** Fixes, docs, handoff | File owners for fixes; Claude for docs | Fixes by the owning agent (Codex files: Claude commits them as above), re-review, final README/handoff/TASK_BOARD, merge request to the owner | Re-review APPROVE; full suite green offline and with `AIS_MYSQL_TESTS=1`; **no production cutover** (separate approval, C&M §5.5) |

## 7. File ownership (Phase 4+)

One owner per file in each phase. A file listed in two phases has the same owner in both.

| Phase | File | Action | Owner |
|---|---|---|---|
| 0–3 | `docs/plans/T-60-database-architecture.md`, `docs/database/*.md` | create | Claude (architect) |
| 4 | `ai-coordination/TASK_BOARD.md`, `INTEGRATION_BLOCKERS.md`, `ARCHITECTURE.md`, `API_CONTRACTS.md` (C-03 note), `AGENT_TASKS.md` | modify | Claude |
| 4 | `config/app.php` | modify | Claude |
| 4 | `app/Core/Database.php` (init command, if OQ-6 approved) | modify | Claude |
| 4 | `tests/Core/ConfigTest.php` (`APP_REPOSITORY_*` cases) | modify | Claude |
| 4 | `.env.example`, `README.md` (Database section) | modify | Claude |
| 4 | Local MariaDB install, `ais_test`, test accounts | run | Owner |
| 5 | `database/migrations/V0001__infrastructure.sql` … `V0006__section_instructors.sql` | create | Codex |
| 5 | `database/grants/app_grants.sql` | create | Codex |
| 5 | `database/migrate.php`, `database/tools/MigrationRunner.php` | create | Codex |
| 5 | `tests/Database/MySQLTestSupport.php`, `TestDatabaseGuardTest.php`, `TestDatabaseGuardMySQLTest.php`, `MigrationRunnerTest.php`, `MigrationMySQLTest.php`, `ConstraintMySQLTest.php`, `PrivilegeMySQLTest.php` | create | Codex |
| 5–8 | `ai-coordination/T60_IMPLEMENTATION_REPORT.md` | create, then append | Codex |
| 6a | `app/Auth/Repositories/MySQLUserRepository.php`, `MySQLUserTransaction.php` | create | Codex |
| 6a | `tests/Auth/UserRepositoryContract.php`, `UserRepositoryContractTest.php`, `UserRepositoryContractMySQLTest.php` | create | Codex |
| 6b | `app/Students/Repositories/MySQLStudentRepository.php`, `MySQLProgramCatalog.php` | create | Codex |
| 6b | `tests/Students/StudentRepositoryContract.php`, `StudentRepositoryContractTest.php`, `StudentRepositoryContractMySQLTest.php` | create | Codex |
| 6c | `app/Enrollment/Repositories/MySQLCatalogRepository.php` | create | Codex |
| 6c | `tests/Enrollment/CatalogRepositoryContract.php`, `CatalogRepositoryContractTest.php`, `CatalogRepositoryContractMySQLTest.php` | create | Codex |
| 6d | `app/Faculty/Repositories/MySQLFacultyRepository.php`, `tests/Faculty/FacultyRepositoryContract*.php` (after T-30 is merged) | create | Codex |
| 7.1 | `app/bootstrap.php` (repository selection and PDO wiring only) | modify | Claude |
| 7.2 | `tests/Auth/UserConcurrencyMySQLTest.php`, `tests/Students/StudentConcurrencyMySQLTest.php`, `tests/Enrollment/CatalogConcurrencyMySQLTest.php`, `tests/Database/fixtures/mysql_worker.php`, `tests/Database/FailClosedMySQLTest.php`, `tests/Database/WiringMySQLTest.php` | create | Codex |
| 8 | `database/import_json.php`, `database/verify.php`, `tests/Database/ImportMySQLTest.php`, `tests/Database/VerifyMySQLTest.php` | create | Codex |
| 9 | `ai-coordination/AGY_REVIEW_REPORT.md` (or `CODEX_REVIEW_REPORT.md`) | modify | Reviewer |
| 10 | `README.md`, `docs/database/*.md`, `ai-coordination/{TASK_BOARD,AGENT_HANDOFF,INTEGRATION_BLOCKERS}.md` | modify | Claude |

**Not touched by any phase:**
- every existing interface, model, service, controller and mock repository;
- `app/Core/JsonStore.php`, `LoginRateLimiter`, `SecurityEventLog`, `Session`;
- the routes and seeders.

T-40's current file list (`app/*/Repositories/MySQL*.php`, `tests/*/MySQL*`, `config/database.php`) must be **amended** to match this table (OQ-14).

## 8. Parallel vs sequential

- **Strictly sequential:** approval → 4 → 5 → 6a → 6b → 6c → 7.1 → 7.2 → 8 → 9 → 10.
  - Each step depends on the previous one's committed output: the config contract, then the schema, then the adapters, then the wiring.
  - One agent holds the Codex branch at a time.
- **6d** runs only after T-30 is merged, as a sequential follow-up. It may slot in after 6c, or after Phase 10.
- **Allowed in parallel (disjoint files):**
  - Claude's doc-only edits under `docs/database/` and `ai-coordination/` (excluding the Codex report) may run while Codex works on Phases 5, 6 or 8.
  - The owner's DB installation (Phase 4) may run in parallel with Claude's Phase 4 file edits.
- **Never in parallel:**
  - `app/bootstrap.php` with any other task: T-30 phase 3 also edits it, so whichever comes second rebases.
  - Two agents running MySQL tests against the same `ais_test` at the same time: tests reset tables. If two agents need a DB, the owner creates a second, separately guarded database, which needs an amendment to the guard rule.

## 9. Database installation requirements (Windows / XAMPP)

The owner runs these steps after approval. Agents don't.

1. **Engine:** XAMPP MariaDB 10.4.32 (`C:\xampp\mysql\bin\mysqld.exe`, `mysql.exe`, `mysqldump.exe`). Start it from the XAMPP Control Panel. It is not running today.
2. **`my.ini`** (`C:\xampp\mysql\bin\my.ini`), `[mysqld]`:
   - `bind-address=127.0.0.1`
   - `character-set-server=utf8mb4`, `collation-server=utf8mb4_nopad_bin`
   - `default-time-zone='+00:00'`
   - `sql_mode=STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` (XAMPP's shipped value may be non-strict)
   - `innodb_strict_mode=ON`; leave `transaction_isolation` at REPEATABLE-READ
   - Restart, then check with `SELECT @@version, @@sql_mode, @@time_zone, @@collation_server;`.
3. **Hardening** (`SECURITY.md` §8): set the root password; remove anonymous accounts and the `test` database (destructive to system tables, so the owner approves); restrict phpMyAdmin.
4. **Databases and accounts:** `SECURITY.md` §1.1. Create `ais_test` and the test accounts first. Create `ais`, `ais_app`, `ais_migrator` and `ais_backup` only when Phase 8 dry runs pass.
5. **PHP:** check that the PHP binary used for `php tests/run.php` and by Apache has `pdo_mysql` and `mysqlnd` (`php -m`). Both are confirmed present today for the CLI.
6. **Data directory and backups:** XAMPP keeps data under `C:\xampp\mysql\data`, which is outside OneDrive. Keep backups and JSON copies outside OneDrive as well.
7. **Port 3306** must be free. If not, set `port=` in `my.ini` and `DB_PORT` in `.env`.
8. **`lower_case_table_names`:** 1 on Windows (the default). All names are lowercase by convention, so the schema is portable.

## 10. Risks

| Risk | Mitigation |
|---|---|
| Narrowed corruption detection (no per-request snapshot scan) | Constraints, append-only grants, per-row model validation, `verify.php` schedule (OQ-4) |
| Coarse store locks limit write throughput (T-22 peaks) | Equal to today's. T-22 may propose finer locks with its own concurrency tests |
| `LOWER()` vs `mb_strtolower` edge cases | Documented limit; contract tests on ASCII and accented Latin |
| The audit secret filter drifts between mock and MySQL | Shared contract test on both (SECURITY §4) |
| Partially applied migration (non-transactional DDL) | `started` tracking, refusal, manual recovery, backups |
| Test suite hits a real database | Six-condition guard, test-only grants, `DB-M5` mutation |
| FK coupling forces cutover order (auth → students → faculty → enrollment) | Combination rules fail closed at startup |
| MariaDB 10.4 EOL | Development only (OQ-12) |
| Writes lost when rolling back after go-live (no dual-write) | Go/no-go gate; the JSON freeze copy; production cutover needs a separate approval |
| `app/bootstrap.php` contention with T-30 | Sequencing (§8) |

**Destructive operations that need owner approval:**
- system-table hardening (anonymous users, the `test` DB);
- the standing `ais_test` reset (OQ-5);
- any `MANUAL RECOVERY` or backup restore;
- any `DROP` grant to the migrator;
- any forced `auth_version` bump at cutover.

None is executed by this plan.

## 11. Architectural conflicts found

| # | Conflict | Proposed handling |
|---|---|---|
| C-01 | `config/app.php` has one global `APP_REPOSITORY`. Per-module selection needs a config change | §4.2, OQ-14 (Claude, Phase 4) |
| C-02 | T-40's file list omits `app/bootstrap.php`, `app/Core/Database.php`, `config/app.php`, `.env.example`, `database/**`, `tests/Database/*` and the contract test files. Its "no schema DDL in application code" rule puts the migrations under `database/` | Amend T-40 (§7, OQ-14) |
| C-03 | `API_CONTRACTS.md` says Student `academic_status` is `regular\|irregular\|on_leave\|graduated\|withdrawn`, with year 1–6 and an `email?` field. Enrollment rule 1 says "regular/irregular". The implemented T-10 contract is `active\|inactive\|graduated\|transferred\|on_leave`, with year ≤ program max (≤ 8) and no email | The schema follows the code. Claude adds a supersession note (Phase 4). **T-22 must decide which statuses may enroll** |
| C-04 | B-03 asks for a `roles` table and Team 5 "teaching assignments" | No `roles` table (OQ-2). Assignments = Team 4's `section_instructors` (B-04) |
| C-05 | The T-21 T-40 note's `section_instructors PK(section_id, faculty_id)` loses the "assignment order" contract | Add `assigned_seq` (UNIQUE, FK to history) |
| C-06 | B-04 says "no Team 6 schema changes", while the `faculty_id` FK couples Team 4's table to Team 5's | Read as "no schema needed for ownership". The FK is integrity only, but it fixes the cutover order (D-08) |
| C-07 | The T-30 T-40 note ("`findMany` becomes one query joining `users`/`user_roles`") contradicts ARCHITECTURE.md §4 (no cross-module table reads). Closing the A4 window in SQL would hit the same rule | Keep F14 (interface reads). A JOIN needs an explicit owner exception |
| C-08 | Mock whole-snapshot validation per read can't be reproduced per request in SQL | OQ-4 |
| C-09 | ARCHITECTURE.md §5 says writes use `repository->transaction(callable)`, but only Auth exposes that. The others are atomic per method | Follow the actual interfaces; Claude fixes the wording (Phase 4) |
| C-10 | ARCHITECTURE.md §2 lists "assignments" under Faculty | Superseded by B-04. Doc fix (Phase 4) |
| C-11 | Actor conventions differ: student history `actor_id` 0 = system; assignment and faculty history ≥ 1; audit `target_id` 0 | Kept as is. No FK where 0 is allowed |
| C-12 | MariaDB 10.4 is end-of-life | OQ-12 |
| C-13 | T-40 text asks for "row locks for last-admin, capacity and grade submission" | Met by the store lock rows (coarser, equivalent). Grade submission waits for T-32 |

## 12. Summary

- **Plan:** this file. **Design:** `docs/database/{ARCHITECTURE,ERD,DATA_DICTIONARY,CONSTRAINTS_AND_MIGRATIONS,SECURITY,BLOCKERS}.md`.
- **Tables:**
  - **Confirmed (13):** `users`, `user_roles`, `audit_logs`, `programs`, `students`, `student_academic_history`, `terms`, `sections`, `section_meetings`, `section_instructors`, `section_instructor_history`, `repository_locks`, `schema_migrations`.
  - **Confirmed by approved plan (2):** `faculty`, `faculty_history`.
  - **Provisional, not for creation:** `enrollments`, `grades`, `grade_history`, `courses`.
- **Phases:** 0–3 (this plan) → owner gate → 4 Claude/owner → 5–6 Codex → 7 Claude then Codex → 8 Codex → 9 reviewer → 10 fixes and handoff. No production cutover.
- **Open:** OQ-1 to OQ-15; B-05, B-08, D1 and T-30 implementation authorization.
