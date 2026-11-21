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
