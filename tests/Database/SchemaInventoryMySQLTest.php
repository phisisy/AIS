<?php
declare(strict_types=1);

// DB-02, DB-06, DB-12 inventory. UNVERIFIED: written source-only, never executed. Online only (AIS_MYSQL_TESTS=1, ais_test).
// The expected sets below are written out from migrations V0001-V0010 (and MigrationRunner::ensureTrackingTable); they are
// deliberately NOT read from the database under test. The grant set is read from database/grants/app_grants.sql.

use Database\Tools\MigrationRunner;

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

/** table => [primary key columns] */
function inventoryPrimaryKeys(): array
{
    return [
        'repository_locks' => ['name'], 'users' => ['id'], 'user_roles' => ['user_id', 'role'], 'audit_logs' => ['id'],
        'programs' => ['code'], 'students' => ['id'], 'student_academic_history' => ['student_id', 'seq'],
        'terms' => ['id'], 'sections' => ['id'], 'section_meetings' => ['section_id', 'position'],
        'faculty' => ['id'], 'faculty_history' => ['seq'],
        'section_instructor_history' => ['seq'], 'section_instructors' => ['section_id', 'faculty_id'],
        'enrollments' => ['id'], 'enrollment_history' => ['enrollment_id', 'seq'],
        'grades' => ['id'], 'grade_history' => ['seq'], 'schema_migrations' => ['version'],
    ];
}

/** "table.index" => [unique(0|1), columns]; PRIMARY is checked separately. No implicit FK indexes exist: every FK column is leftmost in a declared key. */
function inventoryIndexes(): array
{
    return [
        'users.uq_users_email' => [1, ['email']],
        'user_roles.ix_user_roles_role' => [0, ['role', 'user_id']],
        'audit_logs.ix_audit_logs_actor' => [0, ['actor_id']],
        'students.uq_students_user' => [1, ['user_id']],
        'students.uq_students_number' => [1, ['student_number']],
        'students.ix_students_program' => [0, ['program_code']],
        'terms.uq_terms_code' => [1, ['code']],
        'terms.uq_terms_current' => [1, ['current_marker']],
        'sections.uq_sections_term_course_section' => [1, ['term_id', 'course_code', 'section_code']],
        'faculty.uq_faculty_user' => [1, ['user_id']],
        'faculty.uq_faculty_employee_number' => [1, ['employee_number']],
        'faculty_history.ix_faculty_history_replay' => [0, ['faculty_id', 'field', 'seq']],
        'faculty_history.ix_faculty_history_actor' => [0, ['actor_id']],
        'section_instructor_history.ix_sih_pair' => [0, ['section_id', 'faculty_id', 'seq']],
        'section_instructor_history.ix_sih_faculty' => [0, ['faculty_id']],
        'section_instructor_history.ix_sih_actor' => [0, ['actor_id']],
        'section_instructors.uq_section_instructors_seq' => [1, ['assigned_seq']],
        'section_instructors.ix_section_instructors_faculty' => [0, ['faculty_id', 'section_id']],
        'enrollments.uq_enrollments_active' => [1, ['student_id', 'active_section_id']],
        'enrollments.ix_enrollments_section_status' => [0, ['section_id', 'status']],
        'enrollments.ix_enrollments_status' => [0, ['status', 'id']],
        'enrollments.ix_enrollments_decided_by' => [0, ['decided_by']],
        'enrollment_history.ix_eh_actor' => [0, ['actor_id']],
        'grades.uq_grades_section_student' => [1, ['section_id', 'student_id']],
        'grades.ix_grades_student_status' => [0, ['student_id', 'status']],
        'grade_history.ix_gh_grade' => [0, ['grade_id', 'seq']],
        'grade_history.ix_gh_section_actor' => [0, ['section_id', 'actor_id']],
        'grade_history.ix_gh_student' => [0, ['student_id']],
        'grade_history.ix_gh_actor' => [0, ['actor_id']],
    ];
}

/** "table.constraint" => [columns, referenced table, referenced columns]; every FK is ON DELETE RESTRICT ON UPDATE RESTRICT. */
function inventoryForeignKeys(): array
{
    return [
        'user_roles.fk_user_roles_user' => [['user_id'], 'users', ['id']],
        'audit_logs.fk_audit_logs_actor' => [['actor_id'], 'users', ['id']],
        'students.fk_students_user' => [['user_id'], 'users', ['id']],
        'students.fk_students_program' => [['program_code'], 'programs', ['code']],
        'student_academic_history.fk_sah_student' => [['student_id'], 'students', ['id']],
        'sections.fk_sections_term' => [['term_id'], 'terms', ['id']],
        'section_meetings.fk_section_meetings_section' => [['section_id'], 'sections', ['id']],
        'faculty.fk_faculty_user' => [['user_id'], 'users', ['id']],
        'faculty_history.fk_faculty_history_faculty' => [['faculty_id'], 'faculty', ['id']],
        'faculty_history.fk_faculty_history_actor' => [['actor_id'], 'users', ['id']],
        'section_instructor_history.fk_sih_section' => [['section_id'], 'sections', ['id']],
        'section_instructor_history.fk_sih_faculty' => [['faculty_id'], 'faculty', ['id']],
        'section_instructor_history.fk_sih_actor' => [['actor_id'], 'users', ['id']],
        'section_instructors.fk_si_section' => [['section_id'], 'sections', ['id']],
        'section_instructors.fk_si_faculty' => [['faculty_id'], 'faculty', ['id']],
        'section_instructors.fk_si_history' => [['assigned_seq'], 'section_instructor_history', ['seq']],
        'enrollments.fk_enrollments_student' => [['student_id'], 'students', ['id']],
        'enrollments.fk_enrollments_section' => [['section_id'], 'sections', ['id']],
        'enrollments.fk_enrollments_decider' => [['decided_by'], 'users', ['id']],
        'enrollment_history.fk_eh_enrollment' => [['enrollment_id'], 'enrollments', ['id']],
        'enrollment_history.fk_eh_actor' => [['actor_id'], 'users', ['id']],
        'grades.fk_grades_section' => [['section_id'], 'sections', ['id']],
        'grades.fk_grades_student' => [['student_id'], 'students', ['id']],
        'grade_history.fk_gh_grade' => [['grade_id'], 'grades', ['id']],
        'grade_history.fk_gh_section' => [['section_id'], 'sections', ['id']],
        'grade_history.fk_gh_student' => [['student_id'], 'students', ['id']],
        'grade_history.fk_gh_actor' => [['actor_id'], 'users', ['id']],
    ];
}

/** table => CHECK constraint names (V0009 adds two grade_history checks, V0010 adds ck_grades_value and ck_gh_value_scale, by ALTER). */
function inventoryChecks(): array
{
    return [
        'users' => ['ck_users_email', 'ck_users_status', 'ck_users_password_hash', 'ck_users_auth_version'],
        'user_roles' => ['ck_user_roles_role'],
        'audit_logs' => ['ck_audit_logs_id', 'ck_audit_logs_actor', 'ck_audit_logs_action', 'ck_audit_logs_outcome', 'ck_audit_logs_changes'],
        'programs' => ['ck_programs_code', 'ck_programs_name', 'ck_programs_max_year'],
        'students' => ['ck_students_number', 'ck_students_names', 'ck_students_year', 'ck_students_status', 'ck_students_admission'],
        'student_academic_history' => ['ck_sah_seq', 'ck_sah_field', 'ck_sah_program', 'ck_sah_year', 'ck_sah_status'],
        'terms' => ['ck_terms_code', 'ck_terms_name', 'ck_terms_dates', 'ck_terms_flags'],
        'sections' => ['ck_sections_course', 'ck_sections_section', 'ck_sections_title', 'ck_sections_units', 'ck_sections_capacity', 'ck_sections_mode'],
        'section_meetings' => ['ck_section_meetings_position', 'ck_section_meetings_day', 'ck_section_meetings_times', 'ck_section_meetings_room'],
        'faculty' => ['ck_faculty_employee_number', 'ck_faculty_names', 'ck_faculty_status'],
        'faculty_history' => ['ck_faculty_history_seq', 'ck_faculty_history_field', 'ck_faculty_history_status', 'ck_faculty_history_values'],
        'section_instructor_history' => ['ck_sih_seq', 'ck_sih_action'],
        'enrollments' => ['ck_enrollments_status', 'ck_enrollments_decision', 'ck_enrollments_order', 'ck_enrollments_reason', 'ck_enrollments_reject_reason'],
        'enrollment_history' => ['ck_eh_seq', 'ck_eh_action', 'ck_eh_reason', 'ck_eh_reject_reason'],
        'grades' => ['ck_grades_status', 'ck_grades_value_len', 'ck_grades_remarks', 'ck_grades_order', 'ck_grades_value'],
        'grade_history' => ['ck_gh_seq', 'ck_gh_action', 'ck_gh_reason', 'ck_gh_reason_text', 'ck_gh_value_len', 'ck_gh_values',
            'ck_gh_remarks_text', 'ck_gh_remarks_unvalued', 'ck_gh_value_scale'],
        'schema_migrations' => ['ck_schema_migrations_checksum', 'ck_schema_migrations_state'],
    ];
}

/** Grants parsed from app_grants.sql with the Phase 5 substitutions; every GRANT line must parse. @return list<string> "table:PRIVILEGE" */
function inventoryExpectedGrants(): array
{
    $lines = file(dirname(__DIR__, 2) . '/database/grants/app_grants.sql', FILE_IGNORE_NEW_LINES);
    check($lines !== false, 'Unable to read app_grants.sql.');
    $grants = []; $statements = 0;
    foreach ($lines as $line) {
        if (!str_starts_with($line, 'GRANT ')) { continue; }
        $statements++;
        check(preg_match('/\AGRANT ([A-Z, ]+) ON `\{\{SCHEMA\}\}`\.`([a-z_]+)` TO \'\{\{APP_USER\}\}\'@\'127\.0\.0\.1\';\z/D', $line, $m) === 1,
            'Unparseable grant line: ' . $line);
        foreach (explode(', ', $m[1]) as $privilege) { $grants[] = $m[2] . ':' . $privilege; }
    }
    same(19, $statements);
    sort($grants);
    check(count($grants) === count(array_unique($grants)), 'Duplicate grant.');
    return $grants;
}

if (MySQLTestSupport::enabled()) {
    test('DB-02 DB-06 MySQL schema inventory: 18 tables plus schema_migrations, keys, indexes, foreign keys, checks, engine and collation', static function (): void {
        $owner = MySQLTestSupport::connect();
        MySQLTestSupport::reset($owner);
        (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $rows = static fn (string $sql): array => $owner->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Tables, engine, collation.
        $primary = inventoryPrimaryKeys();
        $expectedTables = array_keys($primary);
        sort($expectedTables);
        same(19, count($expectedTables));
        $tables = $rows("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME");
        // information_schema sorts with utf8mb3_general_ci ('enrollments' before 'enrollment_history'); compare in PHP byte order.
        $tableNames = array_column($tables, 'TABLE_NAME');
        sort($tableNames);
        same($expectedTables, $tableNames);
        foreach ($tables as $table) {
            same(['BASE TABLE', 'InnoDB', 'utf8mb4_nopad_bin'], [$table['TABLE_TYPE'], $table['ENGINE'], $table['TABLE_COLLATION']]);
        }
        same([], $rows("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'ais_test' "
            . "AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_nopad_bin'"));

        // Indexes: group STATISTICS rows by table and index.
        $indexes = [];
        foreach ($rows("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX") as $row) {
            $indexes[$row['TABLE_NAME'] . '.' . $row['INDEX_NAME']]['unique'] = 1 - (int) $row['NON_UNIQUE'];
            $indexes[$row['TABLE_NAME'] . '.' . $row['INDEX_NAME']]['columns'][(int) $row['SEQ_IN_INDEX']] = $row['COLUMN_NAME'];
        }
        $actualPrimary = [];
        foreach ($indexes as $name => $index) {
            if (str_ends_with($name, '.PRIMARY')) {
                same(1, $index['unique']);
                $actualPrimary[substr($name, 0, -8)] = array_values($index['columns']);
            }
        }
        ksort($actualPrimary); ksort($primary);
        same($primary, $actualPrimary);
        $actualSecondary = [];
        foreach ($indexes as $name => $index) {
            if (!str_ends_with($name, '.PRIMARY')) { $actualSecondary[$name] = [$index['unique'], array_values($index['columns'])]; }
        }
        $expectedSecondary = inventoryIndexes();
        ksort($actualSecondary); ksort($expectedSecondary);
        same($expectedSecondary, $actualSecondary);

        // Foreign keys: columns, referenced table and columns, ON DELETE/UPDATE RESTRICT.
        $foreign = [];
        foreach ($rows("SELECT TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME "
            . "FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'ais_test' AND REFERENCED_TABLE_NAME IS NOT NULL "
            . "ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION") as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
            $foreign[$key][0][] = $row['COLUMN_NAME'];
            $foreign[$key][1] = $row['REFERENCED_TABLE_NAME'];
            $foreign[$key][2][] = $row['REFERENCED_COLUMN_NAME'];
        }
        $expectedForeign = inventoryForeignKeys();
        same(27, count($expectedForeign));
        ksort($foreign); ksort($expectedForeign);
        same($expectedForeign, $foreign);
        $rules = $rows("SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, CONSTRAINT_NAME");
        $ruleNames = array_map(static fn (array $r): string => $r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME'], $rules);
        sort($ruleNames); // same collation reason as the table list above
        same(array_keys($expectedForeign), $ruleNames);
        foreach ($rules as $rule) {
            same(['RESTRICT', 'RESTRICT'], [$rule['DELETE_RULE'], $rule['UPDATE_RULE']]);
        }

        // Constraint inventory: PRIMARY KEY, UNIQUE, FOREIGN KEY and CHECK names per table.
        $expectedUnique = array_keys(array_filter($expectedSecondary, static fn (array $i): bool => $i[0] === 1));
        $constraints = ['PRIMARY KEY' => [], 'UNIQUE' => [], 'FOREIGN KEY' => [], 'CHECK' => []];
        foreach ($rows("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = 'ais_test'") as $row) {
            $constraints[$row['CONSTRAINT_TYPE']][] = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
        }
        sort($constraints['UNIQUE']); sort($expectedUnique);
        same($expectedUnique, $constraints['UNIQUE']);
        sort($constraints['FOREIGN KEY']);
        same(array_keys($expectedForeign), $constraints['FOREIGN KEY']);
        same(19, count($constraints['PRIMARY KEY']));
        $expectedChecks = [];
        foreach (inventoryChecks() as $table => $names) { foreach ($names as $name) { $expectedChecks[] = $table . '.' . $name; } }
        sort($expectedChecks);
        $actualChecks = array_map(static fn (array $r): string => $r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME'],
            $rows("SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'ais_test'"));
        sort($actualChecks);
        same($expectedChecks, $actualChecks);
    });

    if (is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD'))) {
        test('DB-12 MySQL app grants: exactly the 46 table privileges of app_grants.sql, nothing at schema, global or column level', static function (): void {
            $owner = MySQLTestSupport::connect();
            MySQLTestSupport::reset($owner);
            (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
            $expected = inventoryExpectedGrants();
            same(46, count($expected));
            // The app account reads its own privileges (visible to any account without extra rights).
            $app = MySQLTestSupport::connectApp();
            same('ais_test_app@127.0.0.1', (string) $app->query('SELECT CURRENT_USER()')->fetchColumn());
            $grantee = "'ais_test_app'@'127.0.0.1'";
            $query = static function (string $sql) use ($app, $grantee): array {
                $statement = $app->prepare($sql);
                $statement->execute([$grantee]);
                return $statement->fetchAll(PDO::FETCH_ASSOC);
            };
            $actualRows = $query("SELECT TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ? ORDER BY TABLE_NAME, PRIVILEGE_TYPE");
            same(46, count($actualRows));
            $actual = [];
            foreach ($actualRows as $row) {
                same(['ais_test', 'NO'], [$row['TABLE_SCHEMA'], $row['IS_GRANTABLE']]);
                $actual[] = $row['TABLE_NAME'] . ':' . $row['PRIVILEGE_TYPE'];
            }
            sort($actual);
            same($expected, $actual);
            same([], $query('SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ?'));
            same([], $query('SELECT PRIVILEGE_TYPE FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = ?'));
            same(['USAGE'], array_column($query('SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ?'), 'PRIVILEGE_TYPE'));

            // The tester account itself: USAGE globally, schema privileges on ais_test only (read as the tester).
            same('ais_tester@127.0.0.1', (string) $owner->query('SELECT CURRENT_USER()')->fetchColumn());
            $tester = $owner->prepare('SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ?');
            $tester->execute(["'ais_tester'@'127.0.0.1'"]);
            same(['USAGE'], $tester->fetchAll(PDO::FETCH_COLUMN));
            $tester = $owner->prepare('SELECT DISTINCT TABLE_SCHEMA FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ?');
            $tester->execute(["'ais_tester'@'127.0.0.1'"]);
            same(['ais_test'], $tester->fetchAll(PDO::FETCH_COLUMN));
        });
    }
}
