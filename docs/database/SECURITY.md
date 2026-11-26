# Database security (T-60)

> **Status: CONDITIONALLY APPROVED (owner, 2026-11-21; safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0). Implementation and testing against `ais_test` only; no cutover. Original note:** The SQL below is **text only**: nothing has been executed. The owner (or someone the owner names) runs account and database creation on their own machine after approval. Agents never run it.

## 1. Accounts: least privilege, never root

| Account | Used by | Database | Purpose |
|---|---|---|---|
| `ais_app`@`127.0.0.1` | The web app (`DB_USERNAME`/`DB_PASSWORD`) | `ais` | Runtime reads and writes, nothing else |
| `ais_migrator`@`127.0.0.1` | `database/migrate.php`, `import_json.php` (`DB_MIGRATION_USERNAME`/`DB_MIGRATION_PASSWORD`) | `ais` | Schema changes and the one-off import |
| `ais_tester`@`127.0.0.1` | The MySQL test suite and the test-mode runner | `ais_test` **only** | Create, reset and exercise the test schema |
| `ais_test_app`@`127.0.0.1` | The privilege tests (Phase 7) | `ais_test` **only** | The same grants as `ais_app`, to prove the app grants are enough and not too much |
| `ais_backup`@`127.0.0.1` | `mysqldump` | `ais` | Read-only backups |

Rules:

- **Host:** `127.0.0.1` only; `localhost` and `::1` are refused (§6 condition 4). MariaDB binds to `127.0.0.1` (§8), and `DB_HOST` defaults to `127.0.0.1`, which is TCP, so every connection matches the `@'127.0.0.1'` accounts that hold the grants. No `@'localhost'`, `@'::1'` or `@'%'` account is created.
- **No account has `GRANT OPTION`, `SUPER`, `FILE`, `PROCESS`, `RELOAD`, `SHUTDOWN` or global privileges.**
- **`root`** is used only by the owner for account creation and recovery. It gets a strong password; it is never placed in `.env` or used by the app, the tools or the tests.
- **Passwords** are long and random, stored in the owner's password manager, and are different per account and per machine.

### 1.1 Proposed GRANTs (PROPOSED — text only)

```sql
-- PROPOSED — not approved. Owner runs as root after approval. Replace <...> from a password manager.
CREATE DATABASE ais      CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin;
CREATE DATABASE ais_test CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin;

CREATE USER 'ais_app'@'127.0.0.1'      IDENTIFIED BY '<app-secret>';
CREATE USER 'ais_migrator'@'127.0.0.1' IDENTIFIED BY '<migrator-secret>';
CREATE USER 'ais_tester'@'127.0.0.1'   IDENTIFIED BY '<tester-secret>';
CREATE USER 'ais_test_app'@'127.0.0.1' IDENTIFIED BY '<test-app-secret>';
CREATE USER 'ais_backup'@'127.0.0.1'   IDENTIFIED BY '<backup-secret>';

-- Migration account: schema changes and import. No DROP (granted temporarily, by root, only for an approved recovery).
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES ON ais.* TO 'ais_migrator'@'127.0.0.1';

-- Test accounts: confined to ais_test.
GRANT ALL PRIVILEGES ON ais_test.* TO 'ais_tester'@'127.0.0.1';

-- Backup account.
GRANT SELECT, SHOW VIEW, LOCK TABLES ON ais.* TO 'ais_backup'@'127.0.0.1';
```

**App grants are per table,** applied after the tables exist (a separate script, Phase 5: `database/grants/app_grants.sql`, parameterized by the schema name so the same text grants `ais_test_app` on `ais_test`):

| Table | `ais_app` privileges | Why not more |
|---|---|---|
| `users` | SELECT, INSERT, UPDATE | No account deletion path |
| `user_roles` | SELECT, INSERT, DELETE | The role set is replaced on update. History of roles is in `audit_logs` |
| `audit_logs` | SELECT, INSERT | **Append-only** |
| `programs` | SELECT | No app write path |
| `students` | SELECT, INSERT, UPDATE | No hard delete (T-10 decision 3) |
| `student_academic_history` | SELECT, INSERT | **Append-only** |
| `terms`, `sections` | SELECT, INSERT, UPDATE | No hard delete (T-20 Q5) |
| `section_meetings` | SELECT, INSERT, DELETE | PATCH replaces the meeting list |
| `section_instructors` | SELECT, INSERT, DELETE | Unassign. The record is kept in history |
| `section_instructor_history` | SELECT, INSERT | **Append-only** |
| `faculty` | SELECT, INSERT, UPDATE | No hard delete (T-30 F1) |
| `faculty_history` | SELECT, INSERT | **Append-only** |
| `enrollments` | SELECT, INSERT, UPDATE | No hard delete; drop is a status change (T-22) |
| `enrollment_history` | SELECT, INSERT | **Append-only** |
| `grades` | SELECT, INSERT, UPDATE | No hard delete (T-32) |
| `grade_history` | SELECT, INSERT | **Append-only** (table-level grant also covers V0009's remarks columns) |
| `repository_locks` | SELECT, UPDATE | Lock and counter only |
| `schema_migrations` | SELECT | Optional, for a future schema-version check. No write |

`database/grants/app_grants.sql` holds exactly these 19 GRANT statements (46 table privileges) for all 18 V0001–V0009 tables plus `schema_migrations`. V0010 adds only CHECK constraints (`ck_grades_value`, `ck_gh_value_scale`; demo grade scale), so the grants are unchanged; the file header says "Apply only after V0001-V0010".

```sql
-- PROPOSED — text only. Example rows; the full script mirrors the table above.
GRANT SELECT, INSERT, UPDATE ON ais.users TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON ais.user_roles TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT ON ais.audit_logs TO 'ais_app'@'127.0.0.1';
GRANT SELECT ON ais.programs TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON ais.students TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT ON ais.student_academic_history TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON ais.terms TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON ais.sections TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON ais.section_meetings TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON ais.section_instructors TO 'ais_app'@'127.0.0.1';
GRANT SELECT, INSERT ON ais.section_instructor_history TO 'ais_app'@'127.0.0.1';
GRANT SELECT, UPDATE ON ais.repository_locks TO 'ais_app'@'127.0.0.1';
-- after V0005: GRANT SELECT, INSERT, UPDATE ON ais.faculty ...; GRANT SELECT, INSERT ON ais.faculty_history ...
```

**What the grants buy:** a bug or an injected query running as `ais_app` can't:
- drop or alter tables;
- delete students, terms, sections or users;
- rewrite or delete any history or audit row.

That partly replaces the mock's "replay on every read" protection (`BLOCKERS.md` §2). Phase 7 tests these grants with `ais_test_app`: each forbidden statement must fail with error 1142.

## 2. Credentials only in the environment

- **Env vars only:** `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (existing, `config/database.php`), plus `DB_MIGRATION_USERNAME`/`DB_MIGRATION_PASSWORD`, which **only the CLI tools read**. The web bootstrap never loads migration credentials.
- **`.env`** is git-ignored (T-00). `.env.example` keeps placeholders only (`replace_with_…`).
- **Test runs** set `DB_DATABASE=ais_test` and the tester credentials in the **process environment** of the test command, not in the shared `.env` (Environment: the process env overrides `.env`).
- **Never in the repo or the docs:** no password, DSN with a password, or real host appears in the repository, in docs, in test output or in commit messages.
- **Rotation:** change the password in MariaDB (`ALTER USER`), then in `.env`. The app needs a restart only for PHP-FPM; Apache mod_php reads `.env` on every request.

## 3. Prepared statements only

- **Every value is bound:** `PDO::prepare` + `execute` with bound values. `EMULATE_PREPARES=false` (already in `Database::connect`), so the server prepares the statements.
- **Identifiers** (table and column names, `ORDER BY` columns) are constants in the adapter code. They never come from input or config.
- **`LIMIT`/`OFFSET`** are bound as `PDO::PARAM_INT` from the already-validated `page`/`per_page`.
- **`LIKE`:** the needle is escaped for `%`, `_` and the escape character, using `ESCAPE '!'`. A non-backslash escape avoids `NO_BACKSLASH_ESCAPES` ambiguity, and the result keeps exact `str_contains` semantics.
- **`IN (...)` lists** (`assignedFacultyIds`, `findMany`) are built from `?` placeholders counted from a validated `list<int>`.
- **`MULTI_STATEMENTS`** stays off (`Database.php:36`).
- **Review rule (Phase 9):** any string concatenation into SQL text other than constant fragments and placeholders is a High finding.

## 4. No secrets in audit or event rows

- **`audit_logs.changes`:** the MySQL `appendAudit` applies **the same** checks as `MockUserTransaction::appendAudit`, before the INSERT:
  - `outcome` ∈ {success, denied};
  - top-level keys ⊆ `UserTransactionInterface::AUDIT_KEYS`;
  - recursive refusal of keys matching `/pass|hash|token|secret|csrf|session|cookie/i`.

  **Drift risk:** that regex is a `private const` in the mock. Phase 6 copies it, and a shared contract test (Phase 7) asserts that both implementations refuse the same inputs. Extracting it to one place would be a Team 2 refactor and is not proposed.
- **History tables** hold only ids, enum values, codes, year levels and (faculty) department text. They never hold emails, names, hashes or free input beyond `department`.
- **Security events stay in the file log** (`ARCHITECTURE.md` §9). Nothing about them changes.
- **The database stores `password_hash` only.** It is selected only to build `User`, never logged, never part of `verify.php` or importer output.

## 5. Error handling that leaks nothing

- **Connection failures:** `Database::connect` already turns them into `RuntimeException('Database connection failed.')` and drops the original exception (credentials).
- **Query failures:** adapters catch `PDOException` and throw a generic `RuntimeException('<Module> repository unavailable.')`, **without chaining the original**, so no SQL, values or SQLSTATE can reach a response or the log. `ErrorHandler` logs the exception class only and returns the generic 500 (`ErrorHandler.php:31-34`). Fixed `HttpException` messages (404/409/422) pass through unchanged. *Trade-off (review L1):* the log can't tell a database outage from corrupt data. A future safe diagnostic, such as a SQLSTATE-only log line, needs owner approval.
- **No `error_log` calls containing SQL, parameters or row data** in adapters or tools.
- **CLI tools** print version names, table names, counts and offending **ids** only, and exit non-zero on failure.
- **A database outage** (Phase 7.1 wiring, owner OQ-A): when any module is on MySQL, bootstrap opens the one shared connection **at startup** (the adapters take a `PDO` in their constructors). So during an outage **every request**, including `GET /` and anonymous requests, gets the generic 500 `An internal error occurred.`. The connection error is `Database connection failed.` with no chained exception, and no host, port, database, user or password reaches the response or the log. Because bootstrap fails before the route guard is built, **refused anonymous requests are not recorded in the security-event log during an outage**. Login throttling and security events are file-based and work normally whenever the database is reachable. The app never falls back to mock (ARCHITECTURE.md §5). In Phase 7.1 MySQL is allowed only behind the test-only gate (`APP_ENV=testing`, `ais_test`, `ais_test_app`, loopback; faculty on mock); proven by `tests/Core/ConfigTest.php` W1–W4.

## 6. Test-database guard

The T-40 board text says: "The test harness refuses any database other than `ais_test`." Proposed: `tests/Database/MySQLTestSupport.php`, Phase 5. Every MySQL test calls it before connecting, and it **refuses (throws, and the test fails) unless all of these hold**:

1. `AIS_MYSQL_TESTS=1` is set. Otherwise MySQL test files register **no** tests, so `php tests/run.php` stays offline and unchanged by default.
2. `APP_ENV === 'testing'`.
3. `DB_DATABASE === 'ais_test'`, exactly and case-sensitively, with no prefix or suffix matching.
4. `DB_HOST` is exactly `127.0.0.1` (`localhost` and `::1` are refused, so the connection always matches the `@'127.0.0.1'` accounts that hold the grants).
5. After connecting: `SELECT DATABASE()` returns `ais_test`, and `SELECT CURRENT_USER()` starts with `ais_tester@` or `ais_test_app@`.
6. Every reset or DDL statement the harness issues names `ais_test` explicitly (fully qualified), so a wrong default database can't be hit.
7. **Independent name check (owner safeguard S2):** a separate variable `AIS_MYSQL_TEST_TARGET` must equal `ais_test` and equal `DB_DATABASE`. Two independent settings must agree, so one mistyped value can't redirect a reset.
8. **Re-check right before every reset (S2):** immediately before each destructive operation, on the same connection that will run it, the harness re-runs `SELECT DATABASE(), @@port, CURRENT_USER()`. It then refuses unless the database is `ais_test`, the port equals `DB_PORT`, and the account is a test account. Conditions 1–7 are also re-evaluated. A check made at connect time alone is not enough.

- **Reset policy:** drop and re-create the `ais_test` tables through the runner, between test files. This is destructive, but confined to `ais_test` by grants and by the guard. **It needs a standing owner approval (OQ-5).** No test ever connects to `ais`.
- **Offline guard tests** (no DB needed): each of conditions 1–4 violated → the guard throws before any connection is attempted.

## 7. Backup, restore and recovery

The backup and restore procedure and the drill are documented in `docs/database/BACKUP_RESTORE.md`; that file is authoritative where it differs from this summary.

- **Backup:**

  ```text
  mysqldump --single-transaction --skip-lock-tables --hex-blob --default-character-set=utf8mb4 -u ais_backup -p -h 127.0.0.1 ais > <dir>\ais-YYYYMMDD-HHMM.sql
  ```

  - **When:** before every migration on `ais`, before every import, and on the schedule the owner chooses (OQ-4).
  - **Where:** outside OneDrive and outside the web root.
  - **Treat dumps as secrets:** they contain password hashes and personal data. Encrypt them or keep them on an encrypted volume, and keep the last N as the owner decides.
- **Check every dump:** it is non-empty, and its last line is `-- Dump completed`.
- **Restore drill:** runs within `ais_test` only (`database/tools/backup_drill.php`; steps in `docs/database/BACKUP_RESTORE.md`). It never connects to `ais` and creates no scratch database. A restore into `ais` remains an owner-approved recovery step.
- **Recovery after a failed migration:** follow the file's `MANUAL RECOVERY` section, or restore the last backup. Both are owner-approved. Then delete the `started` row in `schema_migrations` and re-run (`CONSTRAINTS_AND_MIGRATIONS.md` §3.3).
- **JSON stores:** the dated copy taken before import is the rollback source (`CONSTRAINTS_AND_MIGRATIONS.md` §5.4). It is a secret too: it contains password hashes.

## 8. Server hardening for the XAMPP install (owner-run, after approval)

- **Network:** `bind-address=127.0.0.1` in `C:\xampp\mysql\bin\my.ini`. Never expose port 3306 on the network.
- **Root:**
  - Set a strong `root` password (XAMPP ships root with **no password**).
  - Remove the anonymous accounts and the `test` database if present. **Both are destructive changes to system tables; the owner approves and runs them.**
- **phpMyAdmin** (bundled with XAMPP): keep it limited to localhost or disable it. Never use it with the app or migration accounts.
- **`sql_mode`:** must include `STRICT_ALL_TABLES`. XAMPP's `my.ini` may set a non-strict `sql_mode`, so verify it with `SELECT @@GLOBAL.sql_mode`. Phase 4 also proposes setting it per connection (OQ-6).
- **Patching:** MariaDB 10.4 reached end of life in 2024 and gets no security fixes. **Use it for local development only** (OQ-12).

## 9. Authorization stays in the application

- **DB accounts are per application, not per end user.** There is no row-level security, no per-role DB user and no DB-side authorization logic.
- **Who may do what** stays where it is today: the route guard (authentication → role → CSRF), `AuthorizationService::requireRole` in every service, ownership checks from the session user (ARCHITECTURE.md §6), and B-07/A14 checks that fail closed. None of that changes with MySQL.
- **What the DB adds:** integrity (FK, UNIQUE, CHECK) and limits on *what the app process itself can do* (append-only history, no deletes, no DDL). It never grants anything the app would refuse. An app-level authorization bug is still a bug, and DB permissions don't hide it.
- **Cross-module boundary:** ARCHITECTURE.md §4 also holds in SQL. A module's adapter queries only its own tables. Other modules' data comes through their read interfaces (for example the T-30 lookup reads accounts through `UserRepositoryInterface`, not by joining `users`; see conflict C-07 in the plan). One shared `ais_app` account can't enforce this boundary, so it is a code-review rule (Phase 9).
