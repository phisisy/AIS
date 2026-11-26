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
