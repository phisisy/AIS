<?php
declare(strict_types=1);

namespace App\Enrollment\Services;

use App\Auth\Models\User;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Enrollment\Models\Term;
use App\Enrollment\Repositories\CatalogRepositoryInterface;
use Closure;

final class TermService
{
    private const STAFF = ['registrar', 'admin'];
    private Closure $clock;

    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly AuthorizationService $authorization,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /** Authentication is enforced by the route guard; every signed-in user can read. */
    public function list(): array
    {
        return ['terms' => array_map(static fn (Term $term): array => $term->toArray(), $this->catalog->terms())];
    }

    public function create(User $actor, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->allowFields($input, ['code', 'name', 'start_date', 'end_date', 'is_current', 'enrollment_open']);
        $errors = [];
        foreach (['code', 'name', 'start_date', 'end_date'] as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'This field is required.';
            }
        }
        $this->throwIfErrors($errors);
        $fields = $this->fields($input, $errors);
        $this->throwIfErrors($errors);
        // Both dates are supplied on create; PATCH instead checks the merged row under the lock.
        self::checkDates($fields);
        $fields += ['is_current' => false, 'enrollment_open' => false];
        return $this->catalog->createTerm($fields, $this->now())->toArray();
    }

    public function update(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->allowFields($input, ['name', 'start_date', 'end_date', 'is_current', 'enrollment_open']);
        if ($input === []) {
            throw new HttpException(422, 'Validation failed.', ['body' => 'Provide at least one field to change.']);
        }
        $this->catalog->findTerm($id) ?? throw new HttpException(404, 'Term not found.');
        $errors = [];
        $changes = $this->fields($input, $errors);
        $this->throwIfErrors($errors);
        return $this->catalog->updateTerm($id, $changes, $this->now(), self::checkDates(...))->toArray();
    }

    /** Format validation only: no stored row is consulted here. */
    private function fields(array $input, array &$errors): array
    {
        $fields = [];
        if (array_key_exists('code', $input)) {
            $code = is_string($input['code']) ? strtoupper(trim($input['code'])) : '';
            if (preg_match('/\A[A-Z0-9][A-Z0-9-]{1,19}\z/', $code) !== 1) {
                $errors['code'] = 'Use 2 to 20 characters: A-Z, 0-9 and hyphen.';
            } else {
                $fields['code'] = $code;
            }
        }
        if (array_key_exists('name', $input)) {
            $name = is_string($input['name']) ? trim($input['name']) : null;
            if ($name === null || $name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 100
                || preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
                $errors['name'] = 'Use 1 to 100 characters without control characters.';
            } else {
                $fields['name'] = $name;
            }
        }
        foreach (['start_date', 'end_date'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $date = $input[$field];
            if (!is_string($date) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $errors[$field] = 'Use a real date in YYYY-MM-DD format.';
            } else {
                $fields[$field] = $date;
            }
        }
        foreach (['is_current', 'enrollment_open'] as $field) {
            if (array_key_exists($field, $input)) {
                is_bool($input[$field]) ? $fields[$field] = $input[$field] : $errors[$field] = 'Use true or false.';
            }
        }
        return $fields;
    }

    private static function checkDates(array $row): void
    {
        if ($row['start_date'] >= $row['end_date']) {
            throw new HttpException(422, 'Validation failed.', ['end_date' => 'The end date must be after the start date.']);
        }
    }

    private function allowFields(array $input, array $allowed): void
    {
        $this->throwIfErrors(array_fill_keys(array_diff(array_keys($input), $allowed), 'Unknown field.'));
    }

    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
    }
}
