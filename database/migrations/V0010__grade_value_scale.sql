-- Purpose: Constrains the grade value domain to the demo-approved scale (owner decision 2026-11-26): 1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00, 5.00, INC, DRP. This is NOT the official university grading policy, which stays pending (GradeScale::official() remains empty).
-- Dependencies: V0008.
-- Destructive: no. Adds two CHECK constraints only; no grade rows can exist before this migration, and ck_grades_value_len stays.
-- Forward-only: DDL is non-transactional; interrupted migrations require manual recovery.
-- Comparison is exact (columns use utf8mb4_nopad_bin): '3.25', 'inc' and '5' are refused.
-- MANUAL RECOVERY (operator only, after backup and specific approval):
-- ALTER TABLE grade_history DROP CHECK ck_gh_value_scale;
-- ALTER TABLE grades DROP CHECK ck_grades_value;
-- Then remove the started schema_migrations row only after recovery is verified.

ALTER TABLE grades ADD CONSTRAINT ck_grades_value CHECK (value IN ('1.00','1.25','1.50','1.75','2.00','2.25','2.50','2.75','3.00','5.00','INC','DRP'));

ALTER TABLE grade_history ADD CONSTRAINT ck_gh_value_scale CHECK ((old_value IS NULL OR old_value IN ('1.00','1.25','1.50','1.75','2.00','2.25','2.50','2.75','3.00','5.00','INC','DRP')) AND (new_value IS NULL OR new_value IN ('1.00','1.25','1.50','1.75','2.00','2.25','2.50','2.75','3.00','5.00','INC','DRP')));
