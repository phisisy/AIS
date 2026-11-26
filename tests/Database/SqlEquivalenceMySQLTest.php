<?php
declare(strict_types=1);

// DB-16 equivalence of database/database.sql and MigrationRunner::up(). UNVERIFIED: written source-only, never executed.
// Online only (AIS_MYSQL_TESTS=1, ais_test, ais_tester). Both installs happen inside the single permitted test database,
// one after the other, each after the guarded MySQLTestSupport::reset(). Nothing outside ais_test is touched.

use Database\Tools\MigrationRunner;

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

/** The file's statements without CREATE DATABASE and USE; refuses any other database name or database-level statement. @return list<string> */
function sqlEquivalenceStatements(string $file): array
{
    $sql = file_get_contents($file);
    check($sql !== false && $sql !== '', 'Unable to read database.sql.');
    $kept = []; $skipped = [];
    foreach (MigrationRunner::splitStatements($sql) as $statement) {
        if (preg_match('/\A(CREATE DATABASE|USE)\b/i', $statement, $m) === 1) {
            $normalized = strtoupper(preg_replace('/\s+/', ' ', $m[1]));
            // Only the exact test database may be named by the statements we drop.
            check($normalized === 'USE' ? $statement === 'USE ais_test'
                : $statement === 'CREATE DATABASE ais_test CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin', 'Unexpected database statement: ' . $statement);
            $skipped[] = $normalized;
            continue;
        }
        check(!str_contains($statement, '/*') && preg_match('/^\s*#/m', $statement) !== 1, 'database.sql has a comment inside a statement: ' . substr($statement, 0, 80));
        // Any qualified identifier (`q`.x, q.x, `q`.`x`) must be qualified by exactly ais_test.
        check(preg_match_all('/(?:`([^`]*)`|\b([A-Za-z_][A-Za-z0-9_$]*))\s*\.\s*(?=[`A-Za-z_])/', $statement, $q, PREG_SET_ORDER) !== false, 'Qualifier scan failed.');
        foreach ($q as $match) {
            check((($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')) === 'ais_test','database.sql has a foreign qualifier: ' . substr($statement, 0, 80));
        }
        check(preg_match('/\b(DATABASE|SCHEMA)\b/i', $statement) !== 1 && preg_match('/\Ause\b/i', $statement) !== 1
            && preg_match('/\bais(?!_test\b)\w*/i', $statement) !== 1, 'database.sql names another database: ' . substr($statement, 0, 80));
        $kept[] = $statement;
    }
    same(['CREATE DATABASE', 'USE'], $skipped);
    // Exact inventory (trusted run 2026-11-26, revision 6), replacing the never-executed "> 40" floor: the 28 migration
    // statements of V0001-V0010, plus the schema_migrations table and its tracking insert, plus 2 SET SESSION = 32.
    $kinds = [];
    foreach ($kept as $statement) {
        $kind = strtoupper(implode(' ', array_slice(preg_split('/\s+/', trim($statement)), 0, 2)));
        $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
    }
    ksort($kinds);
    same(['ALTER TABLE' => 5, 'CREATE TABLE' => 19, 'INSERT INTO' => 6, 'SET SESSION' => 2], $kinds);
    check(count($kept) === 32, 'database.sql statement count changed.');
    return $kept;
}

/** Everything the two installs must agree on (timestamps and applied_by excluded). */
function sqlEquivalenceSnapshot(PDO $pdo): array
{
    $q = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return [
        'schema' => $q("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'ais_test'"),
        'tables' => $q("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME"),
        'columns' => $q("SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME, "
            . "COLLATION_NAME, COLUMN_KEY, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, ORDINAL_POSITION"),
        'statistics' => $q("SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE, COLLATION, SUB_PART, NULLABLE "
            . "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"),
        'table_constraints' => $q("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, CONSTRAINT_NAME"),
        'key_column_usage' => $q("SELECT TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME "
            . "FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"),
        'check_constraints' => $q("SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, CONSTRAINT_NAME"),
        'referential_constraints' => $q("SELECT TABLE_NAME, CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE, MATCH_OPTION "
            . "FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'ais_test' ORDER BY TABLE_NAME, CONSTRAINT_NAME"),
        'repository_locks' => $q('SELECT name, last_seq FROM repository_locks ORDER BY name'),
        'schema_migrations' => $q('SELECT version, name, checksum, state, statements_applied FROM schema_migrations ORDER BY version'),
    ];
}

if (MySQLTestSupport::enabled()) {
    test('DB-16 MySQL: database.sql (minus CREATE DATABASE and USE) and MigrationRunner::up() give identical schema, locks and tracking rows', static function (): void {
        $pdo = MySQLTestSupport::connect();
        check((string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'ais_tester@127.0.0.1','Equivalence test requires ais_tester.');
        $statements = sqlEquivalenceStatements(dirname(__DIR__, 2) . '/database/database.sql');
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $zone = (string) $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
        try {
            // A: the consolidated file.
            MySQLTestSupport::reset($pdo);
            foreach ($statements as $index => $statement) {
                MySQLTestSupport::assertConnection($pdo);
                try {
                    $pdo->exec($statement);
                } catch (PDOException $error) {
                    throw new RuntimeException('database.sql statement ' . ($index + 1) . ' failed (' . ($error->errorInfo[1] ?? '?') . '): '
                        . substr($statement, 0, 100));
                }
            }
            MySQLTestSupport::assertConnection($pdo);
            $fromFile = sqlEquivalenceSnapshot($pdo);
        } finally {
            // The file sets strict mode and UTC itself; put the session back exactly as the harness had it.
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($mode));
            $pdo->exec('SET SESSION time_zone = ' . $pdo->quote($zone));
        }
        // B: the migrations.
        MySQLTestSupport::reset($pdo);
        (new MigrationRunner($pdo, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $fromMigrations = sqlEquivalenceSnapshot($pdo);

        same(array_keys($fromMigrations), array_keys($fromFile));
        foreach ($fromMigrations as $part => $rows) {
            check($rows !== [], 'Snapshot part is empty: ' . $part);
            if ($rows === $fromFile[$part]) { continue; }
            $detail = 'rows ' . count($fromFile[$part]) . ' (file) vs ' . count($rows) . ' (migrations)';
            foreach ($rows as $i => $row) {
                if (($fromFile[$part][$i] ?? null) !== $row) {
                    $detail .= '; first difference at row ' . $i . ': file=' . json_encode($fromFile[$part][$i] ?? null) . ' migrations=' . json_encode($row);
                    break;
                }
            }
            throw new RuntimeException('database.sql differs from the migrations in ' . $part . ': ' . substr($detail, 0, 600)
                . ($part === 'schema_migrations' ? ' (checksums assume LF line endings in the migration files)' : ''));
        }
        same(10, count($fromMigrations['schema_migrations'])); // V0001-V0010 (was 9 before V0010)
        same(19, count($fromMigrations['tables']));
        same(5, count($fromMigrations['repository_locks']));
    });
}
