<?php
declare(strict_types=1);

namespace App\Students\Repositories;

use App\Students\Models\Student;

interface StudentRepositoryInterface
{
    public function findById(int $id): ?Student;

    public function findByUserId(int $userId): ?Student;

    /**
     * $filters: search (number/first/last name, case-insensitive), program_code, academic_status.
     * @return array{students: list<Student>, total: int} ordered by id.
     */
    public function search(array $filters, int $page, int $perPage): array;

    /**
     * Atomically assigns id and timestamps. Uniqueness of user_id and student_number is checked
     * under the same exclusive lock as the write (409 on conflict).
     */
    public function create(array $fields, string $now, int $actorId = 0): Student;

    /**
     * Academic change history (program_code, year_level, academic_status), oldest first, never
     * truncated. Each entry: seq, at, field, from, to, actor_id (0 = system/seeder).
     * @return array{entries: list<array>, total: int}
     */
    public function history(int $studentId, int $page, int $perPage): array;

    /**
     * Atomic read-modify-write of the given fields; 404 if missing. user_id/student_number are immutable.
     * $check(array $mergedRow) runs inside the lock before writing; throwing aborts with nothing written.
     */
    public function update(int $id, array $changes, string $now, ?callable $check = null, int $actorId = 0): Student;
}
