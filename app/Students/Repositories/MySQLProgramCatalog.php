<?php
declare(strict_types=1);

namespace App\Students\Repositories;

use App\Students\Contracts\ProgramCatalogInterface;
use App\Students\Models\Program;
use PDO;
use PDOException;
use RuntimeException;

/** Read-only catalog over a caller-supplied connection. */
final class MySQLProgramCatalog implements ProgramCatalogInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $code): ?Program
    {
        try {
            $statement = $this->pdo->prepare('SELECT code, name, max_year_level FROM programs WHERE code = ?');
            $statement->execute([$code]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : self::program($row);
        } catch (PDOException) {
            throw new RuntimeException('Program catalog unavailable.');
        }
    }

    public function all(): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT code, name, max_year_level FROM programs ORDER BY code');
            $statement->execute();
            return array_map(self::program(...), $statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException) {
            throw new RuntimeException('Program catalog unavailable.');
        }
    }

    private static function program(array $row): Program
    {
        return Program::fromArray(['code' => $row['code'], 'name' => $row['name'],
            'max_year_level' => (int) $row['max_year_level']]);
    }
}
