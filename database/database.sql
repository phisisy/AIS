-- AIS_SYSTEM consolidated schema: CANDIDATE, NOT VERIFIED
-- Generated 2026-11-25 (source-only, on an untrusted host) from the committed migrations V0001-V0006 at aafc115.
-- Equivalence with migration-based install (database/migrate.php up) has NOT been tested. See docs/database/INSTALL.md.
-- Scope: test/demo database `ais_test` ONLY. V0005+ (faculty, CP) are refused for any other target by
-- MigrationRunner::assertFacultyTarget until T-30 is merged, so this file is hard-wired to ais_test.
-- Run as an administrative account that may create the database. Do NOT run against `ais` (no cutover is approved).
-- Not included: accounts and grants (database/grants/app_grants.sql, after accounts exist), demo data.
-- schema_migrations rows carry the SHA-256 of each migration file as committed (LF line endings).
-- Clone with `git -c core.autocrlf=false clone ...` so `migrate.php status` sees the same checksums.
-- Non-empty target: refused. CREATE DATABASE (no IF NOT EXISTS) fails with error 1007 if ais_test already exists,
-- before anything is written; the mariadb client stops at the first error (never use --force with this file).
-- If the import fails part-way, ais_test is partial: do not use it. Recovery = drop and re-create ais_test, which
-- is destructive and needs the owner's explicit approval (CLAUDE.md rule 3), then re-run this file.
-- Revision 2 (2026-11-25 review): fixed the tracking CHECK escape (\\z) and refused existing databases.

CREATE DATABASE ais_test CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin;
USE ais_test;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';

-- Migration tracking table: identical to MigrationRunner::ensureTrackingTable().
CREATE TABLE IF NOT EXISTS schema_migrations (version INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(100) NOT NULL, checksum CHAR(64) NOT NULL, state VARCHAR(7) NOT NULL, statements_applied INT UNSIGNED NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, applied_by VARCHAR(80) NOT NULL, CONSTRAINT ck_schema_migrations_checksum CHECK (checksum REGEXP '^[0-9a-f]{64}\\z'), CONSTRAINT ck_schema_migrations_state CHECK (state IN ('started','applied'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

-- ===== V0001__infrastructure (V0001, 1 statements) =====
-- Purpose: Repository lock rows and gap-free sequence counters.
-- Dependencies: none.
-- Destructive: no. This migration creates tables and inserts only repository_locks seed rows.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DROP TABLE repository_locks;
-- Then remove the started schema_migrations row only after recovery is verified.

CREATE TABLE repository_locks (
  name     VARCHAR(32)     NOT NULL,
  last_seq BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

-- ===== V0002__auth (V0002, 4 statements) =====
-- Purpose: Auth accounts, roles, audit history and auth lock row.
-- Dependencies: V0001.
-- Destructive: no. This migration creates tables and inserts only repository_locks seed rows.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DELETE FROM repository_locks WHERE name = 'auth';
-- DROP TABLE audit_logs;
-- DROP TABLE user_roles;
-- DROP TABLE users;
-- Then remove the started schema_migrations row only after recovery is verified.

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(254) NOT NULL,
  status        VARCHAR(8)   NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  auth_version  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  -- id >= 1 is guaranteed by INT UNSIGNED AUTO_INCREMENT; MariaDB 10.4 forbids CHECK on AUTO_INCREMENT columns (error 1901).
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

-- ===== V0003__students (V0003, 4 statements) =====
-- Purpose: Programs, student profiles, academic history and students lock row.
-- Dependencies: V0001, V0002.
-- Destructive: no. This migration creates tables and inserts only repository_locks seed rows.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DELETE FROM repository_locks WHERE name = 'students';
-- DROP TABLE student_academic_history;
-- DROP TABLE students;
-- DROP TABLE programs;
-- Then remove the started schema_migrations row only after recovery is verified.

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

-- ===== V0004__catalog (V0004, 4 statements) =====
-- Purpose: Terms, sections, meetings and enrollment lock row.
-- Dependencies: V0001.
-- Destructive: no. This migration creates tables and inserts only repository_locks seed rows.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DELETE FROM repository_locks WHERE name = 'enrollment';
-- DROP TABLE section_meetings;
-- DROP TABLE sections;
-- DROP TABLE terms;
-- Then remove the started schema_migrations row only after recovery is verified.

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

-- ===== V0005__faculty (V0005, 3 statements) =====
-- Purpose: Faculty profiles, history and faculty lock row.
-- Dependencies: V0001, V0002.
-- Destructive: no. This migration creates tables and inserts only repository_locks seed rows.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DELETE FROM repository_locks WHERE name = 'faculty';
-- DROP TABLE faculty_history;
-- DROP TABLE faculty;
-- Then remove the started schema_migrations row only after recovery is verified.
-- Faculty-dependent migration: apply only to ais_test until T-30 is merged.

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

-- ===== V0006__section_instructors (V0006, 2 statements) =====
-- Purpose: Instructor assignment history and current assignments.
-- Dependencies: V0002, V0004, V0005.
-- Destructive: no. This migration creates tables and inserts only repository_locks seed rows.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DROP TABLE section_instructors;
-- DROP TABLE section_instructor_history;
-- Then remove the started schema_migrations row only after recovery is verified.
-- Faculty-dependent migration: apply only to ais_test until T-30 is merged.

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

-- Record the six migrations as applied, exactly as MigrationRunner::up() would.
INSERT INTO schema_migrations (version, name, checksum, state, statements_applied, started_at, finished_at, applied_by) VALUES
(1, 'infrastructure', 'ccd8872467c18cebf1525421e981958bab0ce181cb91c2a6d5ef60665af4fed8', 'applied', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), CURRENT_USER()),
(2, 'auth', '0a546b90a046872e79d1137e25eadde3423e3c8f66c18976c36fdbb45069b3ce', 'applied', 4, UTC_TIMESTAMP(), UTC_TIMESTAMP(), CURRENT_USER()),
(3, 'students', 'a211d4939b57f2cceb94faca2b889cf0c619d8e32236a04d4b2eed508e908e2b', 'applied', 4, UTC_TIMESTAMP(), UTC_TIMESTAMP(), CURRENT_USER()),
(4, 'catalog', '0e52d01eb7d6b6ea466f0b3f9ed9abb45b241f52d2e80f2d6cd09a91bf2e3bb9', 'applied', 4, UTC_TIMESTAMP(), UTC_TIMESTAMP(), CURRENT_USER()),
(5, 'faculty', 'b1409adbe4768a26aa631a818b36b75b4d13973d568cda8f1ce9bbc48193e606', 'applied', 3, UTC_TIMESTAMP(), UTC_TIMESTAMP(), CURRENT_USER()),
(6, 'section_instructors', 'f47a159b20e75270c05a77ef22f9aec25f0234f40395a84475c25ecfb538c88a', 'applied', 2, UTC_TIMESTAMP(), UTC_TIMESTAMP(), CURRENT_USER());
