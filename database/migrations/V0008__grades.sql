-- Purpose: Grades (one per section and student) and their append-only grade history; adds the 'grades' repository lock.
-- Dependencies: V0001, V0002, V0003, V0004, V0007 (V0007 for ordering only; there is no FK to enrollments).
-- Destructive: no. This migration creates tables and inserts only a repository_locks seed row.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- The grade value domain is NOT constrained here (B-05 scale unconfirmed): only length 1..8. A later additive migration adds the scale CHECK.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DROP TABLE grade_history;
-- DROP TABLE grades;
-- DELETE FROM repository_locks WHERE name = 'grades';
-- Then remove the started schema_migrations row only after recovery is verified.

CREATE TABLE grades (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  value      VARCHAR(8)   NOT NULL,
  remarks    VARCHAR(255) NULL,
  status     VARCHAR(9)   NOT NULL,
  created_at DATETIME     NOT NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grades_section_student (section_id, student_id),
  KEY ix_grades_student_status (student_id, status),
  CONSTRAINT fk_grades_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_grades_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_grades_status CHECK (status IN ('draft','submitted','finalized')),
  CONSTRAINT ck_grades_value_len CHECK (CHAR_LENGTH(value) BETWEEN 1 AND 8),
  CONSTRAINT ck_grades_remarks CHECK (remarks IS NULL OR remarks <> ''),
  CONSTRAINT ck_grades_order CHECK (updated_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE grade_history (
  seq        BIGINT UNSIGNED NOT NULL,
  at         DATETIME        NOT NULL,
  action     VARCHAR(9)      NOT NULL,
  grade_id   INT UNSIGNED    NOT NULL,
  section_id INT UNSIGNED    NOT NULL,
  student_id INT UNSIGNED    NOT NULL,
  old_value  VARCHAR(8)      NULL,
  new_value  VARCHAR(8)      NULL,
  reason     VARCHAR(255)    NULL,
  actor_id   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (seq),
  KEY ix_gh_grade (grade_id, seq),
  KEY ix_gh_section_actor (section_id, actor_id),
  KEY ix_gh_student (student_id),
  KEY ix_gh_actor (actor_id),
  CONSTRAINT fk_gh_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_gh_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_gh_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_gh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_gh_seq CHECK (seq >= 1 AND actor_id >= 1),
  CONSTRAINT ck_gh_action CHECK (action IN ('entered','updated','submitted','returned','finalized','corrected')),
  CONSTRAINT ck_gh_reason CHECK ((action IN ('corrected','returned')) = (reason IS NOT NULL)),
  CONSTRAINT ck_gh_reason_text CHECK (reason IS NULL OR reason <> ''),
  CONSTRAINT ck_gh_value_len CHECK ((old_value IS NULL OR CHAR_LENGTH(old_value) BETWEEN 1 AND 8) AND (new_value IS NULL OR CHAR_LENGTH(new_value) BETWEEN 1 AND 8)),
  CONSTRAINT ck_gh_values CHECK ((action = 'entered' AND old_value IS NULL AND new_value IS NOT NULL) OR (action IN ('updated','corrected') AND old_value IS NOT NULL AND new_value IS NOT NULL) OR (action IN ('submitted','returned','finalized') AND old_value IS NULL AND new_value IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

INSERT INTO repository_locks (name, last_seq) VALUES ('grades', 0);
