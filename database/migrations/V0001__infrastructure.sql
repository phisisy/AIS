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
