# Constraints, migrations and data migration (T-60)

> **Status: CONDITIONALLY APPROVED (owner, 2026-11-21; safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0). Implementation and testing against `ais_test` only; no cutover. Original note:** No migration exists, and none may be written to a non-test database or run until the owner approves the schema (B-03). Phase 5 builds the tooling. Applying anything to a database other than `ais_test` needs a separate owner approval each time.

## 1. Relationship and constraint matrix

### 1.1 Primary keys and foreign keys

Every FK is `ON DELETE RESTRICT ON UPDATE RESTRICT`. There is **no CASCADE and no SET NULL anywhere** (`ARCHITECTURE.md` §5).

| Table | PK | FK → target | Why |
|---|---|---|---|
| `users` | `id` | — | |
| `user_roles` | `(user_id, role)` | `user_id` → `users.id` | A role set belongs to an account. The PK prevents duplicate roles (`User.php:26`) |
| `audit_logs` | `id` | `actor_id` → `users.id` | The actor is always a real admin. `target_id` has no FK (0 allowed) |
| `programs` | `code` | — | The natural key: the app has no program id |
| `students` | `id` | `user_id` → `users.id`; `program_code` → `programs.code` | T-10 criterion 2; `Unknown program.` is checked by the app first |
| `student_academic_history` | `(student_id, seq)` | `student_id` → `students.id` | `actor_id` has no FK (0 = system) |
| `terms` | `id` | — | |
| `sections` | `id` | `term_id` → `terms.id` | `Unknown term.` 422 is still checked by the app inside the lock (T-20 P6). The FK is the backstop |
| `section_meetings` | `(section_id, position)` | `section_id` → `sections.id` | |
| `section_instructors` | `(section_id, faculty_id)` | `section_id` → `sections.id`; `faculty_id` → `faculty.id`; `assigned_seq` → `section_instructor_history.seq` | Duplicate pair impossible (409 is still checked by the app first) |
| `section_instructor_history` | `seq` | `section_id` → `sections.id`; `faculty_id` → `faculty.id`; `actor_id` → `users.id` | |
| `faculty` (CP) | `id` | `user_id` → `users.id` | T-30 F2 |
| `faculty_history` (CP) | `seq` | `faculty_id` → `faculty.id`; `actor_id` → `users.id` | |
| `repository_locks` | `name` | — | |
| `schema_migrations` | `version` | — | |

### 1.2 UNIQUE constraints

| Constraint | Columns | Contract it backs |
|---|---|---|
| `uq_users_email` | `users.email` | 409 duplicate email (`UserTransactionInterface` insert/update); B-06 |
| `uq_students_user` | `students.user_id` | One profile per account (409) |
| `uq_students_number` | `students.student_number` | 409 duplicate number |
| `uq_terms_code` | `terms.code` | 409 duplicate code |
| `uq_terms_current` | `terms.current_marker` (generated) | At most one current term (T-20 P13). The app clears the others first, in the same transaction |
| `uq_sections_term_course_section` | `(term_id, course_code, section_code)` | 409 duplicate section. It also serves the term filter and the list order |
| `uq_section_instructors_seq` | `section_instructors.assigned_seq` | One current row per `assigned` event |
| `uq_faculty_user`, `uq_faculty_employee_number` (CP) | `faculty.user_id`, `faculty.employee_number` | T-30 F2/F3 (409) |

**In every case the app checks uniqueness inside the store lock first,** and returns the exact contract 409 with its field messages. If the UNIQUE index ever fires, that is a skipped check, and it becomes the generic 500 (`ARCHITECTURE.md` §6.2).

### 1.3 CHECK constraints

The full text is in `DATA_DICTIONARY.md` §7. They fall into three groups:

| Kind | Examples |
|---|---|
| Value sets | `status`, `role`, `outcome`, `academic_status`, `delivery_mode`, `day`, `action`, `field` |
| Format | Codes (`REGEXP … \z`, case-sensitive under binary collation); `HH:MM`; normalized email |
| Ranges | `year_level 1..8`, `units 1..12`, `capacity 1..1000`, `approved_count <= capacity`, `position <= 13`, `max_year_level 1..8`, `admission_year >= 1900`, `auth_version >= 1`, ids and seqs ≥ 1 |
| Cross-column, same row | `start_date < end_date`, `start_time < end_time`, history value per `field` |
| Byte length | `OCTET_LENGTH(programs.name) <= 200` (the model counts bytes) |

**CHECK is never stricter than the write path.** Where the model or service accepts a value, the CHECK accepts it. The one deliberate tightening is that history `to_value` is NOT NULL: no write path produces NULL, and the importer reports any such row.

### 1.4 Rules that stay in the application

Each of these spans more than one row. The DB can't enforce them without triggers, and this design uses no triggers, stored procedures or events.

Why no triggers:

- Triggers hide business logic from the PHP code and reviews.
- They complicate the forward-only migrations.
- They would duplicate rules the reviewed services already enforce inside the lock.

| Rule | Where it is enforced | Verified across the whole table by |
|---|---|---|
| Last active admin can't be removed (409) | `MySQLUserRepository::transaction`, inside the `auth` lock | Concurrency test (Phase 7) |
| ≥ 1 role per user | `User::fromArray` + same transaction | `verify.php` |
| Year level ≤ program `max_year_level` | `StudentService` `$check`, inside the `students` lock | `verify.php` |
| Gap-free `seq` (students, assignments, faculty, audit ids) | Adapters, under the lock (`ARCHITECTURE.md` §7) | `verify.php`; C10, F-C03 |
| Meetings: ≥ 1 if `scheduled`; no self-overlap | `Section::fromArray` + `SectionService` | `verify.php` |
| ≤ 5 instructors per section; no same-term overlap per instructor | `MySQLCatalogRepository`, inside the `enrollment` lock | `verify.php`; C6–C9 |
| History replay equals the current state (T-21 A11, T-30 §5.2) | Append-only writes in the same transaction; the app account has no UPDATE/DELETE on history | `verify.php` |
| `approved_count` = approved enrollments (T-22) | T-22, inside the `enrollment` lock | `verify.php` (when T-22 exists) |
| Linked account active and holding the role at profile creation | Service, before the lock (Team 2 read interface) | — (accepted window, T-30 §7) |

### 1.5 Indexes and why

Only indexes that a current query needs, plus the ones InnoDB requires for FKs. There are no speculative indexes.

| Index | Query that needs it |
|---|---|
| `ix_user_roles_role (role, user_id)` | Last-admin count (`role = 'admin'`); T-30 "holds `faculty`" |
| `ix_audit_logs_actor` | FK (InnoDB requires an index). T-05 filters later |
| `ix_students_program` | FK; the `program_code` filter in `search` |
| `uq_sections_term_course_section` | The `term_id` filter, the list `ORDER BY term_id, course_code, section_code, id`, and the FK on `term_id` |
| `ix_section_instructors_faculty (faculty_id, section_id)` | `sectionIdsForFaculty` (ascending); the conflict check "sections of faculty F" |
| `ix_sih_pair (section_id, faculty_id, seq)` | Replay verification; FK on `section_id` |
| `ix_sih_faculty`, `ix_sih_actor`, `ix_faculty_history_actor` | FK requirement |
| `ix_faculty_history_replay (faculty_id, field, seq)` | Replay verification; FK on `faculty_id` |

**Not indexed, on purpose:**
- Name and title search columns. `LIKE '%x%'` can't use a B-tree index, and the lists are small and paginated.
- `academic_status`, `status` (low cardinality).

## 2. Migration dependency order

```text
V0001 infrastructure        repository_locks
V0002 auth                  users → user_roles → audit_logs           (+ lock row 'auth')
V0003 students              programs → students → student_academic_history (+ 'students')   needs V0002
V0004 catalog               terms → sections → section_meetings       (+ 'enrollment')
V0005 faculty (CP)          faculty → faculty_history                 (+ 'faculty')      needs V0002
V0006 section instructors   section_instructor_history → section_instructors             needs V0004, V0005
(later, after their plans)  V0007+ enrollments (T-22), grades (T-32), courses (D1): additive only
```

- **Version order equals dependency order.** The runner applies strictly ascending versions and never skips one.
- **V0005 and V0006 are CP.** Codex may write them and test them in `ais_test` now. Applying them to any other database waits until T-30 is merged (OQ-7).
- **Module cutover order** (data, not DDL) follows the FKs: auth → students → faculty → enrollment.
  - Before T-30 is wired, enrollment may move without faculty. Production then has no assignments, so `section_instructors` stays empty, and `UnavailableFacultyIdentity` refuses new ones.
  - After T-30 is wired, enrollment on `mysql` requires faculty on `mysql` (plan §4.2).

## 3. Migration and rollback strategy

### 3.1 Files

- **Name:** `database/migrations/VNNNN__snake_name.sql`, a 4-digit version.
- **Immutable once applied to any database.** A change is always a new version.
- **Each file starts with a header comment block:**
  - purpose;
  - dependencies;
  - whether it is destructive (dropping, truncating, narrowing a column, or deleting rows), which needs owner approval with the exact statements;
  - a **`-- MANUAL RECOVERY`** section: the statements an operator would run to undo it after a backup. These are never executed by the runner.
- **Content:** DDL plus the seed rows of `repository_locks`. No other data. Data comes from the importer (§5).

### 3.2 Runner (`database/migrate.php`, Phase 5, Codex)

- **Command:** `php database/migrate.php status|up --target=<database>` (CLI only: it refuses `PHP_SAPI !== 'cli'`, like the seeders).
- **Connection:**
  - It uses `App\Core\Database::connect` with `DB_HOST`, `DB_PORT`, `DB_DATABASE` and the **migration account** (`DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD`). It never uses the app account.
  - `--target` must equal `DB_DATABASE`, and `SELECT DATABASE()` must return it. Otherwise it refuses.
- **Tracking:** it creates `schema_migrations` if it is missing. This is the only DDL outside a migration file, and it is idempotent (`CREATE TABLE IF NOT EXISTS`).
- **Statement splitting:** `MULTI_STATEMENTS` is off, so the runner splits a file into statements.
  - Rule: a statement ends with `;` at the end of a line. `--` comment lines are dropped. A `;` anywhere else (inside a line or a string literal) makes the file invalid.
  - That is enough because the files contain no procedures, triggers or string data with semicolons. Offline tests cover the rule.
- **Before applying anything,** it validates:
  - every applied version's file still exists and its **SHA-256 matches** the recorded checksum. A mismatch means **refuse to run** (exit 2) and name the version;
  - no row is in state `started`. One there means a previous run was interrupted: **refuse** (exit 2) and point to the manual recovery for that version;
  - no unapplied file has a version lower than the highest applied one. If one does: **refuse** (exit 2);
  - the server is MariaDB ≥ 10.4 with `utf8mb4_nopad_bin` available, the database default collation is `utf8mb4_nopad_bin`, and the session `sql_mode` includes `STRICT_ALL_TABLES`.
- **Applying one version:**
  1. INSERT the row with `state='started'`, `statements_applied=0`.
  2. Run each statement, incrementing `statements_applied` after each one.
  3. UPDATE the row to `state='applied'` with `finished_at`.
  4. Stop at the first error, leaving `started` with the count.
- **Exit codes:** 0 ok or nothing to do, 1 error, 2 refused. Output: version and name only. No SQL text with values, and never credentials.
- **Re-running:** running `up` again after success is a no-op (it is idempotent by tracking). The migration-repeat test proves it.

### 3.3 What is and isn't reversible

- **DDL isn't transactional in MariaDB:** each `CREATE`/`ALTER` commits implicitly. A failed migration can leave earlier statements of the same file applied. **No document or tool may claim otherwise.**
- **Forward-only:**
  - The runner has no `down` command.
  - Recovery is manual: restore the backup, or run the file's `MANUAL RECOVERY` statements by hand with owner approval (they are destructive), delete the `started` row, then re-run `up`.
  - Each step is documented in the file header.
- **Backups before every migration** on any database other than `ais_test`: `mysqldump --single-transaction` (`SECURITY.md` §7), verified (non-empty, ends with the dump-completed marker) before `up`. For a destructive migration, the owner also sees the statements, their impact and the recovery plan (CLAUDE.md rule 3).
- **Data in migrations:** only `repository_locks` seed rows. Bulk data goes through the importer, whose DML *is* transactional (§5).

## 4. Verification command (`database/verify.php`, Phase 8)

The command is read-only and needs only `SELECT`. It checks the cross-row invariants that the mock validated on every read but SQL can't check per request. It is run:

- after each import;
- by the Phase 7 tests;
- on demand, or on a schedule the owner chooses (OQ-4).

| Check | Mirrors |
|---|---|
| Per student, history `seq` is 1..n, and the last `to_value` per field equals the current column | `MockStudentRepository::historyMap` |
| Assignment history `seq` is 1..n globally. Replaying the history gives exactly the `section_instructors` set. Each `assigned_seq` is the latest `assigned` entry for its pair | `MockCatalogRepository::snapshot` (T-21 A10/A11) |
| ≤ 5 instructors per section; no same-term meeting overlap per instructor | `snapshot` `:384-405` |
| `scheduled` sections have ≥ 1 meeting; no self-overlap | `Section::validMeetings` |
| Faculty history 1..n; replay per (faculty, field); first `from_value` NULL; last `to_value` = current (CP) | T-30 §5.2 |
| Audit ids 1..n | `MockUserTransaction.php:78` |
| `repository_locks.last_seq` = MAX(seq) of its stream | counter integrity |
| Every user has ≥ 1 role; students' `year_level` ≤ program max | `User::fromArray`; `StudentService` |
| Every row passes its PHP model (`User`, `Student`, `Term`, `Section`, `Faculty::fromArray`) | the "corrupt store fails closed" rule |

Output: the check name and offending ids only. No values: no emails, names or hashes. Exit 0 clean, 1 violations, 2 error.

## 5. Data migration from the JSON stores (Phase 8)

### 5.1 Tool

`php database/import_json.php --module=auth|students|faculty|enrollment --runtime=<APP_RUNTIME_PATH> --target=<db> [--dry-run]`. It is CLI only, uses the migration account, and is one module per run, in the cutover order (§2).

### 5.2 Preconditions (the tool refuses otherwise)

1. The schema is at the expected version (`schema_migrations`, all `applied`), and `verify.php` is clean on the target.
2. The module's target tables are **empty**, and its `repository_locks.last_seq = 0`. The importer never merges and never overwrites.
3. **Modules it depends on are already imported** (FKs; for example faculty needs users).
4. **The app is stopped** for the whole import: XAMPP Apache stopped, no `php -S` running (OQ-10). There is no maintenance mode in the app, and adding one is not requested.
5. A **copy of the module's JSON files** (and `.lock` files) has been taken into a dated folder outside OneDrive, and a DB backup taken (`SECURITY.md` §7).

### 5.3 Steps (one DB transaction per module; DML is transactional)

1. **Validate the source through the existing mock code.**
   - Construct the mock repository on the runtime path and call one read method: `MockUserRepository::findById(1)`, `MockStudentRepository::findById(1)`, `MockCatalogRepository::terms()`, `MockFacultyRepository::findById(1)`.
   - Each one validates the **whole snapshot**: uniqueness, replay, conflicts, key order and types. It throws on any corruption. **A corrupt source aborts the import with nothing written.**
2. **Read the raw JSON** with `JsonStore::read()`. That gives the exact stored values, including `password_hash`, which the `User` model doesn't expose.
3. **`BEGIN`; lock the module's `repository_locks` row; INSERT every row with its original id** and every history row with its original `seq`, converting timestamps and booleans (`ARCHITECTURE.md` §2–3):
   - `users`: `roles[]` → `user_roles` rows; `audit[]` → `audit_logs` (`changes` re-encoded with `json_encode`).
   - `students`: `programs.json` → `programs` first; `history{id: [...]}` → `student_academic_history` (`from`/`to` → text).
   - `enrollment`: `sections[].meetings[i]` → `section_meetings(position = i)`; `assignment_history` → `section_instructor_history`; `assignments` → `section_instructors` with `assigned_seq` = the latest `assigned` seq for the pair.
   - **Orphans abort:** any `faculty_id` without a `faculty` row aborts (FK, and the explicit pre-check gives a readable message), per OQ-8. Expected count in production before T-30: zero.
4. **Set `last_seq`** to the imported stream maximum (audit count, assignment history count, faculty history count).
5. **Verify inside the same transaction, before COMMIT:**
   - row counts per table equal the JSON counts;
   - **interface equality:** for every id, the MySQL adapter's interface output equals the mock's, compared as PHP values with `===`. That covers `findById` and `findByEmail` for every user; `auditEntries()`; `findById` and `history(id, 1, 100…)` for every student; `all()` programs; `terms()`; `findSection` for every section; `assignedFacultyIds(all)`; `sectionIdsForFaculty` for every faculty id; and the faculty finders (CP).

     The adapters read through the same connection, so they see the uncommitted rows;
   - the `verify.php` checks for the module.
6. **`COMMIT` only if every check passes,** and print counts only. On any failure: `ROLLBACK`, exit 1, nothing persisted. `--dry-run` always rolls back after step 5.

**AUTO_INCREMENT:** after explicit-id inserts, InnoDB moves the counter past MAX(id) automatically. Phase 7 asserts the next insert gets `MAX(id) + 1`.

**Sessions survive:** user ids and `auth_version` are preserved, so existing native sessions stay valid. If the owner prefers a forced re-login at cutover, that is a one-line `auth_version` bump. It is destructive to sessions, so it is not the default.

### 5.4 Rollback

| When | How | Data loss |
|---|---|---|
| During the import | Automatic `ROLLBACK` | None |
| After COMMIT, before the module goes live | Keep `APP_REPOSITORY_<MODULE>=mock`. To retry: restore the pre-import DB backup (destructive: owner approval) | None. The JSON files were never written |
| After go-live (MySQL accepted writes) | Switch the module back to `mock`: the frozen JSON copy is still valid | **Writes since go-live are lost**, because there is no dual-write and no reverse export. This is the go/no-go gate |

The JSON stores are never modified or deleted by the importer. Archiving them after a successful cutover is a separate, owner-approved step.

### 5.5 Production cutover

This is **not part of T-60 or T-40.** It needs:
- an owner-approved runbook (date, freeze, backups, go/no-go criteria, who runs it);
- the reviewed Phase 7 and 8 results;
- a supported production DB version (OQ-12);
- the deployment prerequisites already blocking production in `config/app.php` (`APP_ENV` production fails closed today: TLS, persistent sessions).
