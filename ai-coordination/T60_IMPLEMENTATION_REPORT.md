# T-60 implementation report

## T-60 review fixes (A) and Phase 6c (B), 2026-11-21

Baseline: `task/T-60-impl` at `82d3e24`. No Git write, database connection, reset, migration execution, app wiring, or cutover was performed.

### Part A: review fixes, tests only

Files: `tests/Database/MySQLTestSupport.php`, `tests/Auth/UserRepositoryContract.php`, `tests/Auth/UserRepositoryContractMySQLTest.php`, `tests/Students/StudentRepositoryContract.php`, `tests/Students/StudentRepositoryContractMySQLTest.php`, and this report.

| Finding | Test behavior |
|---|---|
| M1 | `connectApp()` requires `AIS_MYSQL_TEST_APP_PASSWORD`, connects as `ais_test_app`, checks the live test schema/port/account, and requires `CURRENT_USER()` to start `ais_test_app@`. The user, student, and program adapters use that connection; owner connection remains for reset, migrations, and fixtures. An unset app password makes an enabled contract case fail loudly. |
| M2 | Shared user and student search cases assert that `%` alone and `_` alone each find exactly the literal fixture row. |
| L3(a) | The owner injects a colliding audit id. An app transaction changes a user and advances the audit counter before the duplicate-key failure; the test expects a sanitized exception, unchanged user, unchanged counter, and only the injected audit row. |
| L3(b) | The owner inserts a valid history row at the maximum `INT UNSIGNED` seq. A profile update succeeds before its history insert exceeds that range; the test expects a sanitized exception and unchanged profile/history. |
| L3(c) | A non-UTC audit timestamp is rejected without moving `last_seq` or adding an audit row. |
| L3(d) | The owner temporarily disables CHECK enforcement for its own session, corrupts a student history value, restores enforcement, and verifies that an app read fails closed. |

Deviations: none in production behavior. The new fault-injection cases are MySQL-only and remain unverified until an online run.
Counts: four new MySQL-only cases; the two wildcard checks extend existing shared cases, so Part A adds no offline test cases.

### Part B: catalog adapter and shared contract

Files: `app/Enrollment/Repositories/MySQLCatalogRepository.php`, `tests/Enrollment/CatalogRepositoryContract.php`, `tests/Enrollment/CatalogRepositoryContractTest.php`, `tests/Enrollment/CatalogRepositoryContractMySQLTest.php`, and this report.

| Mock behavior | MySQL behavior |
|---|---|
| Terms ordered by start date/id; exact code 409; one current term; merged `$check` in the lock | Prepared reads and writes, `repository_locks('enrollment') FOR UPDATE`, the same validations/messages and current switch in one transaction |
| Sections use a unique term/course/section triple, known term, valid delivery mode/meetings, capacity check, computed `remaining` | In-lock checks, `Section::fromArray`, persisted fields only, meetings replaced through `position`, and `remaining` computed by the model |
| Search filters, case-insensitive literal substring, total, ordering and pagination | Exact filters, escaped `LOWER(...) LIKE ? ESCAPE '!'`, ordered id page and consistent read snapshot |
| Assignment order, section ids for faculty, five-instructor cap, conflicts on assign/PATCH | `assigned_seq` order; same 404/409 messages; same-term meeting overlap checked under the lock, including all delivery modes; back-to-back permitted |
| One history row per successful assignment change, gap-free global seq | Sequence from the locked enrollment row; history and current assignment commit or roll back together |

The shared contract runs against the mock offline and MySQL online. The online fixture creates referenced users and faculty through the owner connection, then constructs the repository with the guarded app connection. MySQL-only cases inspect history sequence, rollback after an absent faculty FK, and approved-count capacity protection.
Counts: six shared cases (six offline mock cases and six online MySQL cases), plus three MySQL-only cases.

Deviations: `section_instructors.faculty_id` and history actor/faculty ids have foreign keys, so a direct repository call with a missing faculty or actor fails with generic `RuntimeException('Catalog repository unavailable.')`; the JSON mock accepts such ids. SQL `DATETIME` accepts the service's UTC-seconds timestamps, while the mock can store arbitrary strings. Entity ids use AUTO_INCREMENT and may have gaps after rollback; assignment history seq remains gap-free. SQL reads validate returned rows and meeting positions; they do not replay the complete history as the mock snapshot does. The MySQL fixture's faculty inserts are test setup, not a faculty repository.

### Verification

- `APP_RUNTIME_PATH=C:/Users/bhill/AppData/Local/Temp/t60_runtime`, outside OneDrive; `AIS_MYSQL_TESTS=0`.
- Offline suite: **211 passed, 0 failed, 211 total** (205 baseline plus six catalog mock cases).
- `php -l` on **138 PHP files**: **0 failures**.
- Online contract and fault-injection cases: **NOT RUN**. The sandbox cannot reach MariaDB; no connection was attempted.

---

# T-60 Phase 5 implementation report (2026-11-21)

## Files

- `database/migrations/V0001__infrastructure.sql` through `V0006__section_instructors.sql`: confirmed DDL from `docs/database/DATA_DICTIONARY.md` §7, lock-row seeds, dependency and manual-recovery headers.
- `database/grants/app_grants.sql`: per-table least-privilege grant template. Phase 5 substitutions are `ais_test` and `ais_test_app`; no grants were executed.
- `database/migrate.php`, `database/tools/MigrationRunner.php`: CLI-only, forward-only runner; CLI uses migration credentials through `Database::connect()` and is limited to loopback `ais_test` in this phase.
- `tests/Database/MySQLTestSupport.php`, `TestDatabaseGuardTest.php`, `TestDatabaseGuardMySQLTest.php`, `MigrationRunnerTest.php`, `MigrationMySQLTest.php`, `ConstraintMySQLTest.php`, `PrivilegeMySQLTest.php`: offline and opt-in MariaDB tests.
- This report.

## Design notes

- The runner splits only statements terminated by a semicolon at line end, records SHA-256 file checksums, and tracks `started` versus `applied` with statement counts. It refuses changed or missing applied files, interrupted history, gaps, late older versions, wrong target, and server prerequisites. Successful reruns are no-ops. MariaDB DDL is not transactional; recovery is manual from the migration headers and a backup.
- V0005/V0006 are refused outside `ais_test` until T-30 merges. The Phase 5 CLI currently refuses **all** non-`ais_test` targets before connecting, a deliberately narrower rule than the eventual multi-database CLI contract.
- Every test connection uses `Database::connect()` for strict `sql_mode` and UTC. The test harness requires `AIS_MYSQL_TESTS=1`, `APP_ENV=testing`, exact independent `DB_DATABASE` and `AIS_MYSQL_TEST_TARGET` values of `ais_test`, a loopback host, a test account, and matching live database, port, and account. It repeats those checks on the same connection immediately before each explicitly qualified `ais_test` table drop.

## Verification

- `APP_RUNTIME_PATH` was set to `%TEMP%\ais-codex-t60-runtime` for `php tests/run.php`.
- Offline suite: **191 passed, 0 failed, 191 total**. This includes all 186 existing tests and five new offline guard/runner tests. Shipped migrations parse offline in version order with statement counts 1, 4, 4, 4, 3, 2.
- `php -l` on all **124** PHP files: **0 failures**.
- Online tests exist for live guard and session settings; migration order, no-op rerun, and checksum tampering; REGEXP CHECK and `\\z` escaping, binary equality and escaped `LOWER() LIKE`, generated-column uniqueness, duplicates, FK and RESTRICT failures, and gap-free history sequence with competing `repository_locks` transactions; app-account privileges.
- Online tests: **NOT RUN**. `AIS_MYSQL_TESTS` and all required `DB_*` environment variables were unset. No MariaDB connection, reset, grant, or migration was executed. The privilege test also requires `AIS_MYSQL_TEST_APP_PASSWORD` after the test app account and grants are installed.

## Online verification fix

- A MariaDB 10.4.32 run reported error 1901 for `ck_users_id` on `users.id` (`AUTO_INCREMENT`). V0002 now omits that CHECK; the reported rerun applied V0001–V0006 cleanly. `ck_audit_logs_id` remains because `audit_logs.id` is not `AUTO_INCREMENT`.

## Deviations and remaining risks

- The CLI is confined to `ais_test` for this approved phase. Broader target support requires a later approved change, including the required non-test backup gate.
- The grant file is a substitution template, not an executed grant. An authorized operator must substitute the two documented test identifiers and apply it after migrations before the privilege test can run.
- MariaDB behavior, DDL compatibility, grant errors, and online assertions remain unverified until the owner supplies the guarded `ais_test` setup. `verify.php` is scheduled for Phase 8, so the Phase 5 runner cannot yet perform the post-migration cross-row verification required before future cutover.
- No commit, merge, push, production database, or app repository cutover was performed.

---

# T-60 Phase 6a implementation report (2026-11-21)

Baseline: `task/T-60-impl` at `b71884e`. No Git writes, app wiring, database connection, reset, or migration execution were performed.

## Files

- `app/Auth/Repositories/MySQLUserRepository.php`: injected-PDO adapter for lookups, search, audit reads and serialized transactions.
- `app/Auth/Repositories/MySQLUserTransaction.php`: locked transaction operations for insert, update and audit append.
- `tests/Auth/UserRepositoryContract.php`: seven shared contract cases covering every method on the three interfaces.
- `tests/Auth/UserRepositoryContractTest.php`: always-on mock fixture.
- `tests/Auth/UserRepositoryContractMySQLTest.php`: opt-in guarded `ais_test` fixture, reset and migrations before each case.
- This report (append only).

## Mock-to-MySQL behavior mapping

| Mock behavior | MySQL behavior |
|---|---|
| `findById`, `findByEmail` return a validated `User` or `null`; email is trimmed/lowercased | Prepared selects, roles ordered by role, `User::fromArray` for each returned row; missing returns `null` |
| `search` uses case-insensitive email substring, id order, total and page slice | Lowercased escaped `LIKE` pattern, `COUNT(*)` and ordered page in one read snapshot; each user hydrated and validated |
| One exclusive `JsonStore` write transaction | One PDO transaction with `repository_locks('auth') FOR UPDATE`; callback result returned; exceptions roll back |
| Active-admin count before and after; last removal 409 | Same count under auth lock; same `LAST_ADMIN_MESSAGE` and rollback |
| `tx->find` and `findByEmail` see pending writes | Reads on the same PDO connection see pending writes |
| `insert` normalizes email, checks duplicate 409, validates user | Same in-lock check and `User::fromArray`; user id from AUTO_INCREMENT; roles inserted in same transaction |
| `update` enforces allowlist and 404, duplicate 409, role replacement and `auth_version` bumps | Same checks and messages; validates original and merged rows; replaces roles in the same transaction |
| `appendAudit` allowlist and recursive secret-key check; sequential id | Same validation and entry shape; id allocated from locked `last_seq` and rolled back with the audit row |
| `auditEntries` returns oldest first with stable keys | `ORDER BY id`, decoded `changes`, exact PHP key order and UTC `at` formatting |

PDO failures become `RuntimeException('User repository unavailable.')` without SQL or driver text. Expected validation, last-admin, missing-user, and callback exceptions retain their types and messages. The repository takes only an injected PDO; it does not connect, read environment variables, or issue DDL.

## Deviations and limits

- `users.id` is AUTO_INCREMENT, so a rolled-back insert can leave an id gap; the documented interface has no gap-free entity-id promise. Audit ids remain gap-free.
- `user_roles` has no order column. SQL reads roles in byte-sorted order, matching `UserService` writes; a direct transaction call supplying unsorted roles would retain that order in the mock but return sorted roles from MySQL.
- `audit_logs.at` is DATETIME. The adapter accepts the service's UTC-seconds format and reconstructs it on read; arbitrary timestamp strings accepted by the mock cannot be preserved. The schema also requires an existing actor id through its FK, unlike the mock's in-memory audit list.
- MariaDB behavior, including constraints, search escaping, transaction isolation and error mapping, is unverified in this sandbox. Phase 7.2 owns the concurrency test.

## Verification

- `APP_RUNTIME_PATH` pointed to `%TEMP%\ais-codex-t60-phase6a-runtime`, outside OneDrive; `AIS_MYSQL_TESTS=0`.
- `php tests/run.php`: **198 passed, 0 failed, 198 total** (191 baseline plus seven new offline contract cases).
- `php -l` across **129 PHP files**: **0 failures**.
- Online contract: **NOT RUN**. When the guarded `ais_test` environment is enabled, the same seven cases reset and migrate before each run, then exercise lookup, search, writes, last-admin protection, audit shape/sequence and rollback through MySQL.

---

# T-60 Phase 6b implementation report (2026-11-21)

Baseline: `task/T-60-impl` at `a820eac`. No Git write, application wiring, database connection, reset or migration execution was performed.

## Files

- `app/Students/Repositories/MySQLStudentRepository.php`: injected-PDO student adapter with read snapshots, locked writes, and per-student academic history.
- `app/Students/Repositories/MySQLProgramCatalog.php`: read-only catalog adapter.
- `tests/Students/StudentRepositoryContract.php`: seven shared cases for both backends.
- `tests/Students/StudentRepositoryContractTest.php`: always-on mock fixture.
- `tests/Students/StudentRepositoryContractMySQLTest.php`: guarded `ais_test` fixture and an additional foreign-key case.
- This report (append only).

## Mock-to-MySQL behavior mapping

| Mock behavior | MySQL behavior |
|---|---|
| `findById`, `findByUserId` return a validated `Student` or `null` | Prepared single-row selects hydrate native integer fields and UTC timestamps in `Student::FIELDS` order, then call `Student::fromArray`; missing returns `null` |
| `search` filters program and status exactly, case-insensitive substring on number/first/last name, ordered id pages and total | Fixed SQL predicates with escaped `LOWER(...) LIKE`, `ORDER BY id`, count and page in one read transaction; returned rows pass the model |
| One exclusive JSON-store lock per create/update | One PDO transaction obtains `repository_locks('students') FOR UPDATE`; all checks, writes and history append occur before commit; any exception rolls back |
| `create` returns both duplicate error keys when both collide | Both in-lock lookups run before insert; the same 409 `Validation failed.` with the same field messages; insert uses AUTO_INCREMENT |
| `update` rejects empty/immutable changes, 404s missing rows, and calls `$check` on the merged row inside the lock | Same allowlist and exceptions; `$check` runs after locked read and before model validation or SQL write; callback exceptions propagate unchanged |
| Creation and update add one history entry per changed academic field, in field order; `actor_id=0` means system | Inserts share the profile transaction and allocate `MAX(seq)+1` under the students lock; history returns typed `year_level`, stable keys, seq order, page and total |
| Program catalog exact lookup and list | Prepared binary-collation lookup returns `Program::fromArray` or `null`; list is ordered by code and each row passes the model |

All driver failures are mapped to generic `RuntimeException` messages without SQL or driver text. The adapters take only an injected PDO, read no environment variables, open no connections, and issue no DDL.

## Deviations and limits

- `students.id` is AUTO_INCREMENT; a rolled-back insert can leave an entity-id gap. Academic history `seq` remains gap-free.
- The SQL foreign keys require an existing user and program. Direct mock repository calls do not check these relationships; the service checks program existence and account identity before repository calls.
- SQL `DATETIME` preserves the service's UTC-second timestamp format. Arbitrary strings accepted by the mock model cannot be stored in SQL.
- `MySQLProgramCatalog::all()` sorts by code, whereas the JSON catalog retains file order. The current caller sorts programs by code. Rare Unicode case mappings may differ between SQL `LOWER()` and PHP `mb_strtolower`.
- SQL reads validate returned rows rather than the entire store. The schema and app privileges prevent most corruption; cross-row verification remains Phase 8 work.

## Verification

- `APP_RUNTIME_PATH` was set to `%TEMP%\ais-codex-t60-phase6b-runtime`, outside OneDrive; `AIS_MYSQL_TESTS=0`.
- `php tests/run.php`: **205 passed, 0 failed, 205 total** (198 baseline plus seven new offline contract cases).
- `php -l` on all **134** PHP files: **0 failures**.
- Online contract: **NOT RUN**. When enabled, each of the seven shared cases plus the MySQL-only foreign-key case resets and migrates guarded `ais_test`, then seeds referenced users and programs through the tester connection before exercising the adapters. No MariaDB connection was attempted in this sandbox.

---

# T-60 Phase 7.2 implementation report (2026-11-21)

Baseline: `task/T-60-impl` at `4a276ad`. Tests only; no Git writes, application changes, wiring, or MariaDB connection.

## Files created

- `tests/Database/fixtures/mysql_worker.php`: one JSON result per separate PHP process, real adapter calls as `ais_test_app`, bounded start waits and session lock timeouts. T3 additionally uses a guarded tester connection for the deliberate inverted lock order.
- `tests/Database/MySQLConcurrencySupport.php`: guarded fixture reset and migrations, identity seeding, file barriers, parent-held store locks, bounded process polling, hard process timeout and cleanup.
- `tests/Auth/UserConcurrencyMySQLTest.php`: A1–A4.
- `tests/Students/StudentConcurrencyMySQLTest.php`: S1–S4.
- `tests/Enrollment/CatalogConcurrencyMySQLTest.php`: C1–C6.
- `tests/Database/TransactionIntegrityMySQLTest.php`: T1–T4.
- This report: append only.

## Per-invariant mapping

| Invariant | Test name | Online result |
|---|---|---|
| A1 | MySQL A1: concurrent user field updates preserve both changes | NOT RUN |
| A2 | MySQL A2: competing role sets stay atomic and version bumps match | NOT RUN |
| A3 | MySQL A3: simultaneous admin demotions preserve one active admin | NOT RUN |
| A4 | MySQL A4: audit ids and lock counter include committed writes only | NOT RUN |
| S1 | MySQL S1: student updates of separate fields are both kept | NOT RUN |
| S2 | MySQL S2: student history has one gap-free entry per tracked change | NOT RUN |
| S3 | MySQL S3: duplicate student number and account races reject losers | NOT RUN |
| S4 | MySQL S4: thrown check leaves no partial student row or history | NOT RUN |
| C1 | MySQL C1: concurrent current-term switches leave exactly one | NOT RUN |
| C2 | MySQL C2: separate section patches preserve every field | NOT RUN |
| C3 | MySQL C3: overlapping faculty assignments admit one winner | NOT RUN |
| C4 | MySQL C4: fifth-instructor race enforces the cap | NOT RUN |
| C5 | MySQL C5: assignment history is gap-free and replays to current rows | NOT RUN |
| C6 | MySQL C6: meeting patch and assignment cannot create conflict | NOT RUN |
| T1 | MySQL T1: injected mid-operation failures roll back all affected tables | NOT RUN |
| T2 | MySQL T2: commit and rollback both release the auth lock | NOT RUN |
| T3 | MySQL T3: one cross-store deadlock victim rolls back and survivor commits | NOT RUN |
| T4 | MySQL T4: lock timeout is sanitized and writes nothing | NOT RUN |

Each group runs three rounds; S3 tests both duplicate keys and T2 tests both commit and rollback in every round. Registration requires `AIS_MYSQL_TESTS=1`, `AIS_MYSQL_TEST_APP_PASSWORD`, and `MySQLTestSupport::enabled()`. The existing offline registration count is unchanged.

## Synchronization and limits

The parent uses `ais_tester` to begin a transaction and hold the relevant `repository_locks` row `FOR UPDATE` before releasing worker start flags. Workers publish ready and attempt flags, then invoke adapters on independent connections. The parent confirms all processes remain live while its lock is held, commits, collects one JSON line per worker, and verifies final rows through the tester connection. Polls are bounded and processes are killed on timeout. Worker sessions set `innodb_lock_wait_timeout`; parent transactions and worker connections are cleaned up in `finally`.

T3 creates a deterministic two-row lock cycle: the auth adapter holds `auth` and its callback requests `students`, while the student adapter holds `students` and its `$check` requests `auth`. The extra row locks require tester privileges. Exactly one 1213 victim and one commit are asserted, along with sanitized exception and full rollback of the victim's user/audit or student/history writes. Adapter-only deadlock absence is **argued from the current one-store-lock-per-operation design, not proven** by this deliberately cross-store test. T4 holds `auth` until the worker's bounded lock wait ends; an independent raw lock probe on the same app connection confirms server code 1205 after the repository has returned its sanitized failure.

Worker scheduling after the parent releases the lock cannot be fixed portably. Tests accept either winning order and assert the required final invariant. The harness prints each round's observed worker completion order by job index; completion order is not asserted to be database lock-acquisition order. No online orderings have yet been observed. The live-process poll confirms pending calls but does not independently inspect `INNODB_TRX` or prove each worker has reached InnoDB; the start and attempt flags plus parent-held lock constrain the race. T2's follow-up write uses the same worker connection after each commit or rollback to test transaction closure before process exit.

## Verification

- `APP_RUNTIME_PATH` used `%TEMP%\ais-codex-t60-phase72-runtime`, outside OneDrive; `AIS_MYSQL_TESTS=0`.
- `php tests/run.php`: **211 passed, 0 failed, 211 total**.
- `php -l` across **144 PHP files**: **0 failures**.
- Online MariaDB tests: **NOT RUN** because MariaDB is unreachable from this sandbox. The PASS/FAIL status of every invariant remains unverified until the guarded `ais_test` run.

Fixture correction: every concurrency user now has one valid role: users 1-2 `admin`, 3-8 `faculty`, and 9-12 `student`. A2's two allowed final role sets and unchanged role-only `auth_version` expectation still apply. T3's auth worker can now reach its bounded cross-lock barrier after loading user 3; online behavior remains unverified here.
