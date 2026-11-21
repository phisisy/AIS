# AIS data dictionary (T-60)

> **Status: CONDITIONALLY APPROVED (owner, 2026-11-21; safeguards S1–S10 in `docs/plans/T-60-database-architecture.md` §0). Implementation and testing against `ais_test` only; no cutover. Original note:** The DDL in §7 is **text only, proposed**. It must not be executed until the owner approves the schema (B-03), and then only through the Phase 5 migration tooling.

Legend for the status column:

- **C** = confirmed. The column is taken from implemented, merged code.
- **CP** = confirmed by an approved plan, but not implemented (T-30).
- **P** = provisional. Not for creation; T-22, T-32 and D1 decide.
- **I** = infrastructure. Owned by the database tooling.

Every table has charset `utf8mb4`, collation `utf8mb4_nopad_bin`, engine InnoDB (`ARCHITECTURE.md` §1). Types are MariaDB 10.4. "Default —" means there is no DEFAULT clause, and the adapter writes the value (`ARCHITECTURE.md` §2).

## 1. Team 2: Auth

### 1.1 `users` (C)

Source: `app/Auth/Models/User.php`, `MockUserTransaction.php`, `UserService.php`. Current store: `$runtime/auth/users.json` → `users[]`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK, CHECK ≥ 1 | `User.php:22` (`id < 1` invalid); `MockUserTransaction.php:41` | C |
| `email` | VARCHAR(254) | no | — | UNIQUE `uq_users_email`; CHECK `email = LOWER(email)` and length 3–254 | `User.php:21-23`; `UserService.php:279-280`; B-06 | C |
| `status` | VARCHAR(8) | no | — | CHECK IN (`active`,`inactive`) | `User.php:24` | C |
| `password_hash` | VARCHAR(255) | no | — | CHECK length ≥ 20. The algorithm check stays in the model (`password_get_info`) | `User.php:27`; `AuthService::hashPassword` | C |
| `auth_version` | INT UNSIGNED | no | — | CHECK ≥ 1 | `User.php:22`; bumped in `MockUserTransaction.php:62-65`; B-09 | C |

- **No timestamps on users:** the model has none, so none are added.
- **Never exposed:** `password_hash` is never selected for `toPublicArray`. It is read only to construct `User`.

### 1.2 `user_roles` (C)

Source: `User.php:25-34` (non-empty list, unique, known values), `AuthorizationService::ROLES`, `UserService.php:286-301` (stored sorted).

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `user_id` | INT UNSIGNED | no | — | PK part 1; FK → `users.id` RESTRICT | `User.php` `roles` | C |
| `role` | VARCHAR(9) | no | — | PK part 2; CHECK IN (`student`,`faculty`,`registrar`,`admin`); index `ix_user_roles_role (role, user_id)` | `AuthorizationService.php:13` | C |

- **Read order:** `ORDER BY role` (byte order), which equals PHP `sort()` on these values. That is how the service stores them.
- **"At least one role":** the DB can't express it, so the app enforces it: `User::fromArray` rejects `[]`, and the adapter writes roles in the same transaction as the user.

### 1.3 `audit_logs` (C)

Source: `MockUserTransaction::appendAudit` (`:71-80`), `UserTransactionInterface::AUDIT_KEYS`, `UserService.php`. Current store: `users.json` → `audit[]`, in the same file and lock as the users.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | — | PK; from `repository_locks('auth').last_seq + 1`; CHECK ≥ 1 | `MockUserTransaction.php:78` (`count + 1`) | C |
| `at` | DATETIME | no | — | UTC | `UserService.php:323` | C |
| `actor_id` | INT UNSIGNED | no | — | FK → `users.id` RESTRICT; CHECK ≥ 1 | `UserService.php:103, 223` | C |
| `target_id` | INT UNSIGNED | no | — | **no FK** (0 = "account that would have been created"); CHECK ≥ 0 | `UserService.php:64` | C |
| `action` | VARCHAR(64) | no | — | CHECK length ≥ 1. **No value list**: the mock accepts any action string, so a list would be stricter than the contract. Current values: `user.created`, `user.updated`, `user.activated`, `user.deactivated`, `user.password_reset` | `UserService.php:67-85` | C |
| `outcome` | VARCHAR(7) | no | — | CHECK IN (`success`,`denied`) | `MockUserTransaction.php:73` | C |
| `changes` | LONGTEXT | no | — | CHECK `JSON_VALID(changes)`. Top-level keys ⊆ `AUDIT_KEYS`, and no secret keys at any depth, both enforced by the app before the insert | `UserTransactionInterface.php:31`; `MockUserTransaction.php:14, 88-98` | C |

`auditEntries()` returns `{id, at, actor_id, target_id, action, outcome, changes}` in that key order, `ORDER BY id`, with `changes` JSON-decoded to an array.

## 2. Team 3: Students

### 2.1 `programs` (C)

Source: `app/Students/Models/Program.php`, `JsonProgramCatalog.php`. Current store: `$runtime/students/programs.json`. Read-only in the app.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `code` | VARCHAR(20) | no | — | PK; CHECK REGEXP `^[A-Z0-9][A-Z0-9-]{0,19}\z` | `Program.php:16` | C |
| `name` | VARCHAR(200) | no | — | CHECK `name <> ''` and **`OCTET_LENGTH(name) <= 200`** (the model limits **bytes**: `strlen`) | `Program.php:16` | C |
| `max_year_level` | TINYINT UNSIGNED | no | — | CHECK BETWEEN 1 AND 8 | `Program.php:17` | C |

### 2.2 `students` (C)

Source: `app/Students/Models/Student.php`, `StudentService.php`, `StudentNumberPolicy.php`, `MockStudentRepository.php`. Current store: `$runtime/students/students.json` → `students[]`. Contract: `API_CONTRACT_T10_STUDENTS.md`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK | `Student.php:23`; `MockStudentRepository.php:84` | C |
| `user_id` | INT UNSIGNED | no | — | **UNIQUE**; FK → `users.id` RESTRICT | `StudentRepositoryInterface.php:21-22`; T-10 criterion 1 | C |
| `student_number` | VARCHAR(32) | no | — | **UNIQUE**; CHECK REGEXP `^[A-Z0-9/-]{1,32}\z` (the hard limit; the configurable pattern stays in `StudentNumberPolicy`) | `StudentNumberPolicy.php:37` | C |
| `first_name` | VARCHAR(100) | no | — | CHECK `<> ''` | `StudentService.php:167` | C |
| `middle_name` | VARCHAR(100) | yes | — | CHECK NULL or `<> ''` | `StudentService.php:161-167` | C |
| `last_name` | VARCHAR(100) | no | — | CHECK `<> ''` | `StudentService.php:167` | C |
| `program_code` | VARCHAR(20) | no | — | FK → `programs.code` RESTRICT. The year-level ≤ program maximum check stays in the app (`$check` inside the lock) | `StudentService.php:143-148, 191-199` | C |
| `year_level` | TINYINT UNSIGNED | no | — | CHECK BETWEEN 1 AND 8 | `Student.php:27`; `MockStudentRepository.php:150` | C |
| `academic_status` | VARCHAR(11) | no | — | CHECK IN `Student::STATUSES` | `Student.php:11` | C |
| `admission_year` | SMALLINT UNSIGNED | yes | — | CHECK NULL or ≥ 1900. The upper bound (`now + 1`) stays in the app, because CHECK can't use the clock | `StudentService.php:179-184` | C |
| `created_at` | DATETIME | no | — | UTC | `MockStudentRepository.php:89` | C |
| `updated_at` | DATETIME | no | — | UTC | same | C |

The adapter returns the columns in `Student::FIELDS` order (`Student.php:12-13`). The model requires exact key order.

### 2.3 `student_academic_history` (C)

Source: `MockStudentRepository::appendHistory`, `historyMap` (`:125-177`), `StudentRepositoryInterface::history`. Current store: `students.json` → `history{student_id: [entries]}`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `student_id` | INT UNSIGNED | no | — | PK part 1; FK → `students.id` RESTRICT | `:127` | C |
| `seq` | INT UNSIGNED | no | — | PK part 2; CHECK ≥ 1; gap-free per student (`ARCHITECTURE.md` §7) | `:135, 168` | C |
| `at` | DATETIME | no | — | UTC | `:169` | C |
| `field` | VARCHAR(15) | no | — | CHECK IN (`program_code`,`year_level`,`academic_status`) | `:17` | C |
| `from_value` | VARCHAR(20) | yes | — | NULL only on creation entries. Per-field CHECK (see §7) | `:146-153` | C |
| `to_value` | VARCHAR(20) | no | — | Per-field CHECK. The write path never produces NULL | `:130-136` | C |
| `actor_id` | INT UNSIGNED | no | — | CHECK ≥ 0. **No FK** (0 = system/seeder) | `StudentRepositoryInterface.php:28` | C |

The adapter returns `{seq, at, field, from, to, actor_id}` and converts `year_level` values to `int`.

## 3. Team 4: Enrollment catalog

### 3.1 `terms` (C)

Source: `app/Enrollment/Models/Term.php`, `TermService.php`, `MockCatalogRepository.php:40-89`. Current store: `$runtime/enrollment/enrollment.json` → `terms[]`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK | `Term.php:20` | C |
| `code` | VARCHAR(20) | no | — | **UNIQUE**; CHECK REGEXP `^[A-Z0-9][A-Z0-9-]{1,19}\z`; immutable (T-20 P4) | `Term.php:21`; `MockCatalogRepository.php:45` | C |
| `name` | VARCHAR(100) | no | — | CHECK `<> ''` | `Term.php:32-37` | C |
| `start_date` | DATE | no | — | CHECK `start_date < end_date` | `Term.php:23-24` | C |
| `end_date` | DATE | no | — | (same CHECK) | same | C |
| `is_current` | TINYINT(1) | no | — | CHECK IN (0,1) | `Term.php:25` | C |
| `enrollment_open` | TINYINT(1) | no | — | CHECK IN (0,1); read by T-22 (B-08) | `Term.php:25`; T-20 §3.1 | C |
| `current_marker` | TINYINT, `AS (IF(is_current = 1, 1, NULL)) PERSISTENT` | yes | generated | **UNIQUE**, so at most one current term (T-20 P13). The adapter never writes it | `MockCatalogRepository.php:366` | C |
| `created_at` | DATETIME | no | — | UTC | | C |
| `updated_at` | DATETIME | no | — | UTC | | C |

### 3.2 `sections` (C)

Source: `app/Enrollment/Models/Section.php`, `SectionService.php`, `MockCatalogRepository.php:121-180`. Current store: `enrollment.json` → `sections[]`. `remaining` is computed, never stored (T-20 §3.2). `meetings` lives in §3.3, and `instructors` in §3.4.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK | `Section.php:24` | C |
| `term_id` | INT UNSIGNED | no | — | FK → `terms.id` RESTRICT; part of the natural UNIQUE key; immutable (P5) | `Section.php:24`; `MockCatalogRepository.php:126` | C |
| `course_code` | VARCHAR(20) | no | — | CHECK REGEXP `^[A-Z0-9][A-Z0-9-]{1,19}\z`. **No FK** (D1 open: there is no course catalog) | `Section.php:25`; T-20 §3.2 D1 note | C |
| `course_title` | VARCHAR(150) | no | — | CHECK `<> ''` | `Section.php:26` | C |
| `units` | TINYINT UNSIGNED | no | — | CHECK BETWEEN 1 AND 12 | `Section.php:27` | C |
| `section_code` | VARCHAR(10) | no | — | CHECK REGEXP `^[A-Z0-9][A-Z0-9-]{0,9}\z` | `Section.php:28` | C |
| `capacity` | SMALLINT UNSIGNED | no | — | CHECK BETWEEN 1 AND 1000 | `Section.php:29` | C |
| `approved_count` | SMALLINT UNSIGNED | no | — | CHECK `approved_count <= capacity`. 0 on create. Only T-22 changes it, under the `enrollment` lock | `Section.php:30`; T-20 §3.2 | C |
| `delivery_mode` | VARCHAR(12) | no | — | CHECK IN (`scheduled`,`tba`,`online_async`) | `Section.php:13, 31` | C |
| `created_at` | DATETIME | no | — | UTC | | C |
| `updated_at` | DATETIME | no | — | UTC | | C |

`UNIQUE uq_sections_term_course_section (term_id, course_code, section_code)` is the natural key (`MockCatalogRepository.php:130-133`). It also serves the list order `term_id, course_code, section_code, id` and the `term_id` filter.

### 3.3 `section_meetings` (C)

Source: `Section::validMeetings` (`Section.php:46-66`), `SectionService::meetings` (`:256-314`). Current store: embedded `sections[].meetings[]`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `section_id` | INT UNSIGNED | no | — | PK part 1; FK → `sections.id` RESTRICT | | C |
| `position` | TINYINT UNSIGNED | no | — | PK part 2; 0-based list index; CHECK ≤ 13 (with the PK, this gives **at most 14 meetings**) | `Section.php:48` | C |
| `day` | CHAR(3) | no | — | CHECK IN (`MON`,…,`SUN`) | `Section.php:14, 53` | C |
| `start_time` | CHAR(5) | no | — | CHECK REGEXP for `HH:MM` 00:00–23:59 (exact text in §7); CHECK `start_time < end_time` | `Section.php:54-56` | C |
| `end_time` | CHAR(5) | no | — | the same REGEXP | same | C |
| `room` | VARCHAR(30) | no | — | CHECK `<> ''` | `Section.php:56` | C |

**Order:** `ORDER BY position` returns the list exactly as stored. The service sorts by day and then start before writing (`SectionService.php:311-312`), and the adapter keeps whatever order it is given.

**Enforced in the app, not the DB:**
- No overlap between a section's own meetings (`Section.php:59-64`).
- A `scheduled` section has ≥ 1 meeting (`Section.php:32`; T-20 P9).
- No instructor conflicts across sections (§3.4).

The reason: these rules span rows. Expressing them in the DB needs triggers, which this design excludes (`CONSTRAINTS_AND_MIGRATIONS.md` §1.4). The rules run inside the `enrollment` lock, and `database/verify.php` checks them across the whole table (§4 of that file).

### 3.4 `section_instructors` (C)

Source: `MockCatalogRepository::assignInstructor`, `unassignInstructor`, `assignedFacultyIds`, `sectionIdsForFaculty` (`:182-265`); T-21 plan A10/A11 and the T-40 notes. Current store: `enrollment.json` → `assignments[{section_id, faculty_id}]`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `section_id` | INT UNSIGNED | no | — | PK part 1; FK → `sections.id` RESTRICT | `:387-390` | C |
| `faculty_id` | INT UNSIGNED | no | — | PK part 2; FK → `faculty.id` RESTRICT (cutover rule: plan §3 D-08); index `ix_section_instructors_faculty (faculty_id, section_id)` for `sectionIdsForFaculty` | `:194-205`; T-30 F1 | C (FK target CP) |
| `assigned_seq` | BIGINT UNSIGNED | no | — | **UNIQUE**; FK → `section_instructor_history.seq` RESTRICT. Gives the assignment order | `CatalogRepositoryInterface.php:32` ("ids in assignment order") | C |

**Enforced in the app:** at most 5 per section (`:222`) and no same-term meeting overlap for one instructor (`:295-322`). The DB can't express either per row.

### 3.5 `section_instructor_history` (C)

Source: `MockCatalogRepository::appendHistory` (`:273-277`) and the snapshot replay (`:406-432`). Current store: `enrollment.json` → `assignment_history[]`.

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `seq` | BIGINT UNSIGNED | no | — | PK; global and gap-free from `repository_locks('enrollment')`; CHECK ≥ 1 | T-21 A10; `:409` | C |
| `at` | DATETIME | no | — | UTC | `:410` | C |
| `action` | VARCHAR(10) | no | — | CHECK IN (`assigned`,`unassigned`) | `:411` | C |
| `section_id` | INT UNSIGNED | no | — | FK → `sections.id` RESTRICT; index `(section_id, faculty_id, seq)` for the replay check | `:412` | C |
| `faculty_id` | INT UNSIGNED | no | — | FK → `faculty.id` RESTRICT | `:413` | C (FK target CP) |
| `actor_id` | INT UNSIGNED | no | — | FK → `users.id` RESTRICT; CHECK ≥ 1 | `:414` | C |

## 4. Team 5: Faculty (CP: approved plan T-30, not implemented)

Source: `docs/plans/T-30-faculty-profiles.md` §2 (F1–F5), §3.1, §3.3, §5, and its T-40 note. Planned store: `$runtime/faculty/faculty.json`.

### 4.1 `faculty` (CP)

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | no | — | PK. **Is the `faculty_id`** used by T-21; never reused | F1 | CP |
| `user_id` | INT UNSIGNED | no | — | **UNIQUE**; FK → `users.id` RESTRICT; immutable | F2 | CP |
| `employee_number` | VARCHAR(32) | no | — | **UNIQUE**; CHECK REGEXP `^[A-Z0-9/-]{1,32}\z` (hard limit; the pattern stays in `EmployeeNumberPolicy`); immutable | F3, §1.1-1 | CP |
| `first_name` | VARCHAR(70) | no | — | CHECK `<> ''` | F5 | CP |
| `last_name` | VARCHAR(70) | no | — | CHECK `<> ''` | F5 | CP |
| `department` | VARCHAR(100) | no | — | CHECK `<> ''` | §3.3, Q2 | CP |
| `status` | VARCHAR(8) | no | — | CHECK IN (`active`,`inactive`) | §3.1 | CP |
| `created_at` | DATETIME | no | — | UTC | §3.1 | CP |
| `updated_at` | DATETIME | no | — | UTC | §3.1 | CP |

### 4.2 `faculty_history` (CP)

| Column | Type | Null | Default | Constraints | Source | St. |
|---|---|---|---|---|---|---|
| `seq` | BIGINT UNSIGNED | no | — | PK; global and gap-free from `repository_locks('faculty')` | §5.2, §1.1-6 | CP |
| `at` | DATETIME | no | — | UTC | §5.2 | CP |
| `faculty_id` | INT UNSIGNED | no | — | FK → `faculty.id` RESTRICT; index `(faculty_id, field, seq)` | §5.2 | CP |
| `field` | VARCHAR(10) | no | — | CHECK IN (`status`,`department`) | §5.2 | CP |
| `from_value` | VARCHAR(100) | yes | — | NULL only on the first entry per `(faculty_id, field)` (verifier); per-field CHECK | §5.2 | CP |
| `to_value` | VARCHAR(100) | no | — | per-field CHECK (`status` values) | §5.2 | CP |
| `actor_id` | INT UNSIGNED | no | — | FK → `users.id` RESTRICT; CHECK ≥ 1 | §5.2 | CP |

## 5. Infrastructure (I)

### 5.1 `repository_locks`

| Column | Type | Null | Default | Constraints | Purpose |
|---|---|---|---|---|---|
| `name` | VARCHAR(32) | no | — | PK | One row per former JSON store: `auth`, `students`, `enrollment`, `faculty` (each inserted by its module's migration) |
| `last_seq` | BIGINT UNSIGNED | no | — | — | The last gap-free sequence value issued for that store's global stream (`ARCHITECTURE.md` §6–7). `students` keeps 0 (per-student seq) |

### 5.2 `schema_migrations`

Created by the migration runner itself, before the first migration. Never by the app.

| Column | Type | Null | Constraints | Purpose |
|---|---|---|---|---|
| `version` | INT UNSIGNED | no | PK | `NNNN` from `VNNNN__name.sql` |
| `name` | VARCHAR(100) | no | — | File name without the version |
| `checksum` | CHAR(64) | no | CHECK REGEXP `^[0-9a-f]{64}\z` | SHA-256 of the file bytes |
| `state` | VARCHAR(7) | no | CHECK IN (`started`,`applied`) | `started` remaining = an interrupted run (manual recovery) |
| `statements_applied` | INT UNSIGNED | no | — | How far an interrupted migration got |
| `started_at` | DATETIME | no | — | UTC |
| `finished_at` | DATETIME | yes | — | UTC |
| `applied_by` | VARCHAR(80) | no | — | `CURRENT_USER()` at apply time |

## 6. Provisional, not for creation (P)

These are placeholders, so that the confirmed tables leave room for them. **The recommendation is to defer all DDL for them** until T-22, T-32 and D1 have approved plans (OQ-11). The open questions are in `BLOCKERS.md` §1. No business rule is invented here.

### 6.1 `enrollments` (P, T-22)

API shape (`API_CONTRACTS.md` Team 4): `{id, student_id, section_id, term_id, status, requested_at, decided_at?, decided_by?, reason?}`.

| Column | Sketch | Notes |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `student_id` | INT UNSIGNED, FK → `students.id` RESTRICT | Resolved from the session via `StudentDirectory::findByUserId` (T-22 criterion 1) |
| `section_id` | INT UNSIGNED, FK → `sections.id` RESTRICT | |
| `term_id` | **not stored**. Derived by a join to `sections` | Avoids a denormalized copy that could disagree |
| `status` | VARCHAR(8), CHECK IN (`pending`,`approved`,`rejected`) | Cancel/drop is not in the MVP (B-08), so no further values |
| `requested_at`, `decided_at` | DATETIME; `decided_at` NULL until decided | |
| `decided_by` | INT UNSIGNED NULL, FK → `users.id` | |
| `reason` | VARCHAR(n) NULL | Length: T-22 decides |
| `active_section_id` | `AS (IF(status IN ('pending','approved'), section_id, NULL)) PERSISTENT`, with UNIQUE `(student_id, active_section_id)` | Rule 3: no second pending/approved row for the same student and section |

- **Locking and history:** under the `enrollment` lock, so `approved_count` changes atomically (T-20 P1). Whether there is an `enrollment_history`, T-22 decides.
- **Open questions:** which `academic_status` values may enroll (conflict C-03 in the plan), B-08 eligibility, reason length, and decision history.

### 6.2 `grades`, `grade_history` (P, T-32)

API shape: `{id, section_id, student_id, value, remarks?, status, updated_at}`.

- **Sketch:** `grades(id PK, section_id FK, student_id FK, value VARCHAR(8) NULL, remarks VARCHAR(n) NULL, status CHECK IN ('draft','submitted','finalized'), updated_at)` with UNIQUE `(section_id, student_id)`. History is append-only, in the T-21 pattern.
- **Deliberately not specified:**
  - The **value domain** (B-05 OPEN): there is no CHECK on `value`.
  - Whether a grade must reference an approved enrollment.
  - Whether finalized grades can be corrected.
  - Separation of duties (T-32, owner 2026-11-20). This is app logic and can't be a constraint.
- **Locking:** a new `grades` row in `repository_locks`.

### 6.3 `courses` (P, D1)

Undecided owner. Today `sections.course_code`/`course_title`/`units` are format-checked free values (T-20 §3.2). When D1 is decided, an additive migration adds `courses(code PK, …)` and an FK from `sections.course_code`. That needs data reconciliation first (T-20 §3.2 D1 note). **There is no table now.**

## 7. Proposed DDL (text only — PROPOSED, do not execute)

Notes for whoever implements Phase 5 (Codex):

- **One statement per `;`-terminated line ending** (`CONSTRAINTS_AND_MIGRATIONS.md` §3.2).
- **Escaping:** `\\z` in a SQL literal is the PCRE end-of-subject anchor. It is used instead of `$`, which also matches before a final newline. The session must not set `NO_BACKSLASH_ESCAPES`.
- **Prerequisites:** `sql_mode` must include `STRICT_ALL_TABLES` (OQ-6). Every CHECK must be proven by a negative test in Phase 7.

```sql
-- PROPOSED — not approved. Text only. Do not execute.

-- V0001__infrastructure.sql
CREATE TABLE repository_locks (
  name     VARCHAR(32)     NOT NULL,
  last_seq BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

-- V0002__auth.sql
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(254) NOT NULL,
  status        VARCHAR(8)   NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  auth_version  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT ck_users_id CHECK (id >= 1),
  CONSTRAINT ck_users_email CHECK (email = LOWER(email) AND CHAR_LENGTH(email) BETWEEN 3 AND 254),
  CONSTRAINT ck_users_status CHECK (status IN ('active','inactive')),
  CONSTRAINT ck_users_password_hash CHECK (CHAR_LENGTH(password_hash) >= 20),
  CONSTRAINT ck_users_auth_version CHECK (auth_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role    VARCHAR(9)   NOT NULL,
  PRIMARY KEY (user_id, role),
  KEY ix_user_roles_role (role, user_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_user_roles_role CHECK (role IN ('student','faculty','registrar','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE audit_logs (
  id        BIGINT UNSIGNED NOT NULL,
  at        DATETIME        NOT NULL,
  actor_id  INT UNSIGNED    NOT NULL,
  target_id INT UNSIGNED    NOT NULL,
  action    VARCHAR(64)     NOT NULL,
  outcome   VARCHAR(7)      NOT NULL,
  changes   LONGTEXT        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_logs_actor (actor_id),
  CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_audit_logs_id CHECK (id >= 1),
  CONSTRAINT ck_audit_logs_actor CHECK (actor_id >= 1),
  CONSTRAINT ck_audit_logs_action CHECK (CHAR_LENGTH(action) >= 1),
  CONSTRAINT ck_audit_logs_outcome CHECK (outcome IN ('success','denied')),
  CONSTRAINT ck_audit_logs_changes CHECK (JSON_VALID(changes))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

INSERT INTO repository_locks (name, last_seq) VALUES ('auth', 0);

-- V0003__students.sql
CREATE TABLE programs (
  code           VARCHAR(20)      NOT NULL,
  name           VARCHAR(200)     NOT NULL,
  max_year_level TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (code),
  CONSTRAINT ck_programs_code CHECK (code REGEXP '^[A-Z0-9][A-Z0-9-]{0,19}\\z'),
  CONSTRAINT ck_programs_name CHECK (name <> '' AND OCTET_LENGTH(name) <= 200),
  CONSTRAINT ck_programs_max_year CHECK (max_year_level BETWEEN 1 AND 8)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE students (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED      NOT NULL,
  student_number  VARCHAR(32)       NOT NULL,
  first_name      VARCHAR(100)      NOT NULL,
  middle_name     VARCHAR(100)      NULL,
  last_name       VARCHAR(100)      NOT NULL,
  program_code    VARCHAR(20)       NOT NULL,
  year_level      TINYINT UNSIGNED  NOT NULL,
  academic_status VARCHAR(11)       NOT NULL,
  admission_year  SMALLINT UNSIGNED NULL,
  created_at      DATETIME          NOT NULL,
  updated_at      DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_user (user_id),
  UNIQUE KEY uq_students_number (student_number),
  KEY ix_students_program (program_code),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_students_program FOREIGN KEY (program_code) REFERENCES programs (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_students_number CHECK (student_number REGEXP '^[A-Z0-9/-]{1,32}\\z'),
  CONSTRAINT ck_students_names CHECK (first_name <> '' AND last_name <> '' AND (middle_name IS NULL OR middle_name <> '')),
  CONSTRAINT ck_students_year CHECK (year_level BETWEEN 1 AND 8),
  CONSTRAINT ck_students_status CHECK (academic_status IN ('active','inactive','graduated','transferred','on_leave')),
  CONSTRAINT ck_students_admission CHECK (admission_year IS NULL OR admission_year >= 1900)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE student_academic_history (
  student_id INT UNSIGNED NOT NULL,
  seq        INT UNSIGNED NOT NULL,
  at         DATETIME     NOT NULL,
  field      VARCHAR(15)  NOT NULL,
  from_value VARCHAR(20)  NULL,
  to_value   VARCHAR(20)  NOT NULL,
  actor_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (student_id, seq),
  CONSTRAINT fk_sah_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_sah_seq CHECK (seq >= 1),
  CONSTRAINT ck_sah_field CHECK (field IN ('program_code','year_level','academic_status')),
  CONSTRAINT ck_sah_program CHECK (field <> 'program_code' OR (to_value REGEXP '^[A-Z0-9][A-Z0-9-]{0,19}\\z' AND (from_value IS NULL OR from_value REGEXP '^[A-Z0-9][A-Z0-9-]{0,19}\\z'))),
  CONSTRAINT ck_sah_year CHECK (field <> 'year_level' OR (to_value REGEXP '^[1-8]\\z' AND (from_value IS NULL OR from_value REGEXP '^[1-8]\\z'))),
  CONSTRAINT ck_sah_status CHECK (field <> 'academic_status' OR (to_value IN ('active','inactive','graduated','transferred','on_leave') AND (from_value IS NULL OR from_value IN ('active','inactive','graduated','transferred','on_leave'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

INSERT INTO repository_locks (name, last_seq) VALUES ('students', 0);

-- V0004__catalog.sql
CREATE TABLE terms (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(100) NOT NULL,
  start_date      DATE         NOT NULL,
  end_date        DATE         NOT NULL,
  is_current      TINYINT(1)   NOT NULL,
  enrollment_open TINYINT(1)   NOT NULL,
  current_marker  TINYINT AS (IF(is_current = 1, 1, NULL)) PERSISTENT,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_terms_code (code),
  UNIQUE KEY uq_terms_current (current_marker),
  CONSTRAINT ck_terms_code CHECK (code REGEXP '^[A-Z0-9][A-Z0-9-]{1,19}\\z'),
  CONSTRAINT ck_terms_name CHECK (name <> ''),
  CONSTRAINT ck_terms_dates CHECK (start_date < end_date),
  CONSTRAINT ck_terms_flags CHECK (is_current IN (0,1) AND enrollment_open IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE sections (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  term_id        INT UNSIGNED      NOT NULL,
  course_code    VARCHAR(20)       NOT NULL,
  course_title   VARCHAR(150)      NOT NULL,
  units          TINYINT UNSIGNED  NOT NULL,
  section_code   VARCHAR(10)       NOT NULL,
  capacity       SMALLINT UNSIGNED NOT NULL,
  approved_count SMALLINT UNSIGNED NOT NULL,
  delivery_mode  VARCHAR(12)       NOT NULL,
  created_at     DATETIME          NOT NULL,
  updated_at     DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sections_term_course_section (term_id, course_code, section_code),
  CONSTRAINT fk_sections_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_sections_course CHECK (course_code REGEXP '^[A-Z0-9][A-Z0-9-]{1,19}\\z'),
  CONSTRAINT ck_sections_section CHECK (section_code REGEXP '^[A-Z0-9][A-Z0-9-]{0,9}\\z'),
  CONSTRAINT ck_sections_title CHECK (course_title <> ''),
  CONSTRAINT ck_sections_units CHECK (units BETWEEN 1 AND 12),
  CONSTRAINT ck_sections_capacity CHECK (capacity BETWEEN 1 AND 1000 AND approved_count <= capacity),
  CONSTRAINT ck_sections_mode CHECK (delivery_mode IN ('scheduled','tba','online_async'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE section_meetings (
  section_id INT UNSIGNED     NOT NULL,
  position   TINYINT UNSIGNED NOT NULL,
  day        CHAR(3)          NOT NULL,
  start_time CHAR(5)          NOT NULL,
  end_time   CHAR(5)          NOT NULL,
  room       VARCHAR(30)      NOT NULL,
  PRIMARY KEY (section_id, position),
  CONSTRAINT fk_section_meetings_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_section_meetings_position CHECK (position <= 13),
  CONSTRAINT ck_section_meetings_day CHECK (day IN ('MON','TUE','WED','THU','FRI','SAT','SUN')),
  CONSTRAINT ck_section_meetings_times CHECK (start_time REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]\\z' AND end_time REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]\\z' AND start_time < end_time),
  CONSTRAINT ck_section_meetings_room CHECK (room <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

INSERT INTO repository_locks (name, last_seq) VALUES ('enrollment', 0);

-- V0005__faculty.sql   (CP: apply to a non-test database only after T-30 is merged)
CREATE TABLE faculty (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  employee_number VARCHAR(32)  NOT NULL,
  first_name      VARCHAR(70)  NOT NULL,
  last_name       VARCHAR(70)  NOT NULL,
  department      VARCHAR(100) NOT NULL,
  status          VARCHAR(8)   NOT NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_faculty_user (user_id),
  UNIQUE KEY uq_faculty_employee_number (employee_number),
  CONSTRAINT fk_faculty_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_faculty_employee_number CHECK (employee_number REGEXP '^[A-Z0-9/-]{1,32}\\z'),
  CONSTRAINT ck_faculty_names CHECK (first_name <> '' AND last_name <> '' AND department <> ''),
  CONSTRAINT ck_faculty_status CHECK (status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE faculty_history (
  seq        BIGINT UNSIGNED NOT NULL,
  at         DATETIME        NOT NULL,
  faculty_id INT UNSIGNED    NOT NULL,
  field      VARCHAR(10)     NOT NULL,
  from_value VARCHAR(100)    NULL,
  to_value   VARCHAR(100)    NOT NULL,
  actor_id   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (seq),
  KEY ix_faculty_history_replay (faculty_id, field, seq),
  KEY ix_faculty_history_actor (actor_id),
  CONSTRAINT fk_faculty_history_faculty FOREIGN KEY (faculty_id) REFERENCES faculty (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_faculty_history_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_faculty_history_seq CHECK (seq >= 1 AND actor_id >= 1),
  CONSTRAINT ck_faculty_history_field CHECK (field IN ('status','department')),
  CONSTRAINT ck_faculty_history_status CHECK (field <> 'status' OR (to_value IN ('active','inactive') AND (from_value IS NULL OR from_value IN ('active','inactive')))),
  CONSTRAINT ck_faculty_history_values CHECK (to_value <> '' AND (from_value IS NULL OR from_value <> ''))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

INSERT INTO repository_locks (name, last_seq) VALUES ('faculty', 0);

-- V0006__section_instructors.sql   (needs V0004 and V0005)
CREATE TABLE section_instructor_history (
  seq        BIGINT UNSIGNED NOT NULL,
  at         DATETIME        NOT NULL,
  action     VARCHAR(10)     NOT NULL,
  section_id INT UNSIGNED    NOT NULL,
  faculty_id INT UNSIGNED    NOT NULL,
  actor_id   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (seq),
  KEY ix_sih_pair (section_id, faculty_id, seq),
  KEY ix_sih_faculty (faculty_id),
  KEY ix_sih_actor (actor_id),
  CONSTRAINT fk_sih_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_sih_faculty FOREIGN KEY (faculty_id) REFERENCES faculty (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_sih_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_sih_seq CHECK (seq >= 1 AND actor_id >= 1),
  CONSTRAINT ck_sih_action CHECK (action IN ('assigned','unassigned'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE section_instructors (
  section_id   INT UNSIGNED    NOT NULL,
  faculty_id   INT UNSIGNED    NOT NULL,
  assigned_seq BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (section_id, faculty_id),
  UNIQUE KEY uq_section_instructors_seq (assigned_seq),
  KEY ix_section_instructors_faculty (faculty_id, section_id),
  CONSTRAINT fk_si_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_si_faculty FOREIGN KEY (faculty_id) REFERENCES faculty (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_si_history FOREIGN KEY (assigned_seq) REFERENCES section_instructor_history (seq) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;
```
