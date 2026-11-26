# AIS data dictionary (T-60)

> **Status: source reviewed on `task/INT-src` during the 2026-11-26 takeover; not executed.** All 18 tables are implemented by V0001–V0009; V0010 (`V0010__grade_value_scale.sql`) adds only two CHECKs, `ck_grades_value` on `grades.value` and `ck_gh_value_scale` on `grade_history.old_value`/`new_value`, both limited to the owner-approved **academic demonstration** scale (not the official university policy, which is deferred). Every column, key and constraint below was read from `database/migrations/V0001`–`V0010`, `database/grants/app_grants.sql` and `database/tools/MigrationRunner.php` (for `schema_migrations`). Nothing was run against a database to produce this page. Conditional owner approval (2026-11-21, safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0) still applies: `ais_test` only, no cutover.

## 0. Conventions

- **Engine and text:** every table is `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin` (`ARCHITECTURE.md` §1).
- **Defaults:** no column has a `DEFAULT` clause. "—" in the Default column means the adapter writes the value explicitly (`ARCHITECTURE.md` §2). The only generated columns are `terms.current_marker` and `enrollments.active_section_id` (both `PERSISTENT`).
- **Foreign keys:** every FK is `ON DELETE RESTRICT ON UPDATE RESTRICT`. There is no CASCADE and no SET NULL.
- **CHECKs:** the exact SQL text is in the migration file named per table. This page gives the name and the meaning.
- **Privileges:** "App" is the app account `{{APP_USER}}@'127.0.0.1'` from `database/grants/app_grants.sql` (Phase 5 substitution: `ais_test_app` on `ais_test`). Grants are table-level, so columns added later (V0009) are covered without a grant change. The migration account (`ais_tester`) owns DDL and imports.
- **Owner:** the module and the MySQL adapter class that read and write the table at runtime, and the `repository_locks` row that serializes its writes. All adapters run only behind the test-only gate in `app/bootstrap.php` (`ARCHITECTURE.md` §10).

### 0.1 Table index (18 tables + `schema_migrations`)

| # | Table | Migration | Module / owning adapter | Lock row | App privileges |
|---|---|---|---|---|---|
| 1 | `repository_locks` | V0001 | Infrastructure; every adapter | (is the lock) | SELECT, UPDATE |
| 2 | `users` | V0002 | Auth / `MySQLUserRepository`, `MySQLUserTransaction` | `auth` | SELECT, INSERT, UPDATE |
| 3 | `user_roles` | V0002 | Auth / same | `auth` | SELECT, INSERT, DELETE |
| 4 | `audit_logs` | V0002 | Auth / same | `auth` | SELECT, INSERT |
| 5 | `programs` | V0003 | Students / `MySQLProgramCatalog` (read only) | — | SELECT |
| 6 | `students` | V0003 | Students / `MySQLStudentRepository` | `students` | SELECT, INSERT, UPDATE |
| 7 | `student_academic_history` | V0003 | Students / `MySQLStudentRepository` | `students` | SELECT, INSERT |
| 8 | `terms` | V0004 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT, UPDATE |
| 9 | `sections` | V0004 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT, UPDATE |
| 10 | `section_meetings` | V0004 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT, DELETE |
| 11 | `faculty` | V0005 | Faculty / `MySQLFacultyRepository` | `faculty` | SELECT, INSERT, UPDATE |
| 12 | `faculty_history` | V0005 | Faculty / `MySQLFacultyRepository` | `faculty` | SELECT, INSERT |
| 13 | `section_instructor_history` | V0006 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT |
| 14 | `section_instructors` | V0006 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT, DELETE |
| 15 | `enrollments` | V0007 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT, UPDATE |
| 16 | `enrollment_history` | V0007 | Enrollment / `MySQLCatalogRepository` | `enrollment` | SELECT, INSERT |
| 17 | `grades` | V0008 | Grades / `MySQLGradeRepository` (`app/Faculty/Repositories/`) | `grades` | SELECT, INSERT, UPDATE |
| 18 | `grade_history` | V0008, altered V0009 | Grades / `MySQLGradeRepository` | `grades` | SELECT, INSERT |
| — | `schema_migrations` | runner (`MigrationRunner::ensureTrackingTable`) | Migration tooling | — | SELECT |

No table grants the app DELETE except `user_roles`, `section_meetings` and `section_instructors` (current-state child rows; `ARCHITECTURE.md` §5). Every history and audit table is SELECT, INSERT only (append-only by privilege).

## 1. Infrastructure

### 1.1 `repository_locks` (V0001)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `name` | VARCHAR(32) | no | — | PK |
| `last_seq` | BIGINT UNSIGNED | no | — | Last gap-free value issued for that store's global stream |

- **PK:** `name`. No UNIQUE, FK, CHECK or secondary index.
- **Seed rows** (inserted by migrations, `last_seq = 0`): `auth` (V0002), `students` (V0003), `enrollment` (V0004), `faculty` (V0005), `grades` (V0008). V0001, V0006, V0007 and V0009 insert no row (V0007 reuses `enrollment`).
- **Stream per row** (as checked by `repository_last_seq`): `auth` = MAX(`audit_logs.id`); `students` = always 0 (per-student seq); `enrollment` = MAX(`section_instructor_history.seq`) (enrollment history seq is per enrollment, not global); `faculty` = MAX(`faculty_history.seq`); `grades` = MAX(`grade_history.seq`).
- **Privileges:** App SELECT, UPDATE (no INSERT/DELETE: the set of rows is fixed by migrations).

### 1.2 `schema_migrations` (created by the runner)

Created by `MigrationRunner::ensureTrackingTable()` with `CREATE TABLE IF NOT EXISTS` before the first migration. Never created by the app.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `version` | INT UNSIGNED | no | — | PK; `NNNN` from `VNNNN__name.sql` |
| `name` | VARCHAR(100) | no | — | File name part after `__` |
| `checksum` | CHAR(64) | no | — | SHA-256 of the file bytes |
| `state` | VARCHAR(7) | no | — | `started` or `applied` |
| `statements_applied` | INT UNSIGNED | no | — | Progress of an interrupted run |
| `started_at` | DATETIME | no | — | `UTC_TIMESTAMP()` |
| `finished_at` | DATETIME | yes | — | Set on `applied`. V0009's value is the grade-history remarks cutover used by `grade_history_replay` |
| `applied_by` | VARCHAR(80) | no | — | `CURRENT_USER()` |

- **CHECKs:** `ck_schema_migrations_checksum` (lowercase 64-hex, `\z` anchored); `ck_schema_migrations_state` (`started`,`applied`).
- **Privileges:** App SELECT only.

## 2. Auth (V0002) — module `auth`, adapters `MySQLUserRepository` + `MySQLUserTransaction`, lock `auth`

### 2.1 `users`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK. No CHECK: MariaDB rejects CHECK on AUTO_INCREMENT (error 1901); unsigned AUTO_INCREMENT already gives ≥ 1 |
| `email` | VARCHAR(254) | no | — | Normalized lowercase |
| `status` | VARCHAR(8) | no | — | |
| `password_hash` | VARCHAR(255) | no | — | Never exposed by `toPublicArray` |
| `auth_version` | INT UNSIGNED | no | — | Bumped to revoke sessions (B-09) |

- **UNIQUE:** `uq_users_email (email)`.
- **CHECKs:** `ck_users_email` (equals `LOWER(email)`, length 3–254); `ck_users_status` (`active`,`inactive`); `ck_users_password_hash` (length ≥ 20; algorithm checked by the model); `ck_users_auth_version` (≥ 1).
- **FKs:** none. No timestamps (the model has none).
- **Privileges:** App SELECT, INSERT, UPDATE.

### 2.2 `user_roles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | INT UNSIGNED | no | — | PK part 1 |
| `role` | VARCHAR(9) | no | — | PK part 2 |

- **PK:** `(user_id, role)`. **Index:** `ix_user_roles_role (role, user_id)` (last-admin count, "holds `faculty`").
- **FK:** `fk_user_roles_user` `user_id` → `users.id` RESTRICT.
- **CHECK:** `ck_user_roles_role` (`student`,`faculty`,`registrar`,`admin`).
- "At least one role per user" is app-enforced (`User::fromArray`) and verified by `user_roles`.
- **Privileges:** App SELECT, INSERT, DELETE (a role change replaces the set).

### 2.3 `audit_logs`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | — | PK; `repository_locks('auth').last_seq + 1` |
| `at` | DATETIME | no | — | UTC |
| `actor_id` | INT UNSIGNED | no | — | Signed-in admin |
| `target_id` | INT UNSIGNED | no | — | **No FK, no CHECK** (0 = the account a denied create would have made) |
| `action` | VARCHAR(64) | no | — | No value list (the contract accepts any action string) |
| `outcome` | VARCHAR(7) | no | — | |
| `changes` | LONGTEXT | no | — | JSON; key filter and secret filter are app-enforced |

- **Index:** `ix_audit_logs_actor (actor_id)`.
- **FK:** `fk_audit_logs_actor` `actor_id` → `users.id` RESTRICT.
- **CHECKs:** `ck_audit_logs_id` (≥ 1); `ck_audit_logs_actor` (≥ 1); `ck_audit_logs_action` (length ≥ 1); `ck_audit_logs_outcome` (`success`,`denied`); `ck_audit_logs_changes` (`JSON_VALID`).
- **Privileges:** App SELECT, INSERT.

## 3. Students (V0003) — module `students`, adapters `MySQLProgramCatalog`, `MySQLStudentRepository`, lock `students`

### 3.1 `programs`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `code` | VARCHAR(20) | no | — | PK (natural key) |
| `name` | VARCHAR(200) | no | — | Limited in **bytes** (the model uses `strlen`) |
| `max_year_level` | TINYINT UNSIGNED | no | — | |

- **CHECKs:** `ck_programs_code` (REGEXP `^[A-Z0-9][A-Z0-9-]{0,19}\z`); `ck_programs_name` (non-empty, `OCTET_LENGTH ≤ 200`); `ck_programs_max_year` (1–8).
- **Privileges:** App SELECT only. Rows come from the importer (`students` module) or the migration account.

### 3.2 `students`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK |
| `user_id` | INT UNSIGNED | no | — | One profile per account |
| `student_number` | VARCHAR(32) | no | — | Hard format limit; configurable pattern in `StudentNumberPolicy` |
| `first_name` | VARCHAR(100) | no | — | |
| `middle_name` | VARCHAR(100) | yes | — | NULL or non-empty |
| `last_name` | VARCHAR(100) | no | — | |
| `program_code` | VARCHAR(20) | no | — | |
| `year_level` | TINYINT UNSIGNED | no | — | ≤ program max: app-enforced, verified by `student_year_level` |
| `academic_status` | VARCHAR(11) | no | — | |
| `admission_year` | SMALLINT UNSIGNED | yes | — | Upper bound (`now + 1`) app-enforced |
| `created_at` | DATETIME | no | — | UTC |
| `updated_at` | DATETIME | no | — | UTC |

- **UNIQUE:** `uq_students_user (user_id)`, `uq_students_number (student_number)`. **Index:** `ix_students_program (program_code)`.
- **FKs:** `fk_students_user` `user_id` → `users.id`; `fk_students_program` `program_code` → `programs.code` (both RESTRICT).
- **CHECKs:** `ck_students_number` (REGEXP `^[A-Z0-9/-]{1,32}\z`); `ck_students_names` (first/last non-empty, middle NULL or non-empty); `ck_students_year` (1–8); `ck_students_status` (`active`,`inactive`,`graduated`,`transferred`,`on_leave`); `ck_students_admission` (NULL or ≥ 1900).
- **Privileges:** App SELECT, INSERT, UPDATE.

### 3.3 `student_academic_history`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `student_id` | INT UNSIGNED | no | — | PK part 1 |
| `seq` | INT UNSIGNED | no | — | PK part 2; gap-free per student (`MAX(seq)+1` under the lock) |
| `at` | DATETIME | no | — | UTC |
| `field` | VARCHAR(15) | no | — | |
| `from_value` | VARCHAR(20) | yes | — | NULL only on creation entries |
| `to_value` | VARCHAR(20) | no | — | |
| `actor_id` | INT UNSIGNED | no | — | **No FK, no CHECK** (0 = system/seeder) |

- **PK:** `(student_id, seq)`. No secondary index.
- **FK:** `fk_sah_student` `student_id` → `students.id` RESTRICT.
- **CHECKs:** `ck_sah_seq` (≥ 1); `ck_sah_field` (`program_code`,`year_level`,`academic_status`); `ck_sah_program` (program-code format for both values when `field = 'program_code'`); `ck_sah_year` (`^[1-8]\z` when `field = 'year_level'`); `ck_sah_status` (status values when `field = 'academic_status'`).
- **Privileges:** App SELECT, INSERT.

## 4. Enrollment catalog (V0004) — module `enrollment`, adapter `MySQLCatalogRepository`, lock `enrollment`

### 4.1 `terms`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK |
| `code` | VARCHAR(20) | no | — | Immutable (T-20 P4) |
| `name` | VARCHAR(100) | no | — | |
| `start_date` | DATE | no | — | |
| `end_date` | DATE | no | — | |
| `is_current` | TINYINT(1) | no | — | |
| `enrollment_open` | TINYINT(1) | no | — | Read by T-22 |
| `current_marker` | TINYINT | yes | **generated** `AS (IF(is_current = 1, 1, NULL)) PERSISTENT` | Never written by the adapter |
| `created_at` | DATETIME | no | — | UTC |
| `updated_at` | DATETIME | no | — | UTC |

- **UNIQUE:** `uq_terms_code (code)`; `uq_terms_current (current_marker)` (at most one current term; NULLs do not collide).
- **CHECKs:** `ck_terms_code` (REGEXP `^[A-Z0-9][A-Z0-9-]{1,19}\z`); `ck_terms_name` (non-empty); `ck_terms_dates` (`start_date < end_date`); `ck_terms_flags` (both flags in 0,1).
- **Privileges:** App SELECT, INSERT, UPDATE.

### 4.2 `sections`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK |
| `term_id` | INT UNSIGNED | no | — | Immutable (P5) |
| `course_code` | VARCHAR(20) | no | — | **No FK** (D1: no course catalog) |
| `course_title` | VARCHAR(150) | no | — | |
| `units` | TINYINT UNSIGNED | no | — | |
| `section_code` | VARCHAR(10) | no | — | |
| `capacity` | SMALLINT UNSIGNED | no | — | |
| `approved_count` | SMALLINT UNSIGNED | no | — | = approved enrollments; changed under the `enrollment` lock; verified by `enrollment_approved_count` |
| `delivery_mode` | VARCHAR(12) | no | — | |
| `created_at` | DATETIME | no | — | UTC |
| `updated_at` | DATETIME | no | — | UTC |

- **UNIQUE:** `uq_sections_term_course_section (term_id, course_code, section_code)` (natural key; also serves the `term_id` FK, filter and list order).
- **FK:** `fk_sections_term` `term_id` → `terms.id` RESTRICT.
- **CHECKs:** `ck_sections_course` (REGEXP `^[A-Z0-9][A-Z0-9-]{1,19}\z`); `ck_sections_section` (REGEXP `^[A-Z0-9][A-Z0-9-]{0,9}\z`); `ck_sections_title` (non-empty); `ck_sections_units` (1–12); `ck_sections_capacity` (1–1000 and `approved_count <= capacity`); `ck_sections_mode` (`scheduled`,`tba`,`online_async`).
- **Privileges:** App SELECT, INSERT, UPDATE.

### 4.3 `section_meetings`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `section_id` | INT UNSIGNED | no | — | PK part 1 |
| `position` | TINYINT UNSIGNED | no | — | PK part 2; 0-based list index (at most 14 meetings) |
| `day` | CHAR(3) | no | — | |
| `start_time` | CHAR(5) | no | — | `HH:MM` |
| `end_time` | CHAR(5) | no | — | `HH:MM` |
| `room` | VARCHAR(30) | no | — | |

- **PK:** `(section_id, position)`.
- **FK:** `fk_section_meetings_section` `section_id` → `sections.id` RESTRICT.
- **CHECKs:** `ck_section_meetings_position` (≤ 13); `ck_section_meetings_day` (`MON`…`SUN`); `ck_section_meetings_times` (both `HH:MM` 00:00–23:59 and `start_time < end_time`); `ck_section_meetings_room` (non-empty).
- Cross-row rules (≥ 1 meeting if `scheduled`, no self-overlap, contiguous positions) are app-enforced and verified (`scheduled_meetings`, `meeting_self_overlap`, `section_model`).
- **Privileges:** App SELECT, INSERT, DELETE (PATCH replaces the list).

## 5. Faculty (V0005) — module `faculty`, adapter `MySQLFacultyRepository`, lock `faculty`

Created by V0005. The runner applies V0005 and later only to `ais_test` (`MigrationRunner::assertFacultyTarget`, "until T-30 is merged").

### 5.1 `faculty`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK; is the `faculty_id` used by assignments; never reused |
| `user_id` | INT UNSIGNED | no | — | Immutable |
| `employee_number` | VARCHAR(32) | no | — | Immutable; configurable pattern in `EmployeeNumberPolicy` |
| `first_name` | VARCHAR(70) | no | — | |
| `last_name` | VARCHAR(70) | no | — | |
| `department` | VARCHAR(100) | no | — | |
| `status` | VARCHAR(8) | no | — | |
| `created_at` | DATETIME | no | — | UTC |
| `updated_at` | DATETIME | no | — | UTC |

- **UNIQUE:** `uq_faculty_user (user_id)`, `uq_faculty_employee_number (employee_number)`.
- **FK:** `fk_faculty_user` `user_id` → `users.id` RESTRICT.
- **CHECKs:** `ck_faculty_employee_number` (REGEXP `^[A-Z0-9/-]{1,32}\z`); `ck_faculty_names` (first, last, department non-empty); `ck_faculty_status` (`active`,`inactive`).
- **Privileges:** App SELECT, INSERT, UPDATE.

### 5.2 `faculty_history`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `seq` | BIGINT UNSIGNED | no | — | PK; global gap-free from `repository_locks('faculty')` |
| `at` | DATETIME | no | — | UTC |
| `faculty_id` | INT UNSIGNED | no | — | |
| `field` | VARCHAR(10) | no | — | |
| `from_value` | VARCHAR(100) | yes | — | NULL only on the first entry per (faculty, field) |
| `to_value` | VARCHAR(100) | no | — | |
| `actor_id` | INT UNSIGNED | no | — | |

- **Indexes:** `ix_faculty_history_replay (faculty_id, field, seq)`, `ix_faculty_history_actor (actor_id)`.
- **FKs:** `fk_faculty_history_faculty` → `faculty.id`; `fk_faculty_history_actor` → `users.id` (RESTRICT).
- **CHECKs:** `ck_faculty_history_seq` (`seq ≥ 1` and `actor_id ≥ 1`); `ck_faculty_history_field` (`status`,`department`); `ck_faculty_history_status` (status values when `field = 'status'`); `ck_faculty_history_values` (values non-empty when present).
- **Privileges:** App SELECT, INSERT.

## 6. Instructor assignments (V0006) — module `enrollment`, adapter `MySQLCatalogRepository`, lock `enrollment`

### 6.1 `section_instructor_history`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `seq` | BIGINT UNSIGNED | no | — | PK; global gap-free from `repository_locks('enrollment')` |
| `at` | DATETIME | no | — | UTC |
| `action` | VARCHAR(10) | no | — | |
| `section_id` | INT UNSIGNED | no | — | |
| `faculty_id` | INT UNSIGNED | no | — | |
| `actor_id` | INT UNSIGNED | no | — | |

- **Indexes:** `ix_sih_pair (section_id, faculty_id, seq)`, `ix_sih_faculty (faculty_id)`, `ix_sih_actor (actor_id)`.
- **FKs:** `fk_sih_section` → `sections.id`; `fk_sih_faculty` → `faculty.id`; `fk_sih_actor` → `users.id` (RESTRICT).
- **CHECKs:** `ck_sih_seq` (`seq ≥ 1` and `actor_id ≥ 1`); `ck_sih_action` (`assigned`,`unassigned`).
- **Privileges:** App SELECT, INSERT.

### 6.2 `section_instructors`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `section_id` | INT UNSIGNED | no | — | PK part 1 |
| `faculty_id` | INT UNSIGNED | no | — | PK part 2 |
| `assigned_seq` | BIGINT UNSIGNED | no | — | The `assigned` history row that created this assignment; gives assignment order |

- **PK:** `(section_id, faculty_id)`. **UNIQUE:** `uq_section_instructors_seq (assigned_seq)`. **Index:** `ix_section_instructors_faculty (faculty_id, section_id)`.
- **FKs:** `fk_si_section` → `sections.id`; `fk_si_faculty` → `faculty.id`; `fk_si_history` `assigned_seq` → `section_instructor_history.seq` (RESTRICT).
- No CHECK. ≤ 5 per section and no same-term overlap per instructor are app-enforced (verified by `instructor_cap`, `instructor_overlap`).
- **Privileges:** App SELECT, INSERT, DELETE (unassign; the `unassigned` history row keeps the record).

## 7. Enrollments (V0007) — module `enrollment`, adapter `MySQLCatalogRepository`, lock `enrollment`

Created by V0007 (T-22). It inserts no lock row: the `enrollment` lock from V0004 is reused, so `approved_count` and the enrollment row change atomically.

### 7.1 `enrollments`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK |
| `student_id` | INT UNSIGNED | no | — | |
| `section_id` | INT UNSIGNED | no | — | `term_id` is not stored; it is derived through `sections` |
| `status` | VARCHAR(8) | no | — | |
| `requested_at` | DATETIME | no | — | UTC |
| `decided_at` | DATETIME | yes | — | Set by approve/reject |
| `decided_by` | INT UNSIGNED | yes | — | Deciding user |
| `dropped_at` | DATETIME | yes | — | Set by drop |
| `reason` | VARCHAR(255) | yes | — | Required when rejected |
| `active_section_id` | INT UNSIGNED | yes | **generated** `AS (IF(status IN ('pending','approved'), section_id, NULL)) PERSISTENT` | Never written by the adapter |

- **UNIQUE:** `uq_enrollments_active (student_id, active_section_id)`: at most one pending/approved row per (student, section); rejected/dropped rows have NULL and never collide.
- **Indexes:** `ix_enrollments_section_status (section_id, status)`, `ix_enrollments_status (status, id)`, `ix_enrollments_decided_by (decided_by)`.
- **FKs:** `fk_enrollments_student` → `students.id`; `fk_enrollments_section` → `sections.id`; `fk_enrollments_decider` `decided_by` → `users.id` (RESTRICT).
- **CHECKs:**
  - `ck_enrollments_status`: `pending`, `approved`, `rejected`, `dropped`.
  - `ck_enrollments_decision`: `pending` has no decision and no drop; `approved`/`rejected` have `decided_at` and `decided_by` and no drop; `dropped` has `dropped_at` and either both decision columns or neither (dropped from pending or from approved).
  - `ck_enrollments_order`: `decided_at` and `dropped_at`, when set, are ≥ `requested_at`.
  - `ck_enrollments_reason`: `reason` NULL or non-empty.
  - `ck_enrollments_reject_reason`: `rejected` requires a `reason`.
- **Privileges:** App SELECT, INSERT, UPDATE.

### 7.2 `enrollment_history`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `enrollment_id` | INT UNSIGNED | no | — | PK part 1 |
| `seq` | INT UNSIGNED | no | — | PK part 2; gap-free **per enrollment** (not a `repository_locks` stream) |
| `at` | DATETIME | no | — | UTC |
| `action` | VARCHAR(10) | no | — | |
| `actor_id` | INT UNSIGNED | no | — | |
| `reason` | VARCHAR(255) | yes | — | |

- **PK:** `(enrollment_id, seq)`. **Index:** `ix_eh_actor (actor_id)`.
- **FKs:** `fk_eh_enrollment` → `enrollments.id`; `fk_eh_actor` → `users.id` (RESTRICT).
- **CHECKs:** `ck_eh_seq` (`seq ≥ 1` and `actor_id ≥ 1`); `ck_eh_action` (`requested`,`approved`,`rejected`,`dropped`); `ck_eh_reason` (NULL or non-empty); `ck_eh_reject_reason` (`rejected` requires a reason).
- **Privileges:** App SELECT, INSERT.

## 8. Grades (V0008–V0010) — module `grades`, adapter `MySQLGradeRepository`, lock `grades`

Created by V0008 (T-32); V0009 adds the remarks columns to `grade_history`. The adapter lives in `app/Faculty/Repositories/` but is selected by its own config key (`APP_REPOSITORY_GRADES`), which requires students and enrollment on MySQL. Finalize also takes the `enrollment` lock (`config/app.php` comment).

**Grade value domain:** V0010 demo-only scale (owner decision 2026-11-26), enforced by `ck_grades_value`; the official university policy is pending (B-05) and will replace it via a later forward-only migration (see docs/plans/GRADE_SCALE_ACTIVATION.md). The length (1–8 characters) is also constrained.

### 8.1 `grades`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK |
| `section_id` | INT UNSIGNED | no | — | |
| `student_id` | INT UNSIGNED | no | — | |
| `value` | VARCHAR(8) | no | — | **V0010**: one of the 12 demo-approved codes (`ck_grades_value`); demo-only, official university policy pending (B-05) |
| `remarks` | VARCHAR(255) | yes | — | NULL or non-empty |
| `status` | VARCHAR(9) | no | — | |
| `created_at` | DATETIME | no | — | UTC |
| `updated_at` | DATETIME | no | — | UTC |

- **UNIQUE:** `uq_grades_section_student (section_id, student_id)`: one grade per section and student. **Index:** `ix_grades_student_status (student_id, status)`.
- **FKs:** `fk_grades_section` → `sections.id`; `fk_grades_student` → `students.id` (RESTRICT). **No FK to `enrollments`** (V0007 is an ordering dependency only); the link is verified by `grade_enrollment`.
- **CHECKs:** `ck_grades_status` (`draft`,`submitted`,`finalized`); `ck_grades_value_len` (1–8 characters); `ck_grades_value` (V0010, owner decision 2026-11-26: `1.00`, `1.25`, `1.50`, `1.75`, `2.00`, `2.25`, `2.50`, `2.75`, `3.00`, `5.00`, `INC`, `DRP`, compared exactly because the table collation is `utf8mb4_nopad_bin`; this demo scale is not the official university policy, which stays pending and unconfigured in `GradeScale::official()`); `ck_grades_remarks` (NULL or non-empty); `ck_grades_order` (`updated_at ≥ created_at`).
- **Privileges:** App SELECT, INSERT, UPDATE.

### 8.2 `grade_history`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `seq` | BIGINT UNSIGNED | no | — | PK; global gap-free from `repository_locks('grades')` |
| `at` | DATETIME | no | — | UTC |
| `action` | VARCHAR(9) | no | — | |
| `grade_id` | INT UNSIGNED | no | — | |
| `section_id` | INT UNSIGNED | no | — | Copy of the grade's section (verified by replay) |
| `student_id` | INT UNSIGNED | no | — | Copy of the grade's student (verified by replay) |
| `old_value` | VARCHAR(8) | yes | — | |
| `new_value` | VARCHAR(8) | yes | — | |
| `old_remarks` | VARCHAR(255) | yes | — | **V0009**, after `new_value`; pre-V0009 rows keep NULL |
| `new_remarks` | VARCHAR(255) | yes | — | **V0009**, after `old_remarks`; pre-V0009 rows keep NULL |
| `reason` | VARCHAR(255) | yes | — | Required for `corrected`, `returned` |
| `actor_id` | INT UNSIGNED | no | — | |

- **Indexes:** `ix_gh_grade (grade_id, seq)`, `ix_gh_section_actor (section_id, actor_id)` (separation-of-duties lookup), `ix_gh_student (student_id)`, `ix_gh_actor (actor_id)`.
- **FKs:** `fk_gh_grade` → `grades.id`; `fk_gh_section` → `sections.id`; `fk_gh_student` → `students.id`; `fk_gh_actor` → `users.id` (RESTRICT).
- **CHECKs (V0008):**
  - `ck_gh_seq`: `seq ≥ 1` and `actor_id ≥ 1`.
  - `ck_gh_action`: `entered`, `updated`, `submitted`, `returned`, `finalized`, `corrected`.
  - `ck_gh_reason`: `reason` is present exactly when the action is `corrected` or `returned`.
  - `ck_gh_reason_text`: `reason` NULL or non-empty.
  - `ck_gh_value_len`: each value NULL or 1–8 characters.
  - `ck_gh_values`: `entered` has only `new_value`; `updated`/`corrected` have both values; `submitted`/`returned`/`finalized` have neither.
- **CHECKs (V0009):**
  - `ck_gh_remarks_text`: each remarks column NULL or non-empty.
  - `ck_gh_remarks_unvalued`: remarks may be set only on valued actions (`entered`, `updated`, `corrected`).
  - Deliberately **no** CHECK requires remarks on valued rows, because pre-V0009 rows cannot be told apart from post-V0009 rows without remarks. The verifier applies the strict rule only to rows at or after V0009's `finished_at`.
- **CHECK (V0010):** `ck_gh_value_scale`: `old_value` and `new_value` are each NULL or one of the same 12 demo codes as `ck_grades_value`.
- **Privileges:** App SELECT, INSERT (table-level, so V0009's columns are covered; the grant file header says "after V0001-V0010").

## 9. Not a table

- **`courses` (D1, provisional):** no table and no migration. `sections.course_code`, `course_title` and `units` stay format-checked free values. When D1 is decided, an additive migration may add `courses(code PK, …)` and an FK from `sections.course_code`, after data reconciliation.
- **`roles`:** no table; the role set is the CHECK on `user_roles.role` (`ARCHITECTURE.md` §4, OQ-2).

## 10. DDL text

The authoritative DDL is the migration files themselves (`database/migrations/V0001__infrastructure.sql` … `V0009__grade_history_remarks.sql`). The earlier "proposed DDL" copy in this file was removed so the two cannot drift. Notes that still apply to the files:

- One statement per `;`-terminated line ending (`CONSTRAINTS_AND_MIGRATIONS.md` §3.2).
- `\\z` in a SQL literal is the PCRE end-of-subject anchor (stricter than `$`). The session must not set `NO_BACKSLASH_ESCAPES`, and `sql_mode` must include `STRICT_ALL_TABLES` (both checked by the runner).
