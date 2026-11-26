-- Purpose: Records the remarks before and after each valued grade-history entry (grade_history.old_remarks, new_remarks).
-- Dependencies: V0008.
-- Destructive: no. Adds two nullable columns and two CHECK constraints; existing rows keep NULL remarks (they are not rewritten).
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- Rows written before V0009 (entered/updated/corrected with both remarks NULL) cannot be told apart from post-V0009 rows without remarks, so no CHECK requires remarks on them.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- ALTER TABLE grade_history DROP CHECK ck_gh_remarks_unvalued;
-- ALTER TABLE grade_history DROP CHECK ck_gh_remarks_text;
-- ALTER TABLE grade_history DROP COLUMN new_remarks, DROP COLUMN old_remarks;
-- Then remove the started schema_migrations row only after recovery is verified.

ALTER TABLE grade_history ADD COLUMN old_remarks VARCHAR(255) NULL AFTER new_value, ADD COLUMN new_remarks VARCHAR(255) NULL AFTER old_remarks;

ALTER TABLE grade_history ADD CONSTRAINT ck_gh_remarks_text CHECK ((old_remarks IS NULL OR old_remarks <> '') AND (new_remarks IS NULL OR new_remarks <> ''));

ALTER TABLE grade_history ADD CONSTRAINT ck_gh_remarks_unvalued CHECK (action IN ('entered','updated','corrected') OR (old_remarks IS NULL AND new_remarks IS NULL));
