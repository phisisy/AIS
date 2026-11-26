-- Purpose: Student enrollment requests and their append-only per-enrollment history.
-- Dependencies: V0002, V0003, V0004.
-- Destructive: no. This migration only creates tables (no repository_locks row: the enrollment lock from V0004 is reused).
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- DROP TABLE enrollment_history;
-- DROP TABLE enrollments;
-- Then remove the started schema_migrations row only after recovery is verified.

CREATE TABLE enrollments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id        INT UNSIGNED NOT NULL,
  section_id        INT UNSIGNED NOT NULL,
  status            VARCHAR(8)   NOT NULL,
  requested_at      DATETIME     NOT NULL,
  decided_at        DATETIME     NULL,
  decided_by        INT UNSIGNED NULL,
  dropped_at        DATETIME     NULL,
  reason            VARCHAR(255) NULL,
  active_section_id INT UNSIGNED AS (IF(status IN ('pending','approved'), section_id, NULL)) PERSISTENT,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollments_active (student_id, active_section_id),
  KEY ix_enrollments_section_status (section_id, status),
  KEY ix_enrollments_status (status, id),
  KEY ix_enrollments_decided_by (decided_by),
  CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_enrollments_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_enrollments_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_enrollments_status CHECK (status IN ('pending','approved','rejected','dropped')),
  CONSTRAINT ck_enrollments_decision CHECK ((status = 'pending' AND decided_at IS NULL AND decided_by IS NULL AND dropped_at IS NULL) OR (status IN ('approved','rejected') AND decided_at IS NOT NULL AND decided_by IS NOT NULL AND dropped_at IS NULL) OR (status = 'dropped' AND dropped_at IS NOT NULL AND ((decided_at IS NULL AND decided_by IS NULL) OR (decided_at IS NOT NULL AND decided_by IS NOT NULL)))),
  CONSTRAINT ck_enrollments_order CHECK ((decided_at IS NULL OR decided_at >= requested_at) AND (dropped_at IS NULL OR dropped_at >= requested_at)),
  CONSTRAINT ck_enrollments_reason CHECK (reason IS NULL OR reason <> ''),
  CONSTRAINT ck_enrollments_reject_reason CHECK (status <> 'rejected' OR reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;

CREATE TABLE enrollment_history (
  enrollment_id INT UNSIGNED NOT NULL,
  seq           INT UNSIGNED NOT NULL,
  at            DATETIME     NOT NULL,
  action        VARCHAR(10)  NOT NULL,
  actor_id      INT UNSIGNED NOT NULL,
  reason        VARCHAR(255) NULL,
  PRIMARY KEY (enrollment_id, seq),
  KEY ix_eh_actor (actor_id),
  CONSTRAINT fk_eh_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_eh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_eh_seq CHECK (seq >= 1 AND actor_id >= 1),
  CONSTRAINT ck_eh_action CHECK (action IN ('requested','approved','rejected','dropped')),
  CONSTRAINT ck_eh_reason CHECK (reason IS NULL OR reason <> ''),
  CONSTRAINT ck_eh_reject_reason CHECK (action <> 'rejected' OR reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;
