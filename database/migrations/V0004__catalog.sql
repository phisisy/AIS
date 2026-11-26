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
