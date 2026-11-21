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
