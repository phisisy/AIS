<?php
declare(strict_types=1);

namespace App\Faculty\Repositories;

use App\Faculty\Models\Faculty;

interface FacultyRepositoryInterface
{
    public function findById(int $id): ?Faculty;

    public function findByUserId(int $userId): ?Faculty;

    /**
     * One read. @param list<int> $ids
     * @return array<int, Faculty> keyed by id; unknown ids omitted.
     */
    public function findByIds(array $ids): array;

    /**
     * $filters: search (employee number/first/last name, case-insensitive), status.
     * @return array{faculty: list<Faculty>, total: int} ordered by id.
     */
    public function search(array $filters, int $page, int $perPage): array;

    /**
     * One lock: 409 on duplicate user_id / employee_number (both reported together); appends the
     * status and department creation history entries in the same transaction.
     */
    public function create(array $fields, string $now, int $actorId): Faculty;

    /**
     * One lock: 404 if missing; $changes keys are a subset of first_name, last_name, department, status.
     * Appends one history entry per changed tracked field (status, department), same transaction.
     */
    public function update(int $id, array $changes, string $now, int $actorId): Faculty;
}
