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
