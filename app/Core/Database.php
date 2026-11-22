<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /** T-60 OQ-6: strict, UTC; 7.1 OQ-E: 10 s lock wait (below Apache/PHP time limits) on every connection. */
    public const SESSION_INIT = "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,"
        . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone='+00:00', innodb_lock_wait_timeout=10";

    /** Create a connection only when explicitly requested by a repository. */
    public static function connect(array $config): PDO
    {
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key])) {
                throw new RuntimeException('Invalid database configuration.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $config['host'])
            || !preg_match('/\A[A-Za-z0-9_]+\z/D', $config['database'])
            || !ctype_digit($config['port']) || (int) $config['port'] < 1
            || (int) $config['port'] > 65535 || $config['username'] === '') {
            throw new RuntimeException('Invalid database configuration.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Database driver unavailable.');
        }
        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port']
            . ';dbname=' . $config['database'] . ';charset=utf8mb4';
        try {
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => self::SESSION_INIT,
            ]);
        } catch (PDOException) {
            // Deliberately omit the original exception: it can contain credentials.
            throw new RuntimeException('Database connection failed.');
        }
    }
}
