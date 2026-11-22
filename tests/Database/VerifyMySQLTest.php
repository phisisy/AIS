<?php
declare(strict_types=1);

require_once __DIR__ . '/ImportMySQLTest.php';

function phase8InjectAdditionalFaculty(PDO $owner, int $first, int $last): void
{
    $user = $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, 1)');
    $role = $owner->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
    $faculty = $owner->prepare('INSERT INTO faculty (id, user_id, employee_number, first_name, last_name, '
        . 'department, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $hash = password_hash('Disposable Phase 8 fixture', PASSWORD_DEFAULT);
    for ($id = $first; $id <= $last; $id++) {
        $userId = $id + 2;
        $user->execute([$userId, 'extra' . $id . '@example.test', 'active', $hash]);
        $role->execute([$userId, 'faculty']);
        $faculty->execute([$id, $userId, 'P8-' . $id, 'Faculty', 'Extra', 'Science',
            'active', '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
    }
}

if (phase8OnlineEnabled()) {
    test('Phase 8 V2: verifier names each cross-row corruption and prints ids only', static function (): void {
        $cases = [
            'invalid student model' => ['student_model', static function (PDO $db): void {
                $db->exec('SET SESSION check_constraint_checks = OFF');
                try { $db->exec('UPDATE students SET year_level = 0 WHERE id = 1'); }
                finally { $db->exec('SET SESSION check_constraint_checks = ON'); }
            }],
            'wrong meeting position' => ['section_model', static function (PDO $db): void {
                $db->exec('UPDATE section_meetings SET position = 5 WHERE section_id = 1 AND position = 1');
            }],
            'invalid faculty model' => ['faculty_model', static function (PDO $db): void {
                $db->exec('SET SESSION check_constraint_checks = OFF');
                try { $db->exec("UPDATE faculty SET first_name = '' WHERE id = 1"); }
                finally { $db->exec('SET SESSION check_constraint_checks = ON'); }
            }],
            'student history gap' => ['student_history_seq', static function (PDO $db): void {
                $db->exec('UPDATE student_academic_history SET seq = 9 WHERE student_id = 1 AND seq = 4');
            }],
            'student history/current mismatch' => ['student_history_replay', static function (PDO $db): void {
                $db->exec('UPDATE students SET year_level = 3 WHERE id = 1');
            }],
            'assignment history gap' => ['assignment_history_seq', static function (PDO $db): void {
                $db->exec("INSERT INTO section_instructor_history (seq, at, action, section_id, faculty_id, actor_id) "
                    . "VALUES (7, '2025-01-01 03:04:07', 'assigned', 3, 2, 1)");
                $db->exec("UPDATE repository_locks SET last_seq = 7 WHERE name = 'enrollment'");
            }],
            'assignment replay mismatch' => ['assignment_history_replay', static function (PDO $db): void {
                $db->exec('DELETE FROM section_instructors WHERE section_id = 2 AND faculty_id = 1');
            }],
            'stale assigned sequence' => ['assignment_assigned_seq', static function (PDO $db): void {
                $db->exec('UPDATE section_instructors SET assigned_seq = 1 WHERE section_id = 1 AND faculty_id = 1');
            }],
            'sixth instructor' => ['instructor_cap', static function (PDO $db): void {
                phase8InjectAdditionalFaculty($db, 3, 6);
                $history = $db->prepare('INSERT INTO section_instructor_history (seq, at, action, section_id, faculty_id, actor_id) '
                    . "VALUES (?, '2025-01-01 03:04:07', 'assigned', 1, ?, 1)");
                $assignment = $db->prepare('INSERT INTO section_instructors (section_id, faculty_id, assigned_seq) VALUES (1, ?, ?)');
                for ($id = 3; $id <= 6; $id++) {
                    $seq = $id + 3;
                    $history->execute([$seq, $id]);
                    $assignment->execute([$id, $seq]);
                }
                $db->exec("UPDATE repository_locks SET last_seq = 9 WHERE name = 'enrollment'");
            }],
            'same-term instructor overlap' => ['instructor_overlap', static function (PDO $db): void {
                $db->exec("INSERT INTO section_instructor_history (seq, at, action, section_id, faculty_id, actor_id) "
                    . "VALUES (6, '2025-01-01 03:04:07', 'assigned', 3, 1, 1)");
                $db->exec('INSERT INTO section_instructors (section_id, faculty_id, assigned_seq) VALUES (3, 1, 6)');
                $db->exec("UPDATE section_meetings SET day = 'MON', start_time = '08:30', end_time = '09:30' "
                    . 'WHERE section_id = 3 AND position = 0');
                $db->exec("UPDATE repository_locks SET last_seq = 6 WHERE name = 'enrollment'");
            }],
            'scheduled section without a meeting' => ['scheduled_meetings', static function (PDO $db): void {
                $db->exec('DELETE FROM section_meetings WHERE section_id = 1');
            }],
            'meeting self-overlap' => ['meeting_self_overlap', static function (PDO $db): void {
                $db->exec("UPDATE section_meetings SET day = 'MON' WHERE section_id = 1 AND position = 1");
            }],
            'audit id gap' => ['audit_ids', static function (PDO $db): void {
                $db->exec('UPDATE audit_logs SET id = 3 WHERE id = 2');
            }],
            'lock counter drift' => ['repository_last_seq', static function (PDO $db): void {
                $db->exec("UPDATE repository_locks SET last_seq = 99 WHERE name = 'auth'");
            }],
            'user without a role' => ['user_roles', static function (PDO $db): void {
                $db->exec('DELETE FROM user_roles WHERE user_id = 3');
            }],
            'year above program maximum' => ['student_year_level', static function (PDO $db): void {
                $db->exec('UPDATE students SET year_level = 5 WHERE id = 1');
            }],
            'two current terms' => ['current_term', static function (PDO $db): void {
                $db->exec('ALTER TABLE terms DROP INDEX uq_terms_current');
                $db->exec('UPDATE terms SET is_current = 1 WHERE id = 2');
            }],
            'invalid user model' => ['user_model', static function (PDO $db): void {
                $db->exec("UPDATE users SET password_hash = REPEAT('x', 30) WHERE id = 3");
            }],
            'invalid program model' => ['program_model', static function (PDO $db): void {
                $db->exec("UPDATE programs SET name = ' ' WHERE code = 'BSIT'");
            }],
            'invalid term model' => ['term_model', static function (PDO $db): void {
                $db->exec("UPDATE terms SET name = ' Trim ' WHERE id = 2");
            }],
            'invalid section model' => ['section_model', static function (PDO $db): void {
                $db->exec("UPDATE sections SET course_title = ' Trim ' WHERE id = 2");
            }],
            'faculty history gap' => ['faculty_history_seq', static function (PDO $db): void {
                $db->exec("INSERT INTO faculty_history (seq, at, faculty_id, field, from_value, to_value, actor_id) "
                    . "VALUES (2, '2025-01-01 03:04:05', 1, 'status', NULL, 'active', 1)");
                $db->exec("UPDATE repository_locks SET last_seq = 2 WHERE name = 'faculty'");
            }],
            'faculty history replay' => ['faculty_history_replay', static function (PDO $db): void {
                $db->exec("INSERT INTO faculty_history (seq, at, faculty_id, field, from_value, to_value, actor_id) "
                    . "VALUES (1, '2025-01-01 03:04:05', 1, 'status', NULL, 'inactive', 1)");
                $db->exec("UPDATE repository_locks SET last_seq = 1 WHERE name = 'faculty'");
            }],
        ];
        foreach ($cases as $label => [$expected, $mutate]) {
            $owner = phase8FreshDatabase();
            $runtime = phase8Runtime();
            try {
                phase8ImportAll($owner, $runtime);
                $mutate($owner);
                [$exit, $stdout, $stderr] = phase8Verify($runtime);
                same(1, $exit);
                check(str_contains($stdout, $expected . ' FAIL'), $label . ': missing ' . $expected);
                same('', $stderr);
                check(!str_contains($stdout, '@example.test') && !str_contains($stdout, 'Fixture')
                    && !str_contains($stdout, 'Computer Science') && !str_contains($stdout, '$2y$'),
                    $label . ': verifier exposed a value');
            } finally {
                removeTestDirectory($runtime);
            }
        }
    });

    test('Phase 8 V4: verifier database failure is sanitized', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = phase8Runtime();
        try {
            [$exit, $stdout, $stderr] = phase8Verify($runtime, true, ['DB_PASSWORD' => 'wrong-phase8-password']);
            same(2, $exit);
            same('', $stdout);
            check($stderr !== '' && !str_contains($stderr, 'wrong-phase8-password')
                && !str_contains(strtolower($stderr), 'sqlstate') && !str_contains($stderr, '@example.test'));
        } finally {
            removeTestDirectory($runtime);
        }
    });
}
