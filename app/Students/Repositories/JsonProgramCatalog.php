<?php
declare(strict_types=1);

namespace App\Students\Repositories;

use App\Core\JsonStore;
use App\Students\Contracts\ProgramCatalogInterface;
use App\Students\Models\Program;
use RuntimeException;

/** Development program catalog (runtime/students/programs.json), written only by the dev seeder or tests. */
final class JsonProgramCatalog implements ProgramCatalogInterface
{
    public function __construct(private readonly JsonStore $store)
    {
    }

    public function find(string $code): ?Program
    {
        foreach ($this->all() as $program) {
            if ($program->code === $code) {
                return $program;
            }
        }
        return null;
    }

    public function all(): array
    {
        $data = $this->store->read();
        if ($data === []) {
            return [];
        }
        if (!isset($data['programs']) || !is_array($data['programs']) || !array_is_list($data['programs'])) {
            throw new RuntimeException('Invalid program catalog.');
        }
        return array_map(static fn (mixed $row): Program => Program::fromArray($row), $data['programs']);
    }

    /** Seeder/test helper. Refuses to overwrite an existing catalog. */
    public function seed(array $programs): void
    {
        $rows = array_map(static fn (mixed $row): array => Program::fromArray($row)->toArray(), $programs);
        $this->store->transaction(static function (array &$data) use ($rows): void {
            if ($data !== []) {
                throw new RuntimeException('Program catalog is already initialized.');
            }
            $data = ['programs' => $rows];
        });
    }
}
