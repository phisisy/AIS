# Constraints, migrations and data migration (T-60)

> **Status: derived from source at `a01b114` (branch `task/INT-src`); not executed.** Read from `database/migrations/V0001`–`V0009`, `database/tools/MigrationRunner.php`, `database/tools/IntegrityVerifier.php`, `database/verify.php`, `database/tools/JsonImporter.php` and `database/import_json.php`. No migration, verifier or import was run to produce this page. Conditional owner approval (2026-11-21, safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0) still applies: `ais_test` only, no cutover. Applying anything to another database needs a separate owner approval each time.

## 1. Relationship and constraint matrix

### 1.1 Primary keys and foreign keys

Every FK is `ON DELETE RESTRICT ON UPDATE RESTRICT`. There is **no CASCADE and no SET NULL anywhere** (`ARCHITECTURE.md` §5).

| Table | PK | FK → target | Notes |
|---|---|---|---|
| `repository_locks` | `name` | — | |
| `users` | `id` | — | |
| `user_roles` | `(user_id, role)` | `fk_user_roles_user` → `users.id` | PK prevents duplicate roles |
| `audit_logs` | `id` | `fk_audit_logs_actor` → `users.id` | `target_id` has no FK (0 allowed) |
| `programs` | `code` | — | Natural key |
| `students` | `id` | `fk_students_user` → `users.id`; `fk_students_program` → `programs.code` | |
| `student_academic_history` | `(student_id, seq)` | `fk_sah_student` → `students.id` | `actor_id` has no FK (0 = system) |
| `terms` | `id` | — | |
| `sections` | `id` | `fk_sections_term` → `terms.id` | `course_code` has no FK (D1) |
| `section_meetings` | `(section_id, position)` | `fk_section_meetings_section` → `sections.id` | |
| `faculty` | `id` | `fk_faculty_user` → `users.id` | |
| `faculty_history` | `seq` | `fk_faculty_history_faculty` → `faculty.id`; `fk_faculty_history_actor` → `users.id` | |
| `section_instructor_history` | `seq` | `fk_sih_section` → `sections.id`; `fk_sih_faculty` → `faculty.id`; `fk_sih_actor` → `users.id` | |
| `section_instructors` | `(section_id, faculty_id)` | `fk_si_section` → `sections.id`; `fk_si_faculty` → `faculty.id`; `fk_si_history` `assigned_seq` → `section_instructor_history.seq` | |
| `enrollments` | `id` | `fk_enrollments_student` → `students.id`; `fk_enrollments_section` → `sections.id`; `fk_enrollments_decider` `decided_by` → `users.id` | `decided_by` nullable |
| `enrollment_history` | `(enrollment_id, seq)` | `fk_eh_enrollment` → `enrollments.id`; `fk_eh_actor` → `users.id` | |
| `grades` | `id` | `fk_grades_section` → `sections.id`; `fk_grades_student` → `students.id` | **No FK to `enrollments`** (verifier `grade_enrollment`) |
| `grade_history` | `seq` | `fk_gh_grade` → `grades.id`; `fk_gh_section` → `sections.id`; `fk_gh_student` → `students.id`; `fk_gh_actor` → `users.id` | |
| `schema_migrations` | `version` | — | Created by the runner |

### 1.2 UNIQUE constraints

| Constraint | Columns | Contract it backs |
|---|---|---|
| `uq_users_email` | `users.email` | 409 duplicate email; B-06 |
| `uq_students_user` | `students.user_id` | One profile per account |
| `uq_students_number` | `students.student_number` | 409 duplicate number |
| `uq_terms_code` | `terms.code` | 409 duplicate code |
| `uq_terms_current` | `terms.current_marker` (generated) | At most one current term (T-20 P13) |
| `uq_sections_term_course_section` | `(term_id, course_code, section_code)` | 409 duplicate section; term filter and list order |
| `uq_faculty_user`, `uq_faculty_employee_number` | `faculty.user_id`, `faculty.employee_number` | T-30 F2/F3 (409) |
| `uq_section_instructors_seq` | `section_instructors.assigned_seq` | One current row per `assigned` event |
| `uq_enrollments_active` | `(student_id, active_section_id)` (generated) | At most one pending/approved request per student and section (T-22) |
| `uq_grades_section_student` | `(section_id, student_id)` | One grade per section and student (T-32) |

**In every case the app checks uniqueness inside the store lock first** and returns the contract 409. If the UNIQUE index fires, that is a skipped check and becomes the generic 500 (`ARCHITECTURE.md` §6.2).

### 1.3 CHECK constraints

Every CHECK is named and listed per table in `DATA_DICTIONARY.md`; the exact text is in the migration files. Groups:

| Kind | Examples |
|---|---|
| Value sets | `status` (users, faculty, enrollments, grades), `role`, `outcome`, `academic_status`, `delivery_mode`, `day`, `action` (sih, enrollment_history, grade_history), `field` |
| Format | Codes (`REGEXP … \z`, case-sensitive under binary collation); `HH:MM`; normalized email; `JSON_VALID(changes)` |
| Ranges | `year_level 1..8`, `units 1..12`, `capacity 1..1000`, `approved_count <= capacity`, `position <= 13`, `max_year_level 1..8`, `admission_year >= 1900`, `auth_version >= 1`, ids and seqs ≥ 1 |
| Length | `OCTET_LENGTH(programs.name) <= 200`; grade `value`/`old_value`/`new_value` 1–8 characters |
| Non-empty optional text | `reason`, `remarks`, `old_remarks`, `new_remarks`: NULL or non-empty |
| Cross-column, same row | `start_date < end_date`; `start_time < end_time`; history value per `field`; enrollment status ↔ decision/drop columns (`ck_enrollments_decision`); `decided_at`/`dropped_at` ≥ `requested_at`; rejected requires reason; grade-history action ↔ values (`ck_gh_values`), action ↔ reason (`ck_gh_reason`), remarks only on valued actions (`ck_gh_remarks_unvalued`); `grades.updated_at >= created_at` |

**Grade value domain: pending official university policy (B-05); no value CHECK yet.** Only length is checked. A later additive migration adds the scale CHECK.

**CHECK is never stricter than the write path.** The deliberate tightening is that history `to_value` is NOT NULL (no write path produces NULL).

### 1.4 Rules that stay in the application

Each spans more than one row. There are no triggers, stored procedures or events (they would hide logic from review, complicate forward-only migrations and duplicate rules the services already enforce inside the lock).

| Rule | Where it is enforced | Verified by (check name, §4) |
|---|---|---|
| Last active admin can't be removed (409) | `MySQLUserRepository::transaction`, `auth` lock | Concurrency tests |
| ≥ 1 role per user | `User::fromArray` + same transaction | `user_roles` |
| Year level ≤ program `max_year_level` | `StudentService` `$check`, `students` lock | `student_year_level` |
| Gap-free `seq` / ids | Adapters, under the lock (`ARCHITECTURE.md` §7) | `*_history_seq`, `audit_ids`, `repository_last_seq` |
| Meetings: ≥ 1 if `scheduled`; no self-overlap | `Section::fromArray` + `SectionService` | `scheduled_meetings`, `meeting_self_overlap` |
| ≤ 5 instructors per section; no same-term overlap per instructor | `MySQLCatalogRepository`, `enrollment` lock | `instructor_cap`, `instructor_overlap` |
| History replay equals current state | Append-only writes in the same transaction; app has no UPDATE/DELETE on history | `*_history_replay`, `assignment_assigned_seq` |
| `approved_count` = approved enrollments | `MySQLCatalogRepository`, `enrollment` lock | `enrollment_approved_count` |
| No schedule clash among a student's approved sections in a term | `MySQLCatalogRepository`, `enrollment` lock | `enrollment_schedule_clash` |
| Non-draft grade needs an approved enrollment | `MySQLGradeRepository`, `grades` lock | `grade_enrollment` |
| Grade state machine and separation of duties | `MySQLGradeRepository` / grade service | `grade_history_replay` (state machine only) |
| Linked account active and holding the role at profile creation | Service, before the lock | — (accepted window, T-30 §7) |

### 1.5 Indexes and why

Only indexes a current query needs, plus those InnoDB requires for FKs.

| Index | Query that needs it |
|---|---|
| `ix_user_roles_role (role, user_id)` | Last-admin count; "holds `faculty`" |
| `ix_audit_logs_actor` | FK |
| `ix_students_program` | FK; `program_code` filter |
| `uq_sections_term_course_section` | `term_id` filter, list order, FK on `term_id` |
| `ix_faculty_history_replay (faculty_id, field, seq)` | Replay; FK on `faculty_id` |
| `ix_faculty_history_actor` | FK |
| `ix_sih_pair (section_id, faculty_id, seq)` | Replay; FK on `section_id` |
| `ix_sih_faculty`, `ix_sih_actor` | FK |
| `ix_section_instructors_faculty (faculty_id, section_id)` | `sectionIdsForFaculty`; instructor conflict check |
| `ix_enrollments_section_status (section_id, status)` | Approved students per section; FK on `section_id` |
| `ix_enrollments_status (status, id)` | Registrar queue by status |
| `ix_enrollments_decided_by` | FK |
| `uq_enrollments_active` | FK on `student_id` (leading column) |
| `ix_eh_actor` | FK |
| `uq_grades_section_student` | Grade lookup per section; FK on `section_id` |
| `ix_grades_student_status (student_id, status)` | Student's grades by status; FK on `student_id` |
| `ix_gh_grade (grade_id, seq)` | Latest history row per grade; FK |
| `ix_gh_section_actor (section_id, actor_id)` | Separation-of-duties lookup; FK on `section_id` |
| `ix_gh_student`, `ix_gh_actor` | FK |

Not indexed, on purpose: name and title search columns (`LIKE '%x%'`), low-cardinality status columns on their own.

## 2. Migrations V0001–V0010

Statement counts are the `;`-terminated statements the runner's `splitStatements` would produce (comment lines dropped). Total: **28 statements**.

| Version | File | Purpose | Statements | Header dependencies | Lock row |
|---|---|---|---|---|---|
| V0001 | `V0001__infrastructure.sql` | `repository_locks` | 1 (1 CREATE) | none | — |
| V0002 | `V0002__auth.sql` | `users`, `user_roles`, `audit_logs` | 4 (3 CREATE, 1 INSERT) | V0001 | `auth` |
| V0003 | `V0003__students.sql` | `programs`, `students`, `student_academic_history` | 4 (3 CREATE, 1 INSERT) | V0001, V0002 | `students` |
| V0004 | `V0004__catalog.sql` | `terms`, `sections`, `section_meetings` | 4 (3 CREATE, 1 INSERT) | V0001 | `enrollment` |
| V0005 | `V0005__faculty.sql` | `faculty`, `faculty_history` | 3 (2 CREATE, 1 INSERT) | V0001, V0002 | `faculty` |
| V0006 | `V0006__section_instructors.sql` | `section_instructor_history`, `section_instructors` | 2 (2 CREATE) | V0002, V0004, V0005 | — |
| V0007 | `V0007__enrollments.sql` | `enrollments`, `enrollment_history` (T-22) | 2 (2 CREATE) | V0002, V0003, V0004 | — (reuses `enrollment`) |
| V0008 | `V0008__grades.sql` | `grades`, `grade_history` (T-32) | 3 (2 CREATE, 1 INSERT) | V0001–V0004, V0007 (ordering only; no FK to `enrollments`) | `grades` |
| V0009 | `V0009__grade_history_remarks.sql` | `grade_history.old_remarks`, `new_remarks` + 2 CHECKs | 3 (3 ALTER) | V0008 | — |
| V0010 | `V0010__grade_value_scale.sql` | `ck_grades_value` on `grades.value`, `ck_gh_value_scale` on `grade_history.old_value`/`new_value`: demo-approved scale (owner decision 2026-11-26), not the official policy | 2 (2 ALTER) | V0008 | — |

Notes:

- **Every file declares "Destructive: no"** and carries a `-- MANUAL RECOVERY` block (operator only, after backup and specific approval; never run by the runner). The recovery blocks contain DROP/DELETE/ALTER … DROP statements, so running any of them is a destructive operation that needs the owner's explicit approval (CLAUDE.md rule 3).
- **Header wording drift (documentation only):** the V0001 and V0006 headers say "inserts only repository_locks seed rows", but neither file inserts a row. `database/grants/app_grants.sql` says "Apply only after V0001-V0008"; its grants are table-level, so V0009's new columns need no grant change.
- **V0009** adds two nullable columns; existing rows keep NULL (not rewritten). No CHECK requires remarks on valued rows, because pre-V0009 rows can't be told apart from post-V0009 rows without remarks. The verifier uses V0009's `schema_migrations.finished_at` as the cutover (§4, `grade_history_replay`).
- **V0010** is additive and forward-only (recovery: `ALTER TABLE ... DROP CHECK` for both constraints, operator only, owner approval). Allowed codes: `1.00`, `1.25`, `1.50`, `1.75`, `2.00`, `2.25`, `2.50`, `2.75`, `3.00`, `5.00`, `INC`, `DRP` (exact, case-sensitive); must equal `GradeScale::demo()` (checked by `GradeMySQLTest`). `ck_grades_value_len` stays. The official university scale remains pending.
- **Version order equals dependency order.** The runner applies strictly ascending versions and refuses gaps.
- **Faculty-dependent target rule (S5):** `MigrationRunner::up()` refuses any target other than `ais_test` *before any write* while a V0005 file exists, and `assertFacultyTarget` refuses V0005 and every later version on a non-`ais_test` target ("until T-30 is merged"). In practice every migration currently runs only on `ais_test`.
- **Module data order** follows the FKs: auth → students → faculty → enrollment → grades.

## 3. Migration and rollback strategy

### 3.1 Files

- **Name:** `database/migrations/VNNNN__snake_name.sql` (regex `\AV([0-9]{4})__([a-z][a-z0-9_]*)\.sql\z`).
- **Immutable once applied to any database.** A change is always a new version (the checksum check enforces it).
- **Header:** purpose; dependencies; destructive or not; `-- MANUAL RECOVERY`.
- **Content:** DDL plus `repository_locks` seed rows. No other data; data comes from the importer (§5).

### 3.2 Runner (`database/migrate.php`, `MigrationRunner`)

- **Command:** `php database/migrate.php status|up --target=<database>` (CLI only). Uses the migration account; `SELECT DATABASE()` must equal the target.
- **Server prerequisites (`checkServer`):** MariaDB ≥ 10.4; database default collation `utf8mb4_nopad_bin` and that collation available; session `sql_mode` includes `STRICT_ALL_TABLES` and not `NO_BACKSLASH_ESCAPES`; session `time_zone = '+00:00'`.
- **Tracking:** `CREATE TABLE IF NOT EXISTS schema_migrations` (the only DDL outside a migration file).
- **Statement splitting:** a statement ends with `;` at the end of a line; `--` lines are dropped; a `;` anywhere else, or an unterminated statement, makes the file invalid.
- **Before applying:** every applied version's file exists with the same name and SHA-256; no row is `started`; history is strictly ascending; no unapplied file below the highest applied version. Any failure is a refusal.
- **Applying one version:** INSERT `state='started'`, `statements_applied=0`, `started_at=UTC_TIMESTAMP()`, `applied_by=CURRENT_USER()`; run each statement and increment the count; UPDATE to `applied` with `finished_at`. On the first error, stop and leave `started`.
- **Output:** version and name only; never SQL values or credentials. Re-running `up` after success is a no-op.

### 3.3 What is and isn't reversible

- **DDL isn't transactional in MariaDB:** each `CREATE`/`ALTER` commits implicitly. A failed migration can leave earlier statements of the same file applied. No document or tool may claim otherwise.
- **Forward-only:** no `down` command. Recovery is manual: restore the backup, or run the file's `MANUAL RECOVERY` statements with owner approval (destructive), remove the `started` row, re-run `up`.
- **Backups before every migration** on any database other than `ais_test` (`SECURITY.md` §7).

## 4. Verification command (`database/verify.php`, `IntegrityVerifier`)

- **Command:** `php database/verify.php --target=ais_test` (CLI only). Refuses unless `.env` has `DB_DATABASE=ais_test`, a loopback `DB_HOST`, a numeric port, and `DB_USERNAME` is `ais_tester` or `ais_test_app`; the live `DATABASE()`, `@@port` and `CURRENT_USER()` must match.
- **Read-only:** runs inside `START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT` (REPEATABLE READ) and rolls back. If the caller already has a transaction open (the importer), it uses that snapshot instead.
- **Output:** one line per check: `PASS`, `FAIL <ids>` or `REPORT <ids>`. Only numeric ids, never values. Checks whose offender has no numeric id (programs, lock rows) report with no ids.
- **Exit codes:** 0 clean (report-only findings don't count), 1 at least one non-report check failed, 2 refused or error.

**35 checks.** "Report-only" = printed as `REPORT` and excluded from the exit code (`verify.php` `REPORT_ONLY`). Only one check is report-only.

| # | Check | What it checks | Ids reported | Report-only |
|---|---|---|---|---|
| 1 | `user_model` | Each user row plus its roles passes `User::fromArray` | user id | no |
| 2 | `user_roles` | Each user has ≥ 1 role | user id | no |
| 3 | `student_model` | Each student row passes `Student::fromArray` | student id | no |
| 4 | `program_model` | Each program row passes `Program::fromArray` | none (string key) | no |
| 5 | `student_history_seq` | Per student, history `seq` is exactly 1..n | student id | no |
| 6 | `student_history_replay` | Every student has history; each `from_value` equals the previous `to_value` for that field; the last `to_value` of each tracked field equals the current column; no history for a missing student | student id | no |
| 7 | `student_year_level` | Program exists and `year_level ≤ max_year_level` | student id | no |
| 8 | `term_model` | Each term passes `Term::fromArray` | term id | no |
| 9 | `current_term` | At most one term has `is_current = 1` | term ids | no |
| 10 | `section_model` | Meeting positions are 0..n-1 and the section with its meetings passes `Section::fromArray` | section id | no |
| 11 | `scheduled_meetings` | A `scheduled` section has ≥ 1 meeting | section id | no |
| 12 | `meeting_self_overlap` | No two meetings of one section overlap (same day, intersecting times) | section id | no |
| 13 | `assignment_history_seq` | `section_instructor_history.seq` is exactly 1..n globally | seq | no |
| 14 | `assignment_history_replay` | `assigned`/`unassigned` alternate correctly per (section, faculty), and the replayed set equals `section_instructors` | section id | no |
| 15 | `assignment_assigned_seq` | Each `assigned_seq` is the latest `assigned` seq for its pair | section id | no |
| 16 | `instructor_cap` | ≤ 5 instructors per section | section id | no |
| 17 | `instructor_overlap` | No instructor has overlapping meetings in two sections of the same term | section ids | no |
| 18 | `faculty_model` | Ids ≥ 1, valid status, non-empty number/names/department (inline checks, not `Faculty::fromArray`) | faculty id | no |
| 19 | `faculty_history_seq` | `faculty_history.seq` is exactly 1..n globally | seq | no |
| 20 | `faculty_history_replay` | Faculty exists, field valid, `from_value` chain per (faculty, field), last value equals the current column | faculty id | no |
| 21 | `audit_ids` | `audit_logs.id` is exactly 1..n | audit id | no |
| 22 | `repository_last_seq` | Each lock row exists and `last_seq` equals its stream maximum (`auth` = max audit id, `students` = 0, `enrollment` = max assignment-history seq, `faculty` = max faculty-history seq, `grades` = max grade-history seq); no unknown rows | none (string key) | no |
| 23 | `enrollment_model` | Each enrollment passes `Enrollment::fromArray` (timestamps must round-trip exactly) | enrollment id | no |
| 24 | `enrollment_history_seq` | Per enrollment, history `seq` is exactly 1..n | enrollment id | no |
| 25 | `enrollment_history_replay` | Every enrollment has history; transitions are `requested`→pending, pending→approved/rejected/dropped, approved→dropped; `rejected` has a reason; final state equals `status`; no history for a missing enrollment | enrollment id | no |
| 26 | `enrollment_approved_count` | `sections.approved_count` equals the number of `approved` enrollments | section id | no |
| 27 | `enrollment_duplicate_active` | ≤ 1 pending/approved enrollment per (student, section) | enrollment ids | no |
| 28 | `enrollment_schedule_clash` | A student's approved sections in one term have no overlapping meetings | section ids | no |
| 29 | `grade_model` | Each grade passes `Grade::fromArray` | grade id | no |
| 30 | `grade_value_scale` | `grades.value` is one of the 12 approved academic demonstration codes | grade id | no |
| 31 | `grade_history_seq` | `grade_history.seq` is exactly 1..n globally | seq | no |
| 32 | `grade_history_replay` | Per grade: transitions `entered` (new→draft), `updated` (draft→draft), `submitted` (draft→submitted), `returned` (submitted→draft), `finalized` (submitted→finalized), `corrected` (finalized→finalized); value columns per action; `updated`/`corrected` `old_value` equals the current value and `corrected` changes it; reason iff `corrected`/`returned`; section/student unchanged; remarks rules from V0009 (strict for rows at or after V0009 `finished_at`, tolerant for earlier rows with both remarks NULL); final replay equals the `grades` row | grade id | no |
| 33 | `grade_history_value_scale` | Every non-NULL `old_value` and `new_value` uses the same 12 demo codes | history seq | no |
| 34 | `grade_enrollment` | Every non-draft grade has an `approved` event in `enrollment_history` for the same (student, section) | grade id | no |
| 35 | `finalized_section_missing_grade` | An approved enrollment in a section with ≥ 1 finalized grade has no grade row | section id | **yes** |

Known limit (source comment): the V0009 cutover compares the app-supplied `at`, so a backdated row would be checked with the tolerant rule.

## 5. Data migration from the JSON stores (`database/import_json.php`, `JsonImporter`)

### 5.1 Tool and modules

`php database/import_json.php --module=<module> --runtime=<path> --target=ais_test [--dry-run]`. CLI only, one module per run, migration account `ais_tester` only.

**Import order: auth → students → faculty → enrollment.** Grades are never imported (see the last row).

| Module | Status | Source files | Tables written |
|---|---|---|---|
| `auth` | supported | `auth/users.json` | `users`, `user_roles`, `audit_logs` |
| `students` | supported | `students/students.json`, `students/programs.json` | `programs`, `students`, `student_academic_history` |
| `faculty` | supported (since `405e013`; UNVERIFIED) | `faculty/faculty.json` | `faculty`, `faculty_history`; sets `repository_locks.faculty` |
| `enrollment` | supported | `enrollment/enrollment.json` | `terms`, `sections`, `section_meetings`, `section_instructor_history`, `section_instructors`, `enrollments`, `enrollment_history` |
| grades | **refused** (no module, fail closed) | — | Every module import is refused if `<runtime>/faculty/grades.json` exists: "Grades import refused: historical grade import is deferred." This remains true with the academic demonstration scale enabled. Also refused if `grades`/`grade_history` hold rows |

### 5.2 Preconditions (the tool refuses otherwise)

1. Arguments: exactly the options above, each once; `--target=ais_test`; an absolute, existing runtime path that is not a UNC path, not `C:/ais-runtime`, not `storage/runtime` and not the `.env` `APP_RUNTIME_PATH` (the live runtime).
2. `.env` target is `ais_test` on loopback with a numeric port; the live `DATABASE()`, `@@port` and `CURRENT_USER()` (`ais_tester@…`) match.
3. Each source file and its `.lock` file exist.
4. The schema is fully applied: `schema_migrations` validates against the files and has one row per file (V0001–V0010).
5. The source validates through the mock code (on a private snapshot copy), and the target passes **all 33 verifier checks with no findings, including the report-only one**.
6. Inside the transaction: the module's `repository_locks.last_seq = 0`; the module's tables and `grades`/`grade_history` are empty.
7. Dependencies: `students`, `faculty` and `enrollment` need `users` rows ("Auth dependency missing."); `faculty` needs a `users` row for every profile `user_id` and history `actor_id` ("Orphan user id."); `enrollment` with any instructor assignment or assignment history refuses an empty `faculty` table ("Faculty dependency missing.", exit 2), then needs a `faculty` row for every referenced `faculty_id` and a `students` row for every enrollment `student_id` (orphans abort and roll back).
8. The app is stopped for the whole import, and copies of the JSON files plus a DB backup are taken (operator steps; printed as a reminder).

### 5.3 Steps (one DB transaction per module)

1. Hash the source files under shared locks; read raw JSON; validate through the mock repositories.
2. `BEGIN`; lock the module's `repository_locks` row `FOR UPDATE`; check emptiness and dependencies.
3. INSERT every row with its original id and seq. `faculty`: profiles, then `faculty_history` (global seq). `enrollment`: `sections[].meetings[i]` → `section_meetings(position = i)`; `assignments` → `section_instructors` with `assigned_seq` = the latest `assigned` seq of the pair; then `enrollments` and `enrollment_history`.
4. Set `last_seq`: `auth` = max audit id; `faculty` = max faculty-history seq; `enrollment` = max assignment-history seq (enrollment history seq is per enrollment).
5. Before COMMIT: per-table counts equal the source counts; adapter output equals mock output (interface equality); all verifier checks clean; source hashes unchanged.
6. COMMIT, or ROLLBACK on any failure (exit 1, "no module data committed"); refusals exit 2. `--dry-run` always rolls back. Output: table counts only.

### 5.4 Rollback

| When | How | Data loss |
|---|---|---|
| During the import | Automatic ROLLBACK | None |
| After COMMIT, before go-live | Keep the module on `mock`. To retry: restore the pre-import backup (destructive: owner approval) | None; the JSON files are never written |
| After go-live | Switch the module back to `mock` | Writes since go-live are lost (no dual-write). Go/no-go gate |

### 5.5 Production cutover

Not part of T-60. It needs an owner-approved runbook, reviewed test results, a supported production DB version (OQ-12), and the production prerequisites in `config/app.php`. The test-only gate in `app/bootstrap.php` refuses MySQL outside `ais_test` today.
