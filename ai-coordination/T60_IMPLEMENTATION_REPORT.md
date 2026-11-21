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
