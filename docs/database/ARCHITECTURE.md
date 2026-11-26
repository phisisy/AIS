# AIS database architecture (T-60)

> **Status: derived from source at `a01b114` (branch `task/INT-src`); not executed.** CONDITIONALLY APPROVED (owner, 2026-11-21; safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0): implementation and testing against `ais_test` only; **no cutover**.
>
> - **Schema:** 18 tables created by `database/migrations/V0001`–`V0009` (auth, students, catalog, faculty, instructor assignments, enrollments, grades, grade-history remarks), plus the runner's `schema_migrations` (`DATA_DICTIONARY.md`). `V0010` adds no table: it adds the CHECKs `ck_grades_value` and `ck_gh_value_scale` (owner-approved demo grade scale, 2026-11-26; not the official university policy). 10 migrations in total; `database/database.sql` is revision 6 (V0001–V0010).
> - **Adapters:** all five modules (`auth`, `students`, `enrollment`, `faculty`, `grades`) have MySQL adapters (§10). They are reachable only behind the **test-only gate** in `app/bootstrap.php`: `APP_ENV=testing`, database `ais_test`, account `ais_test_app`, loopback host, and a live `DATABASE()`/`CURRENT_USER()` check; anything else is "MySQL repositories are not available yet." JSON (mock) stays the default.
> - **Test instance:** a local MariaDB **10.11** instance holds `ais_test`. Results recorded against it are provisional. Nothing in this revision was run against it.

- **Engine:** MariaDB, InnoDB. The runner accepts MariaDB **≥ 10.4** (`MigrationRunner::checkServer`); the DDL stays within 10.4 features (for example, no CHECK on AUTO_INCREMENT columns, error 1901). Current test instance: **10.11**. The original XAMPP target was 10.4.32. Production engine and version: owner question OQ-12.
- **Companion documents:** `ERD.md`, `DATA_DICTIONARY.md`, `CONSTRAINTS_AND_MIGRATIONS.md`, `SECURITY.md`, `BLOCKERS.md` (all in `docs/database/`). The plan is `docs/plans/T-60-database-architecture.md`.
- **Rule this document follows:** the schema models what the current code and approved plans define. Where the code and an older document disagree, the code wins and the conflict is listed in the plan (§11).

## 1. Engine, character set and collation

| Setting | Value | Why |
|---|---|---|
| Engine | InnoDB on every table | Transactions, row locks, FKs. The MariaDB 10.4 default, but stated explicitly in every `CREATE TABLE` |
| Row format | `DYNAMIC` (10.4 default) | Index prefixes up to 3072 bytes. `email VARCHAR(254)` in utf8mb4 is 1016 bytes, which fits |
| Character set | `utf8mb4` everywhere | The app accepts any valid UTF-8 in names (`mb_check_encoding`) |
| Collation | **`utf8mb4_nopad_bin`** as the table default, so it applies to every string column | See §1.1 |
| Database default | `CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin` | So nothing created ad hoc inherits latin1 |
| Connection | `charset=utf8mb4` in the DSN (already in `Database::connect`) | — |

### 1.1 Why binary, no-pad collation on every column

The app normalizes values in PHP and compares them with `===`. The database must compare the same way, or uniqueness and lookups disagree with the mock.

- **Emails** are trimmed and lowercased by the app (`User.php:21`, `UserService.php:279`). `FILTER_VALIDATE_EMAIL` without the Unicode flag means they are ASCII. Under `_nopad_bin`, `UNIQUE(email)` means exactly "the same normalized string", the rule `MockUserTransaction::assertEmailFree` applies.
- **Codes** (`student_number`, `employee_number`, `program_code`, term `code`, `course_code`, `section_code`) are trimmed and uppercased by the app and then compared exactly. A case-insensitive collation would accept `cs101` as equal to `CS101`, and it would make every `REGEXP` CHECK case-insensitive. The CHECKs in `DATA_DICTIONARY.md` depend on binary collation.
- **Ordering:** `searchSections` sorts with `strcmp` on `course_code`/`section_code` (`MockCatalogRepository.php:116`), and programs are sorted by `strcmp` (`AcademicRecordService.php:59`). Binary collation gives the same byte order.
- **No-pad:** MariaDB's plain `_bin` collations are PAD SPACE, so `'A'` = `'A '`. The app trims every value, so `_bin` would work in practice, but `_nopad_bin` (available since MariaDB 10.2.2) makes SQL equality identical to PHP `===`. Phase 5 checks the collation exists before applying anything (`SHOW COLLATION LIKE 'utf8mb4_nopad_bin'`).
- **Free text** (names, titles, rooms, department) also uses `_nopad_bin`. The app's search is `mb_strtolower` + `str_contains`: case-insensitive but **accent-sensitive**. `utf8mb4_unicode_ci` is accent-insensitive (`e` matches `é`), so it would change search results. Adapters search with `LOWER(column) LIKE ? ESCAPE '!'`, passing a needle that PHP has already lowercased with `mb_strtolower`. `utf8mb4_unicode_ci` was considered and rejected for that reason (owner question OQ-9).
- **Residual difference:** MariaDB `LOWER()` and PHP `mb_strtolower` can differ on a few special case mappings (for example U+0130, `İ`). Phase 7 contract tests cover ASCII and common accented Latin letters, and the difference is documented as a known limit.

## 2. Naming, keys and types

| Item | Convention |
|---|---|
| Tables | `snake_case`, plural for entities (`users`, `sections`), `<parent>_<child>` for owned children (`section_meetings`). Exception: `faculty` (uncountable; T-30 note) |
| Columns | `snake_case`, the same name as the PHP array key wherever the key isn't a reserved word. `from`/`to` become `from_value`/`to_value`, and meeting `start`/`end` become `start_time`/`end_time`. Adapters map these back to the exact contract keys |
| Primary keys | `id INT UNSIGNED AUTO_INCREMENT` for entities. Composite PKs for owned rows (`(student_id, seq)`, `(section_id, position)`, `(user_id, role)`, `(section_id, faculty_id)`). `seq BIGINT UNSIGNED` for global history streams, assigned by the app (§6), not AUTO_INCREMENT |
| Foreign keys | `<referenced singular>_id`. Every FK has `ON DELETE RESTRICT ON UPDATE RESTRICT` (§5) |
| Constraint names | `pk_` is implicit; `uq_<table>_<cols>`, `fk_<table>_<col>`, `ck_<table>_<rule>`, `ix_<table>_<cols>` |
| Integer ids | `INT UNSIGNED` (up to 4.29 billion). The PHP models require `int ≥ 1`. With `PDO::ATTR_EMULATE_PREPARES => false`, mysqlnd returns native PHP ints, which the models' strict `is_int` checks need |
| Booleans | `TINYINT(1) NOT NULL` with `CHECK (col IN (0,1))`. Adapters convert with `=== 1` to a PHP `bool`, because `Term::fromArray` requires `is_bool` |
| Defaults | **None**, except where noted. The adapter writes every column explicitly. With strict mode, a forgotten column fails loudly instead of silently getting a default |
| Table names | All lowercase. Windows sets `lower_case_table_names=1`, so mixed case would behave differently on Linux |

## 3. Time

- **The app's format:** every stored instant is a string in `Y-m-d\TH:i:s\Z` (UTC), from `gmdate()` with an injected clock (`UserService.php:323`, `StudentService.php:241`, `SectionService.php:337`). Tests use fixed clocks.
- **Column type:** `DATETIME` (seconds precision), holding UTC.
  - Not `TIMESTAMP`: it converts through the session `time_zone`, and it ends in 2038.
  - The adapter converts `2026-11-20T08:00:00Z` ↔ `2026-11-20 08:00:00` with a strict parse. A value that doesn't round-trip exactly is a `RuntimeException`, which fails closed.
- **Dates** (`terms.start_date`, `end_date`): `DATE`. MariaDB returns `YYYY-MM-DD`, the format the model checks.
- **Meeting times:** `CHAR(5)` holding `HH:MM`, with a REGEXP CHECK. This is a deliberate exact round-trip of the contract string. Zero-padded `HH:MM` strings compare correctly, which is the same assumption `Section.php:56` makes.
- **No server-side clock:** SQL written by the app never uses `NOW()`, `CURRENT_TIMESTAMP` or `UTC_TIMESTAMP()`. Time always comes from the service's clock, so tests and audit times stay deterministic. Connections still set `time_zone = '+00:00'` (OQ-6), so a mistake can't write local time.

## 4. Status and enum-like values: CHECK, not ENUM

Every enum-like column is `VARCHAR(n)` with a `CHECK (col IN (...))` naming exactly the PHP constant's values.

| Column | Values (source) |
|---|---|
| `users.status` | `active`, `inactive` (`User.php:24`) |
| `user_roles.role` | `student`, `faculty`, `registrar`, `admin` (`AuthorizationService::ROLES`, `AuthorizationService.php:13`) |
| `audit_logs.outcome` | `success`, `denied` (`MockUserTransaction.php:73`) |
| `students.academic_status` | `active`, `inactive`, `graduated`, `transferred`, `on_leave` (`Student::STATUSES`) |
| `student_academic_history.field` | `program_code`, `year_level`, `academic_status` (`MockStudentRepository::TRACKED`) |
| `sections.delivery_mode` | `scheduled`, `tba`, `online_async` (`Section::DELIVERY_MODES`) |
| `section_meetings.day` | `MON` … `SUN` (`Section::DAYS`) |
| `section_instructor_history.action` | `assigned`, `unassigned` (`MockCatalogRepository.php:411`) |
| `faculty.status`, `faculty_history.field` | `active`/`inactive`; `status`/`department` (T-30 §3.3, §5.2) |
| `enrollments.status` | `pending`, `approved`, `rejected`, `dropped` (V0007, T-22) |
| `enrollment_history.action` | `requested`, `approved`, `rejected`, `dropped` (V0007) |
| `grades.status` | `draft`, `submitted`, `finalized` (V0008, T-32) |
| `grade_history.action` | `entered`, `updated`, `submitted`, `returned`, `finalized`, `corrected` (V0008) |
| `grades.value` | `1.00`, `1.25`, `1.50`, `1.75`, `2.00`, `2.25`, `2.50`, `2.75`, `3.00`, `5.00`, `INC`, `DRP` (V0010 `ck_grades_value`, exact match; `grade_history.old_value`/`new_value` NULL or the same list via `ck_gh_value_scale`). This is the owner-approved **demo** scale (2026-11-26) and must equal `GradeScale::demo()`; the official university policy (B-05) is still pending and `GradeScale::official()` stays empty. `ck_grades_value_len` (1–8) stays |

Why not `ENUM`:

- In a non-strict session, an invalid `ENUM` value is stored silently as `''`.
- `ENUM` values sort by their index, not by their text.
- `ENUM` accepts numeric indexes as input.
- CHECK is enforced in 10.4 (since 10.2.1), and it fails the statement.

Both need DDL to add a value, so neither is cheaper to change. The application still validates first and fails closed on unknown stored values (T-02 criterion 10). The CHECK is a second line of defence, not the rule's owner.

**Roles** are a `user_roles(user_id, role)` table with that CHECK, and **no `roles` table**:

- The set is fixed in code (`AuthorizationService::ROLES`), and the app fails closed on anything else.
- A `roles` table would hold four rows that the code can't use without a redeploy. A row added there would grant nothing, or worse, look as if it did.
- A table (instead of a JSON or SET column) lets the last-admin count and the T-30 "holds `faculty`" check be plain indexed queries.
- B-03's list names `roles`. Dropping it is owner question OQ-2.

## 5. Deletion and archival

- **No hard deletes of academic data:** users, students, programs, terms, sections, faculty, enrollments, grades, and every history or audit row.
  - Every FK is `ON DELETE RESTRICT ON UPDATE RESTRICT`. There is no `CASCADE` and no `SET NULL` anywhere, and no cascade into history.
  - Ids are never updated (they are immutable in every contract), so `ON UPDATE RESTRICT` costs nothing.
- **The app account has no `DELETE` privilege** on those tables (`SECURITY.md` §2). It can delete exactly three kinds of current-state child rows, all of which the code already deletes:

  | Table | Why the app deletes | What keeps the record |
  |---|---|---|
  | `section_instructors` | Unassign (`MockCatalogRepository.php:261`) | The `unassigned` row in `section_instructor_history` |
  | `section_meetings` | PATCH `meetings` replaces the whole list (`SectionService.php`, T-20 §3.2) | Nothing. The mock keeps no meeting history either |
  | `user_roles` | A role change replaces the role set (`UserService::doUpdate`) | The `user.updated` audit entry with `roles.from`/`roles.to` |

- **History and audit tables are append-only by privilege:** the app account has `SELECT, INSERT` only. This replaces part of the mock's "replay on every read" protection (see `BLOCKERS.md` §2).
- **Archival** (T-20 §15, owner decision Q5) is not built. When it is, it adds `archived_at`/`archived_by` columns in an additive migration. No archival columns are created now, because nobody has asked for the feature.
- **Profile retention** (T-10 decision 3, T-30 F8): account deactivation or role loss changes nothing in `students` or `faculty`. There are no triggers and no cascades.

## 6. Transactions and locking

### 6.1 Principle: one lock row per former JSON store

Each mock repository serializes **all** writes to its store with one exclusive `flock`, and validates the whole snapshot inside it. The SQL design reproduces that boundary exactly:

```text
repository_locks(name PK, last_seq)  rows: 'auth', 'students', 'enrollment', 'faculty', 'grades'

BEGIN;
SELECT last_seq FROM repository_locks WHERE name = 'enrollment' FOR UPDATE;   -- = the JsonStore LOCK_EX
... reads, invariant checks, writes ...
COMMIT;   -- or ROLLBACK on any exception (= nothing written)
```

Why one row lock rather than fine-grained row locks:

1. **It is provably equivalent** to the current, reviewed semantics: every invariant that is "checked inside the lock" (uniqueness, last admin, cap of 5, instructor conflicts, one current term, capacity ≥ approved) stays checked inside an exclusive section.
2. **Fine-grained locks would invite gap locks.** For example, `SELECT … FOR UPDATE` on a faculty member's assignments when none exist takes a gap lock. Two such transactions then deadlock on insert, which the mock can never do.
3. **The writes are short and staff-driven.** The throughput limit is the same as today's. T-22's student enrollment requests are the first high-concurrency writer. If measurement shows contention, T-22 can propose finer locks with its own concurrency tests.
4. **The row also holds the gap-free sequence counter** for that store (§7), so taking the lock and allocating `seq` are one statement.

**Reads** don't take the lock:

- InnoDB consistent reads see the last committed state, which is what a `JsonStore::read()` shared lock gives today.
- A read that needs more than one statement (a section plus its meetings, a page plus its total) runs inside one transaction (`PDO::beginTransaction()`, read-write mode but read-only in practice), giving a single REPEATABLE READ snapshot. *(Review L2: earlier text said `START TRANSACTION READ ONLY`; consistency is identical. Only the server-side refusal of an accidental write is not used.)*

**Isolation:** REPEATABLE READ (the InnoDB default). Every decision read inside a write transaction happens after the lock row is taken, so it sees the latest committed data, because no other writer of that store can be running.

### 6.2 Boundaries per repository method

| Interface (file) | Method | SQL transaction |
|---|---|---|
| `UserAdminRepositoryInterface` | `transaction(callable)` | Lock `auth`. Count active admins (`users` ⋈ `user_roles`). Run the callable with a `MySQLUserTransaction` on the same connection. Count again: if one existed before and none now → `HttpException(409, LAST_ADMIN_MESSAGE)` and ROLLBACK. Otherwise COMMIT. Exactly `MockUserRepository.php:54-73` |
| `UserTransactionInterface` | `insert`, `update`, `appendAudit` | Run inside the transaction above, and never open their own. `appendAudit` takes `id = last_seq + 1` from the locked `auth` row |
| `UserRepositoryInterface` | `findById`, `findByEmail`; plus `search`, `auditEntries` | Read only |
| `StudentRepositoryInterface` | `create`, `update` | Lock `students`. Uniqueness or 404 checks, `$check($merged)` (it may read `programs` on the same connection), `Student::fromArray`, write, history rows with per-student `seq = MAX(seq)+1`, COMMIT |
| `ProgramCatalogInterface` | `find`, `all` | Read only. There is no write path in the app (§8) |
| `CatalogRepositoryInterface` | `createTerm`, `updateTerm`, `createSection`, `updateSection`, `assignInstructor`, `unassignInstructor`, and the T-22 enrollment writers | Lock `enrollment`. The same checks in the same order as `MockCatalogRepository`, then COMMIT. Assignment-history `seq` comes from the `enrollment` row. Enrollments (V0007) use this same lock, so `approved_count` and the enrollment row change atomically (T-20 P1); `enrollment_history.seq` is per enrollment |
| `FacultyRepositoryInterface` (T-30) | `create`, `update` | Lock `faculty`. `faculty_history.seq` comes from the `faculty` row |
| `GradeRepositoryInterface` (T-32) | grade writers | Lock `grades` `FOR UPDATE`; exactly one `grade_history` row per changed grade, `seq = repository_locks('grades').last_seq + 1`. Finalize also uses the `enrollment` lock (`config/app.php`) |

- **No nesting:** an adapter that finds `PDO::inTransaction()` already true when it starts a write throws `RuntimeException('Nested store operations are not supported.')`. That is the mock's message (`JsonStore.php:52`). Reads inside another repository's transaction are allowed, because the mock allows them across stores too (for example `$check` reading programs inside the students lock).
- **No cross-store write transactions exist today,** and none are added. For example, Students checks the account through `UserRepositoryInterface` **before** its own lock (`StudentService.php:114`), which is the same window the mock has.
- **Deadlock or lock-wait timeout** (errors 1213 and 1205): there is no retry. The exception propagates, and the user gets the generic 500 (`ErrorHandler.php:34`). With one lock row per store and no cross-store writes, deadlocks shouldn't occur at all. Phase 7 concurrency tests assert that none happen.
- **Unique-key violation** (error 1062) inside a locked transaction means an invariant was skipped (a bug). It surfaces as the generic 500, never as a 409, so a skipped in-lock check can't hide behind the DB constraint.

### 6.3 Transaction rules found in review (6a/6b review L4, L5; 2026-11-21)

- **Never catch `PDOException` or `Throwable` inside a repository transaction callback** (a service callback or a `$check` closure). After a deadlock (1213) or a lock-wait timeout, InnoDB has already rolled back the whole transaction. Carrying on would run later statements outside the transaction and without the lock row. Let the exception reach the repository, which rolls back and rethrows a sanitized error. Phase 9 review checks this rule.
- **One shared PDO per request is required for correctness, not just efficiency.** Adapters refuse nested transactions on a shared connection. With separate connections, nesting a students write inside an auth callback would wait on its own `users` row lock until `innodb_lock_wait_timeout`, and InnoDB can't detect that.
- **Lock order** for any operation that writes to more than one store: **auth → students → enrollment → faculty**, each lock row taken at most once, on the one shared PDO. Grade finalize combines the `enrollment` and `grades` locks (`config/app.php` comment); the order it takes them is defined in `MySQLGradeRepository` and was not re-derived for this revision.

## 7. Gap-free `seq` in SQL

The current contracts promise gap-free sequences, and their tests assert them:

| Stream | Contract | Proposed SQL |
|---|---|---|
| `assignment_history` (T-21 A10) | One global list, `seq = index + 1` | `repository_locks('enrollment').last_seq + 1`, then `UPDATE … SET last_seq = last_seq + 1`, both under the lock |
| `faculty_history` (T-30 §5.2, §1.1-6) | One global list, gap-free | `repository_locks('faculty')` the same way |
| `grade_history` (T-32) | One global list, gap-free | `repository_locks('grades')` the same way |
| `enrollment_history` (T-22) | Per enrollment, `seq` 1..n | PK `(enrollment_id, seq)` under the `enrollment` lock; not a lock-row stream |
| Student academic history (T-11a) | Per student, `seq = count + 1` (`MockStudentRepository.php:135`) | `SELECT COALESCE(MAX(seq),0)+1 FROM student_academic_history WHERE student_id = ?` under the `students` lock. The PK `(student_id, seq)` makes it a single index probe |
| Admin audit ids | `id = count + 1` (`MockUserTransaction.php:78`). Not asserted gap-free, but that is what the mock produces | `repository_locks('auth').last_seq + 1` |

- **Why not AUTO_INCREMENT:** it isn't gap-free. A rolled-back insert consumes a value. For example, an assignment whose history row was inserted before a later check failed would leave a gap.
- **Why the counter row is safe:** it is updated inside the same transaction, so a ROLLBACK restores it. The lock row is held anyway (§6.1), so the counter adds no contention.
- **Owner decision OQ-1:** keep gap-free with counter rows (the default, which preserves the T-21/T-30 contracts and tests C10/F-C03 unchanged), or relax it to "strictly increasing" and use AUTO_INCREMENT. Relaxing changes approved contracts and needs the T-21 and T-30 plans amended.
- **Entity ids** (`users.id`, `students.id`, `terms.id`, `sections.id`, `faculty.id`) stay AUTO_INCREMENT.
  - No contract promises gap-free ids. The mock's `max + 1` only promises uniqueness and no reuse (T-30 F1).
  - MariaDB 10.2.4+ persists the InnoDB counter across restarts, and nothing is deleted, so ids are never reused.

## 8. Audit and history relationships

| History table | About | Actor | Written with |
|---|---|---|---|
| `audit_logs` | `target_id` = a user id, or `0` for "the account that would have been created" (`UserService.php:64`). **No FK**, because of the 0 | `actor_id` FK → `users.id` (always a real signed-in admin) | The same `auth` transaction as the change. Denied entries go in their own `auth` transaction (`UserService::audited`) |
| `student_academic_history` | FK `student_id` → `students.id` | `actor_id` = user id, or **`0` = system/seeder** (`StudentRepositoryInterface.php:28`). **No FK**, because of the 0, and no CHECK (the column is unsigned) | The same `students` transaction |
| `section_instructor_history` | FKs `section_id` → `sections.id`, `faculty_id` → `faculty.id` | FK `actor_id` → `users.id` (≥ 1, `MockCatalogRepository.php:414`) | The same `enrollment` transaction |
| `faculty_history` | FK `faculty_id` → `faculty.id` | FK `actor_id` → `users.id` (≥ 1, T-30 §5.2) | The same `faculty` transaction |
| `enrollment_history` | FK `enrollment_id` → `enrollments.id` | FK `actor_id` → `users.id` (≥ 1) | The same `enrollment` transaction |
| `grade_history` | FKs `grade_id` → `grades.id`, `section_id` → `sections.id`, `student_id` → `students.id` | FK `actor_id` → `users.id` (≥ 1) | The same `grades` transaction |

- **The current assignment row points at its history row:** `section_instructors.assigned_seq` FK → `section_instructor_history.seq`, the `assigned` entry that created it.
  - This preserves the "assignment order" contract (`CatalogRepositoryInterface::assignedFacultyIds`: "ids in assignment order"; `SectionDirectory::assignedFacultyIds`) as `ORDER BY assigned_seq`. The T-21 T-40 note's `PK(section_id, faculty_id)` alone would lose it.
  - It also gives the DB a structural link between the current state and the replay.
- **History values are typed text:** `from_value`/`to_value` are `VARCHAR`. Adapters convert `year_level` back to `int`, and CHECKs validate the value per `field` (`DATA_DICTIONARY.md`).
- **Security events** are not audit rows and stay file-based (§9).
- **No secrets in any audit or history row:** the app's filter (`AUDIT_KEYS` and the secret-key regex, `MockUserTransaction.php:14, 71-77`) must run identically in the MySQL adapter (`SECURITY.md` §4).

## 9. What stays out of the database

| Data | Decision | Why |
|---|---|---|
| Native PHP sessions (`$runtime/sessions`) | **Stay file sessions** | B-09 is DEFERRED. Revocation already works through `users.auth_version`, which is now a column, so the schema part of B-09 is covered. A DB session handler is a separate, unrequested feature |
| `LoginRateLimiter` (`login-attempts.json`, `reauth-attempts.json`) | **Stay file-based** | (1) `LoginRateLimiter` takes a concrete `JsonStore`, not an interface. Moving it means a Team 2 refactor nobody asked for. (2) The data is short-lived (15-minute window), keyed by SHA-256 hashes, and safe to lose. (3) Keeping login throttling independent of the DB means a DB outage doesn't also disable throttling. Limit: per-server buckets. If the app is ever load-balanced, revisit (OQ-3) |
| `SecurityEventLog` (`security-events.json`) | **Stays file-based** | (1) Concrete `JsonStore` dependency, deliberately **non-blocking** (`LOCK_NB`, drop when busy, `SecurityEventLog.php:77`), with a quarantine path for corruption. A DB version needs a new interface and a new failure mode on the refusal path. (2) It must record refusals even when the DB is down. (3) It is bounded (500 + 1000 entries, 30 days) by design. T-05 (audit read endpoint) is about `audit_logs`, not this log |
| Program catalog writes | Table `programs`, but **no app write path**. Loaded by the importer and migration account only | `JsonProgramCatalog` is read-only in the app (`seed()` is for the seeder and tests). D1 may later give it an owner and an API |

## 10. Repository adapter strategy (Phase 6)

1. **The existing interfaces stay unchanged.** One class per interface; all exist at `a01b114`:

   | Module (config key) | Class | Implements |
   |---|---|---|
   | `auth` | `App\Auth\Repositories\MySQLUserRepository` | `UserAdminRepositoryInterface` |
   | `auth` | `App\Auth\Repositories\MySQLUserTransaction` | `UserTransactionInterface` |
   | `students` | `App\Students\Repositories\MySQLStudentRepository` | `StudentRepositoryInterface` |
   | `students` | `App\Students\Repositories\MySQLProgramCatalog` | `ProgramCatalogInterface` |
   | `enrollment` | `App\Enrollment\Repositories\MySQLCatalogRepository` | `CatalogRepositoryInterface` (catalog, assignments, enrollments) |
   | `faculty` | `App\Faculty\Repositories\MySQLFacultyRepository` | `FacultyRepositoryInterface` |
   | `grades` | `App\Faculty\Repositories\MySQLGradeRepository` | `GradeRepositoryInterface` |

   **Status:** wired in `app/bootstrap.php` behind the test-only gate (see the status note). No module has been cut over; mock stays the default.

   No interface method is added, removed or retyped. Services, controllers, models and contracts are not touched.
2. **Models remain the validators.** Adapters build the exact PHP arrays the models expect: exact key order, `int`/`bool`/`string`/`null` types, and the timestamp format. They then pass them through `User::fromArray`, `Student::fromArray`, `Term::fromArray`, `Section::fromArray`, `Faculty::fromArray`. A row the model rejects is a `RuntimeException`, so "corrupt data fails closed" still holds per row.
3. **Selection per module by configuration** (Phase 4 contract, plan §4.2):
   - `APP_REPOSITORY` stays the default for every module.
   - Optional `APP_REPOSITORY_AUTH|STUDENTS|ENROLLMENT|FACULTY|GRADES` overrides it per module (`config/app.php`).
   - Invalid values, or an impossible combination, fail closed at startup: any module on `mysql` requires auth on `mysql`; enrollment on `mysql` requires students and faculty on `mysql`; grades on `mysql` requires students and enrollment on `mysql`.
   - A module never falls back from `mysql` to `mock` (ARCHITECTURE.md §5).
4. **Incremental migration:** one module at a time, in the order auth → students → faculty → enrollment → grades. Each module is imported once from its JSON store (`CONSTRAINTS_AND_MIGRATIONS.md` §5). The importer supports `auth`, `students`, `faculty` (since `405e013`; unverified) and `enrollment`, imported in the order auth → students → faculty → enrollment; `enrollment` is refused while its instructor assignments reference faculty that have not been imported. There is no grades import: any runtime holding `faculty/grades.json` is refused with a fail-closed message (B-05: no grade can legitimately exist yet).
5. **No permanent dual-write.** A module reads and writes exactly one backend. The JSON file is frozen as a backup at import time. Rolling back to it after go-live loses the writes made since (a go/no-go gate in the cutover runbook).
6. **The mock implementations stay.** They remain the default, the offline test backend, and the reference for the shared contract tests. Nothing in the mock or in `JsonStore` changes.
7. **One PDO connection per request,** created by bootstrap through `Database::connect` only if at least one module is on `mysql`, and shared by the adapters.
8. **No schema DDL in application code** (T-40). DDL lives only in `database/migrations/*.sql`, applied by the migration CLI with the migration account.
9. **Production cutover is out of scope.** It needs a separate owner approval, after Phase 10, with its own runbook (`CONSTRAINTS_AND_MIGRATIONS.md` §5.5).
