<?php
declare(strict_types=1);

namespace App\Faculty\Repositories;

use App\Core\HttpException;
use App\Enrollment\Repositories\CatalogRepositoryInterface;
use App\Faculty\Models\Grade;
use App\Faculty\Services\GradeScale;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * T-32 grade adapter on a caller-supplied connection (V0008). Semantics are those of GradeRepositoryInterface and MockGradeRepository.
 * Writers: BEGIN, `grades` repository lock FOR UPDATE, work (every read in a writer is a locking read, so it sees the latest
 * committed rows even when the transaction snapshot is older), gap-free history seq from last_seq, UPDATE last_seq, COMMIT.
 * finalizeSection runs inside CatalogRepositoryInterface::withEnrollmentLock (enrollment lock first) and JOINS that transaction.
 * UNVERIFIED: written without execution.
 */
final class MySQLGradeRepository implements GradeRepositoryInterface
{
    private const COLUMNS = 'g.id, g.section_id, g.student_id, g.value, g.remarks, g.status, g.created_at, g.updated_at';
    private const WITH_SEQ = 'SELECT ' . self::COLUMNS . ', (SELECT MAX(h.seq) FROM grade_history h WHERE h.grade_id = g.id) AS seq FROM grades g';
    private const UNAVAILABLE = 'Grade repository unavailable.';

    /** Next-to-use history seq state of the current locked section (valid only inside locked()). */
    private int $seq = 0;

    public function __construct(private readonly PDO $pdo, private readonly CatalogRepositoryInterface $catalog)
    {
    }

    public function findGrade(int $id): ?Grade
    {
        return $this->read(function () use ($id): ?Grade {
            $row = $this->execute(self::WITH_SEQ . ' WHERE g.id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : self::fromRow($row);
        });
    }

    public function forSection(int $sectionId): array
    {
        return $this->read(fn (): array => array_map(self::fromRow(...),
            $this->execute(self::WITH_SEQ . ' WHERE g.section_id = ? ORDER BY g.student_id', [$sectionId])->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function finalizedForStudent(int $studentId): array
    {
        return $this->read(fn (): array => array_map(self::fromRow(...),
            $this->execute(self::WITH_SEQ . " WHERE g.student_id = ? AND g.status = 'finalized' ORDER BY g.section_id, g.id", [$studentId])->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function saveDraft(int $sectionId, int $studentId, string $value, ?string $remarks, string $now, int $actorId): array
    {
        self::requireDemoValue($value); // parity with MockGradeRepository: refused before any lock, read or write
        // L2: same fixed lock order as finalizeSection (enrollment, roster re-read under it, then grades); a student off the roster is refused.
        return $this->catalog->withEnrollmentLock($sectionId, fn (array $roster): array => $this->joined(function () use ($sectionId, $studentId, $value, $remarks, $now, $actorId, $roster): array {
            if (!in_array($studentId, $roster, true)) {
                throw new HttpException(404, 'Student not found in this class.');
            }
            $stored = $this->storedRows('WHERE g.section_id = ? AND g.student_id = ?', [$sectionId, $studentId]);
            if ($stored === []) {
                $row = ['id' => 1, 'section_id' => $sectionId, 'student_id' => $studentId, 'value' => $value,
                    'remarks' => $remarks, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now];
                Grade::fromArray($row);
                $this->execute('INSERT INTO grades (section_id, student_id, value, remarks, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$sectionId, $studentId, $value, $remarks, 'draft', self::sqlTime($now), self::sqlTime($now)]);
                $row['id'] = (int) $this->pdo->lastInsertId();
                $seq = $this->append($now, 'entered', $row, null, $value, null, $remarks, null, $actorId);
                return ['grade' => Grade::fromArray($row, $seq), 'created' => true];
            }
            $row = $stored[0];
            if ($row['status'] !== 'draft') {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Submitted or finalized grades cannot be edited.']);
            }
            $old = $row['value'];
            $oldRemarks = $row['remarks'];
            $row = array_replace($row, ['value' => $value, 'remarks' => $remarks, 'updated_at' => self::notBefore($now, $row['created_at'])]);
            Grade::fromArray($row);
            $this->execute('UPDATE grades SET value = ?, remarks = ?, updated_at = ? WHERE id = ?', [$value, $remarks, self::sqlTime($row['updated_at']), $row['id']]);
            // L3: every save (including a remarks-only change) is one 'updated' row carrying old/new remarks.
            return ['grade' => Grade::fromArray($row, $this->append($now, 'updated', $row, $old, $value, $oldRemarks, $remarks, null, $actorId)), 'created' => false];
        }));
    }

    public function submitSection(int $sectionId, array $rosterIds, string $now, int $actorId): array
    {
        return $this->write(function () use ($sectionId, $rosterIds, $now, $actorId): array {
            $rows = $this->storedRows('WHERE g.section_id = ?', [$sectionId]);
            $present = array_flip(array_column($rows, 'student_id'));
            $missing = array_values(array_filter($rosterIds, static fn (int $id): bool => !isset($present[$id])));
            if ($missing !== []) {
                throw new HttpException(409, 'Validation failed.', ['grades_missing' => $missing]);
            }
            $changed = $this->transition($rows, 'draft', 'submitted', 'submitted', $now, $actorId, null, $rosterIds);
            if ($changed === []) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'There are no draft grades to submit.']);
            }
            return $changed;
        });
    }

    public function returnSection(int $sectionId, string $reason, string $now, int $actorId, ?int $ownStudentId = null): array
    {
        return $this->write(function () use ($sectionId, $reason, $now, $actorId, $ownStudentId): array {
            $this->requireNotEntrant($sectionId, $actorId);
            $rows = $this->storedRows('WHERE g.section_id = ?', [$sectionId]);
            if ($ownStudentId !== null && in_array($ownStudentId, array_column($rows, 'student_id'), true)) { // L1
                throw new HttpException(403, 'Access denied.');
            }
            $changed = $this->transition($rows, 'submitted', 'draft', 'returned', $now, $actorId, $reason, null);
            if ($changed === []) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'There are no submitted grades to return.']);
            }
            return $changed;
        });
    }

    public function finalizeSection(int $sectionId, string $now, int $actorId, ?int $ownStudentId = null): array
    {
        // Fixed lock order: enrollment (roster re-read under it), then grades, in the transaction withEnrollmentLock opened.
        return $this->catalog->withEnrollmentLock($sectionId, fn (array $roster): array => $this->joined(function () use ($sectionId, $roster, $now, $actorId, $ownStudentId): array {
            $this->requireNotEntrant($sectionId, $actorId);
            $rows = $this->storedRows('WHERE g.section_id = ?', [$sectionId]);
            if ($ownStudentId !== null && (in_array($ownStudentId, $roster, true) || in_array($ownStudentId, array_column($rows, 'student_id'), true))) {
                throw new HttpException(403, 'Access denied.');
            }
            $status = array_column($rows, 'status', 'student_id');
            $missing = array_values(array_filter($roster, static fn (int $id): bool => !isset($status[$id]) || $status[$id] === 'draft'));
            if ($missing !== []) {
                throw new HttpException(409, 'Validation failed.', ['grades_missing' => $missing]);
            }
            $changed = $this->transition($rows, 'submitted', 'finalized', 'finalized', $now, $actorId, null, null);
            if ($changed === []) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'There are no submitted grades to finalize.']);
            }
            return $changed;
        }));
    }

    public function correct(int $id, string $value, string $reason, int $expectedSeq, string $now, int $actorId): Grade
    {
        self::requireDemoValue($value); // parity with MockGradeRepository: refused before any lock, read or write
        return $this->write(function () use ($id, $value, $reason, $expectedSeq, $now, $actorId): Grade {
            $stored = $this->storedRows('WHERE g.id = ?', [$id]);
            if ($stored === []) {
                throw new HttpException(404, 'Grade not found.');
            }
            $row = $stored[0];
            if ($row['status'] !== 'finalized') {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Only finalized grades can be corrected.']);
            }
            $this->requireNotEntrant($row['section_id'], $actorId);
            $latest = (int) $this->execute('SELECT seq FROM grade_history WHERE grade_id = ? ORDER BY seq DESC LIMIT 1 FOR UPDATE', [$id])->fetchColumn();
            if ($latest !== $expectedSeq) {
                throw new HttpException(409, 'Validation failed.', ['stale_grade' => 'This grade changed since you loaded it. Reload and try again.']);
            }
            if ($row['value'] === $value) {
                throw new HttpException(409, 'Validation failed.', ['value' => 'The new value must differ from the current value.']);
            }
            $old = $row['value'];
            $row = array_replace($row, ['value' => $value, 'updated_at' => self::notBefore($now, $row['created_at'])]);
            Grade::fromArray($row);
            $this->execute('UPDATE grades SET value = ?, updated_at = ? WHERE id = ?', [$value, self::sqlTime($row['updated_at']), $id]);
            return Grade::fromArray($row, $this->append($now, 'corrected', $row, $old, $value, $row['remarks'], $row['remarks'], $reason, $actorId));
        });
    }

    /** SoD (b): the actor entered, updated or submitted grades in this section. */
    private function requireNotEntrant(int $sectionId, int $actorId): void
    {
        $hit = $this->execute("SELECT 1 FROM grade_history WHERE section_id = ? AND actor_id = ? AND action IN ('entered', 'updated', 'submitted') LIMIT 1 FOR UPDATE",
            [$sectionId, $actorId])->fetchColumn();
        if ($hit !== false) {
            throw new HttpException(403, 'Access denied.');
        }
    }

    /**
     * Moves every given row from $from to $to (optionally only $onlyStudents), one history row each, in id order.
     * @param list<array> $rows stored rows @param ?list<int> $onlyStudents @return list<Grade>
     */
    private function transition(array $rows, string $from, string $to, string $action, string $now, int $actorId, ?string $reason, ?array $onlyStudents): array
    {
        $changed = [];
        foreach ($rows as $row) {
            if ($row['status'] !== $from || ($onlyStudents !== null && !in_array($row['student_id'], $onlyStudents, true))) {
                continue;
            }
            $row = array_replace($row, ['status' => $to, 'updated_at' => self::notBefore($now, $row['created_at'])]);
            $this->execute('UPDATE grades SET status = ?, updated_at = ? WHERE id = ?', [$to, self::sqlTime($row['updated_at']), $row['id']]);
            $changed[] = Grade::fromArray($row, $this->append($now, $action, $row, null, null, null, null, $reason, $actorId));
        }
        return $changed;
    }

    /** @return int the new history seq (gap-free: last_seq + 1, persisted by locked()) */
    private function append(string $at, string $action, array $grade, ?string $old, ?string $new, ?string $oldRemarks, ?string $newRemarks, ?string $reason, int $actorId): int
    {
        $seq = ++$this->seq;
        $this->execute('INSERT INTO grade_history (seq, at, action, grade_id, section_id, student_id, old_value, new_value, old_remarks, new_remarks, reason, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$seq, self::sqlTime($at), $action, $grade['id'], $grade['section_id'], $grade['student_id'], $old, $new, $oldRemarks, $newRemarks, $reason, $actorId]);
        return $seq;
    }

    /** Locking read (sees the latest committed rows) of validated stored rows, ordered by id. @return list<array> */
    private function storedRows(string $where, array $params): array
    {
        $rows = [];
        foreach ($this->execute('SELECT ' . self::COLUMNS . ' FROM grades g ' . $where . ' ORDER BY g.id FOR UPDATE', $params)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = self::storedRow($r);
            Grade::fromArray($row);
            $rows[] = $row;
        }
        return $rows;
    }

    private static function storedRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'section_id' => (int) $r['section_id'], 'student_id' => (int) $r['student_id'],
            'value' => $r['value'], 'remarks' => $r['remarks'], 'status' => $r['status'],
            'created_at' => self::exactIsoTime($r['created_at']), 'updated_at' => self::exactIsoTime($r['updated_at'])];
    }

    private static function fromRow(array $r): Grade
    {
        if ($r['seq'] === null) {
            throw new RuntimeException('Invalid stored grade.'); // every grade has at least its 'entered' history row
        }
        return Grade::fromArray(self::storedRow($r), (int) $r['seq']);
    }

    /** The V0010 value domain (ck_grades_value) as the same 422 the mock gives, instead of a model/CHECK failure. */
    private static function requireDemoValue(string $value): void
    {
        if (!GradeScale::demo()->allows($value)) {
            throw new HttpException(422, 'Validation failed.', ['value' => 'Use a value from the grading scale.']);
        }
    }

    /** Clock regressions never violate ck_grades_order: updated_at is never earlier than created_at. */
    private static function notBefore(string $now, string $createdAt): string
    {
        return max($now, $createdAt);
    }

    private static function sqlTime(string $time): string
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $time)) {
            throw new RuntimeException('Invalid grade timestamp.');
        }
        return substr($time, 0, 10) . ' ' . substr($time, 11, 8);
    }

    /** Exact calendar round trip; zero and impossible dates fail closed with a generic, unchained error (as T-22 exactIsoTime). */
    private static function exactIsoTime(mixed $time): string
    {
        $parsed = is_string($time) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new \DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $time || $parsed->format('Y') === '0000') {
            throw new RuntimeException('Invalid stored grade.');
        }
        return str_replace(' ', 'T', $time) . 'Z';
    }

    private function execute(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** Takes the `grades` lock, runs $operation, persists last_seq. The caller owns the transaction. */
    private function locked(callable $operation): mixed
    {
        $last = $this->execute('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE', ['grades'])->fetchColumn();
        if ($last === false) {
            throw new RuntimeException('Grade repository lock missing.');
        }
        $this->seq = $start = (int) $last;
        $result = $operation();
        if ($this->seq !== $start) {
            $this->execute('UPDATE repository_locks SET last_seq = ? WHERE name = ?', [$this->seq, 'grades']);
        }
        return $result;
    }

    private function write(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Nested store operations are not supported.');
        }
        try {
            $this->pdo->beginTransaction();
            $result = $this->locked($operation);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof PDOException) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            throw $error;
        }
    }

    /** finalize and saveDraft: joins the transaction withEnrollmentLock opened (never begins or commits; that wrapper rolls back on any throw). */
    private function joined(callable $operation): mixed
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Grade finalize requires the enrollment lock transaction.');
        }
        try {
            return $this->locked($operation);
        } catch (PDOException) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
    }

    private function read(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
        }
    }
}
