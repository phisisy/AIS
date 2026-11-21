<?php
declare(strict_types=1);

namespace Database\Tools;

use PDO;
use RuntimeException;

final class MigrationRefused extends RuntimeException
{
}

final class MigrationError extends RuntimeException
{
}

/** Forward-only runner. MariaDB DDL commits implicitly, so a started row requires manual recovery. */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $target,
        private readonly string $directory
    ) {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $target)) {
            throw new MigrationRefused('Invalid target.');
        }
    }

    /** @return list<array{version:int,name:string,state:string,statements_applied:int}> */
    public function status(): array
    {
        $this->checkServer();
        $this->ensureTrackingTable();
        $files = self::discoverFiles($this->directory);
        $rows = $this->trackingRows();
        self::validateHistory($files, $rows);
        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version'],
            'name' => $row['name'],
            'state' => $row['state'],
            'statements_applied' => (int) $row['statements_applied'],
        ], $rows);
    }

    /** @return list<array{version:int,name:string}> */
    public function up(): array
    {
        $this->checkServer();
        $files = self::discoverFiles($this->directory);
        $statementsByVersion = [];
        foreach ($files as $version => $file) {
            $statementsByVersion[$version] = self::splitStatements($file['sql']);
        }
        // S5: refuse before any write, including creation of the tracking table.
        if ($this->target !== 'ais_test' && array_key_exists(5, $files)) {
            throw new MigrationRefused('V0005 faculty target refused until T-30 is merged.');
        }
        $this->ensureTrackingTable();
        $rows = $this->trackingRows();
        self::validateHistory($files, $rows);
        $applied = array_fill_keys(array_map(static fn (array $row): int => (int) $row['version'], $rows), true);
        $done = [];
        foreach ($files as $version => $file) {
            if (isset($applied[$version])) {
                continue;
            }
            self::assertFacultyTarget($version, $this->target);
            $statements = $statementsByVersion[$version];
            try {
                $this->pdo->prepare('INSERT INTO schema_migrations '
                    . '(version, name, checksum, state, statements_applied, started_at, finished_at, applied_by) '
                    . "VALUES (?, ?, ?, 'started', 0, UTC_TIMESTAMP(), NULL, CURRENT_USER())")
                    ->execute([$version, $file['name'], $file['checksum']]);
                foreach ($statements as $statement) {
                    $this->pdo->exec($statement);
                    $this->pdo->prepare('UPDATE schema_migrations SET statements_applied = statements_applied + 1 '
                        . 'WHERE version = ? AND state = ?')->execute([$version, 'started']);
                }
                $this->pdo->prepare("UPDATE schema_migrations SET state = 'applied', finished_at = UTC_TIMESTAMP() "
                    . "WHERE version = ? AND state = 'started'")->execute([$version]);
            } catch (\Throwable) {
                throw new MigrationError(sprintf('V%04d %s failed; inspect tracking and manual recovery.', $version, $file['name']));
            }
            $done[] = ['version' => $version, 'name' => $file['name']];
        }
        return $done;
    }

    public static function assertFacultyTarget(int $version, string $target): void
    {
        if ($version >= 5 && $target !== 'ais_test') {
            throw new MigrationRefused('Faculty migrations require ais_test until T-30 is merged.');
        }
    }

    /** @return array<int,array{name:string,checksum:string,sql:string}> */
    public static function discoverFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new MigrationRefused('Migration directory missing.');
        }
        $files = [];
        foreach (glob($directory . '/V*.sql') ?: [] as $path) {
            $basename = basename($path);
            if (!preg_match('/\AV([0-9]{4})__([a-z][a-z0-9_]*)\.sql\z/D', $basename, $match)) {
                throw new MigrationRefused('Invalid migration filename.');
            }
            $version = (int) $match[1];
            if ($version < 1 || isset($files[$version])) {
                throw new MigrationRefused('Duplicate or invalid migration version.');
            }
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('Migration file unreadable.');
            }
            $files[$version] = ['name' => $match[2], 'checksum' => hash('sha256', $sql), 'sql' => $sql];
        }
        ksort($files, SORT_NUMERIC);
        $expected = 1;
        foreach (array_keys($files) as $version) {
            if ($version !== $expected++) {
                throw new MigrationRefused('Migration version gap.');
            }
        }
        return $files;
    }

    /** @param array<int,array{name:string,checksum:string,sql:string}> $files */
    public static function validateHistory(array $files, array $rows): void
    {
        $highest = 0;
        foreach ($rows as $row) {
            $version = (int) $row['version'];
            if (!isset($files[$version]) || $files[$version]['checksum'] !== $row['checksum']
                || $files[$version]['name'] !== $row['name']) {
                throw new MigrationRefused(sprintf('V%04d checksum or file mismatch.', $version));
            }
            if ($row['state'] !== 'applied') {
                throw new MigrationRefused(sprintf('V%04d started; follow MANUAL RECOVERY.', $version));
            }
            if ($version <= $highest) {
                throw new MigrationRefused('Migration history order invalid.');
            }
            $highest = $version;
        }
        foreach ($files as $version => $_file) {
            if ($version <= $highest && !in_array($version, array_column($rows, 'version'), false)) {
                throw new MigrationRefused(sprintf('V%04d is unapplied below current version.', $version));
            }
        }
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = [];
        foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $end = str_ends_with($trimmed, ';');
            $body = $end ? substr($trimmed, 0, -1) : $trimmed;
            if (str_contains($body, ';')) {
                throw new MigrationRefused('Invalid migration statement delimiter.');
            }
            $buffer[] = $body;
            if ($end) {
                $statements[] = implode("\n", $buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            throw new MigrationRefused('Unterminated migration statement.');
        }
        return $statements;
    }

    private function checkServer(): void
    {
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->target) {
            throw new MigrationRefused('Connection target mismatch.');
        }
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/\A(\d+)\.(\d+)\./', $version, $match)
            || stripos($version, 'MariaDB') === false
            || (int) $match[1] < 10 || ((int) $match[1] === 10 && (int) $match[2] < 4)) {
            throw new MigrationRefused('MariaDB 10.4 or newer required.');
        }
        $collation = $this->pdo->prepare('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $collation->execute([$this->target]);
        if ($collation->fetchColumn() !== 'utf8mb4_nopad_bin') {
            throw new MigrationRefused('Database collation prerequisite failed.');
        }
        $available = $this->pdo->query("SHOW COLLATION LIKE 'utf8mb4_nopad_bin'")->fetch();
        if ($available === false) {
            throw new MigrationRefused('Required collation unavailable.');
        }
        $mode = (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $modes = explode(',', strtoupper($mode));
        if (!in_array('STRICT_ALL_TABLES', $modes, true)
            || in_array('NO_BACKSLASH_ESCAPES', $modes, true)
            || $this->pdo->query('SELECT @@SESSION.time_zone')->fetchColumn() !== '+00:00') {
            throw new MigrationRefused('Strict sql_mode required.');
        }
    }

    private function ensureTrackingTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version INT UNSIGNED NOT NULL PRIMARY KEY, '
            . 'name VARCHAR(100) NOT NULL, checksum CHAR(64) NOT NULL, '
            . "state VARCHAR(7) NOT NULL, statements_applied INT UNSIGNED NOT NULL, "
            . 'started_at DATETIME NOT NULL, finished_at DATETIME NULL, applied_by VARCHAR(80) NOT NULL, '
            . "CONSTRAINT ck_schema_migrations_checksum CHECK (checksum REGEXP '^[0-9a-f]{64}\\\\z'), "
            . "CONSTRAINT ck_schema_migrations_state CHECK (state IN ('started','applied'))"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin');
    }

    private function trackingRows(): array
    {
        return $this->pdo->query('SELECT version, name, checksum, state, statements_applied '
            . 'FROM schema_migrations ORDER BY version')->fetchAll();
    }
}
