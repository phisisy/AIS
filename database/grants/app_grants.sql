-- App-account grant template. Apply only after V0001-V0006 have completed.
-- Replace {{SCHEMA}} and {{APP_USER}} as identifiers, never with untrusted input.
-- Phase 5 substitutions are exactly: SCHEMA=ais_test, APP_USER=ais_test_app.
-- The account must already exist; this file never creates users or databases.
-- Applying grants to another schema requires separate owner authorization.

GRANT SELECT, INSERT, UPDATE ON `{{SCHEMA}}`.`users` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `{{SCHEMA}}`.`user_roles` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT ON `{{SCHEMA}}`.`audit_logs` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT ON `{{SCHEMA}}`.`programs` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `{{SCHEMA}}`.`students` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT ON `{{SCHEMA}}`.`student_academic_history` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `{{SCHEMA}}`.`terms` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `{{SCHEMA}}`.`sections` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `{{SCHEMA}}`.`section_meetings` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `{{SCHEMA}}`.`section_instructors` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT ON `{{SCHEMA}}`.`section_instructor_history` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `{{SCHEMA}}`.`faculty` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, INSERT ON `{{SCHEMA}}`.`faculty_history` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT, UPDATE ON `{{SCHEMA}}`.`repository_locks` TO '{{APP_USER}}'@'127.0.0.1';
GRANT SELECT ON `{{SCHEMA}}`.`schema_migrations` TO '{{APP_USER}}'@'127.0.0.1';
