<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

use Database\Tools\MigrationRunner;

if (MySQLTestSupport::enabled()) {
    test('MySQL checks, binary search, keys, FKs, and locked history sequence', static function (): void {
        $pdo = MySQLTestSupport::connect();
        MySQLTestSupport::reset($pdo);
        (new MigrationRunner($pdo, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $run = static function (PDO $db, string $sql, array $args = []): void {
            $statement = $db->prepare($sql);
            $statement->execute($args);
        };
        $reject = static function (string $sql, array $args = []) use ($pdo, $run): void {
            throws(PDOException::class, static fn () => $run($pdo, $sql, $args));
        };

        $run($pdo, 'INSERT INTO `ais_test`.`programs` (code,name,max_year_level) VALUES (?,?,?)', ['SCI', 'Résumé Math', 4]);
        $reject('INSERT INTO `ais_test`.`programs` (code,name,max_year_level) VALUES (?,?,?)', ["SCI\n", 'Bad', 4]);
        $reject('INSERT INTO `ais_test`.`programs` (code,name,max_year_level) VALUES (?,?,?)', ['sci', 'Bad', 4]);
        $run($pdo, 'INSERT INTO `ais_test`.`programs` (code,name,max_year_level) VALUES (?,?,?)', ['PCT', '100%_ Physics', 4]);
        $query = $pdo->prepare('SELECT COUNT(*) FROM `ais_test`.`programs` WHERE name = ?');
        $query->execute(['résumé Math']);
        same(0, (int) $query->fetchColumn());
        $query = $pdo->prepare("SELECT COUNT(*) FROM `ais_test`.`programs` WHERE LOWER(name) LIKE ? ESCAPE '!'");
        $query->execute(['%résumé math%']);
        same(1, (int) $query->fetchColumn());
        $query->execute(['%resume math%']);
        same(0, (int) $query->fetchColumn());
        $query->execute(['%100!%!_ physics%']);
        same(1, (int) $query->fetchColumn());

        $term = 'INSERT INTO `ais_test`.`terms` (code,name,start_date,end_date,is_current,enrollment_open,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)';
        $run($pdo, $term, ['T1', 'Term One', '2026-01-01', '2026-06-01', 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $reject($term, ['T2', 'Term Two', '2026-07-01', '2026-12-01', 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $run($pdo, $term, ['T3', 'Term Three', '2026-07-01', '2026-12-01', 0, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $run($pdo, $term, ['T4', 'Term Four', '2027-01-01', '2027-06-01', 0, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $user = 'INSERT INTO `ais_test`.`users` (email,status,password_hash,auth_version) VALUES (?,?,?,?)';
        $run($pdo, $user, ['faculty@example.test', 'active', str_repeat('x', 20), 1]);
        $reject($user, ['faculty@example.test', 'active', str_repeat('x', 20), 1]);
        $student = 'INSERT INTO `ais_test`.`students` (user_id,student_number,first_name,middle_name,last_name,program_code,year_level,academic_status,admission_year,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
        $run($pdo, $student, [1, 'STU-1', 'A', null, 'B', 'SCI', 1, 'active', 2026, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $reject($student, [999, 'STU-2', 'A', null, 'B', 'SCI', 1, 'active', 2026, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $reject('DELETE FROM `ais_test`.`programs` WHERE code = ?', ['SCI']);
        $reject('DELETE FROM `ais_test`.`users` WHERE id = ?', [1]);
        $run($pdo, 'INSERT INTO `ais_test`.`faculty` (user_id,employee_number,first_name,last_name,department,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)', [1, 'EMP-1', 'A', 'B', 'Science', 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $run($pdo, 'INSERT INTO `ais_test`.`sections` (term_id,course_code,course_title,units,section_code,capacity,approved_count,delivery_mode,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [1, 'CS101', 'Intro', 3, 'A', 30, 0, 'tba', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $second = MySQLTestSupport::connect();
        $second->exec('SET SESSION innodb_lock_wait_timeout=1');
        $pdo->beginTransaction();
        $lock = $pdo->query("SELECT last_seq FROM `ais_test`.`repository_locks` WHERE name='enrollment' FOR UPDATE");
        same(0, (int) $lock->fetchColumn());
        $run($pdo, 'INSERT INTO `ais_test`.`section_instructor_history` (seq,at,action,section_id,faculty_id,actor_id) VALUES (?,?,?,?,?,?)', [1, '2026-01-01 00:00:00', 'assigned', 1, 1, 1]);
        $run($pdo, "UPDATE `ais_test`.`repository_locks` SET last_seq=1 WHERE name='enrollment'");
        $second->beginTransaction();
        throws(PDOException::class, static fn () => $second->query("SELECT last_seq FROM `ais_test`.`repository_locks` WHERE name='enrollment' FOR UPDATE"));
        $second->rollBack();
        $pdo->commit();
        $second->beginTransaction();
        $lock = $second->query("SELECT last_seq FROM `ais_test`.`repository_locks` WHERE name='enrollment' FOR UPDATE");
        same(1, (int) $lock->fetchColumn());
        $run($second, 'INSERT INTO `ais_test`.`section_instructor_history` (seq,at,action,section_id,faculty_id,actor_id) VALUES (?,?,?,?,?,?)', [2, '2026-01-01 00:01:00', 'unassigned', 1, 1, 1]);
        $run($second, "UPDATE `ais_test`.`repository_locks` SET last_seq=2 WHERE name='enrollment'");
        $second->commit();
        same([1, 2], array_map('intval', $pdo->query('SELECT seq FROM `ais_test`.`section_instructor_history` ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN)));
        same(2, (int) $pdo->query("SELECT last_seq FROM `ais_test`.`repository_locks` WHERE name='enrollment'")->fetchColumn());
        $run($pdo, 'INSERT INTO `ais_test`.`section_instructors` (section_id,faculty_id,assigned_seq) VALUES (?,?,?)', [1, 1, 1]);
        $reject('INSERT INTO `ais_test`.`section_instructors` (section_id,faculty_id,assigned_seq) VALUES (?,?,?)', [1, 1, 2]);
    });
}
