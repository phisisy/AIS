# T-10 API contract — Student Records (Team 3)

Status: **FINAL for T-10** (owner decisions 2026-11-17). Supersedes the Team 3 rows in `API_CONTRACTS.md` §2 where they differ.
Storage: file-backed mock (`runtime/students/students.json`) until Team 6's schema is final. Field names are API names, **not** final database columns.

## 1. Conventions
- JSON envelopes, status codes and CSRF as in `ARCHITECTURE.md` §5. Unknown body fields → 422.
- Every route is wrapped by `$guard->roles([...])` (T-02). Writes require `X-CSRF-Token`.
- IDs: `id` is the Team 3 student id. `user_id` is the Team 2 account id. They are different numbers.
- No automatic superuser: `admin` can do only what each route's allowlist names.

## 2. Routes

| Method | Path | Guard roles | Server rule |
|---|---|---|---|
| GET | `/api/students` | registrar, admin | Paginated list and search |
| POST | `/api/students` | registrar, admin | Create a profile for an existing `student` account |
| GET | `/api/students/me` | student | The caller's own profile; 404 if none |
| GET | `/api/students/{id}` | registrar, admin, student, faculty | See §4. Anything not permitted → **404** (same body as a missing record) |
| PATCH | `/api/students/{id}` | registrar, admin | Update academic profile fields |

No delete endpoint. Students and faculty cannot modify profiles.

## 3. Request validation

### POST `/api/students`
| Field | Required | Rule |
|---|---|---|
| `user_id` | yes | Positive int. The account must exist, be `active` and hold the `student` role (Team 2 lookup). One profile per account → 409 |
| `student_number` | yes | Trimmed and uppercased, then matched against the **configured** institutional pattern (§5). Unique → 409. Never changeable after creation |
| `first_name`, `last_name` | yes | String, trimmed, 1–100 characters, no control characters |
| `middle_name` | no | Same rule, or `null` |
| `program_code` | yes | Must exist in the program catalog (§6) |
| `year_level` | yes | Int, 1 to the program's `max_year_level` |
| `academic_status` | no (default `active`) | `active`, `inactive`, `graduated`, `transferred` or `on_leave`. Separate from the account's `status` |
| `admission_year` | no | Int 1900 to (current year + 1), or `null` |

### PATCH `/api/students/{id}`
Any non-empty subset of `first_name`, `middle_name`, `last_name`, `program_code`, `year_level`, `academic_status`, `admission_year`, with the same rules. `user_id` and `student_number` are **not accepted** (422 unknown field). A `year_level` change is validated against the program that applies after the update.

### GET `/api/students` query
`search` (≤ 100 characters; matches student number, first or last name, case-insensitive), `program_code`, `academic_status`, `page` (1–1e6), `per_page` (1–100). Invalid → 422.

## 4. Access rules for `GET /api/students/{id}` (server-side, in this order)
1. **registrar or admin:** full profile.
2. **The record's own account** (`student.user_id === session user id`): full profile.
3. **faculty:** the summary (§7), only if the faculty visibility source (§8) confirms the student is enrolled in at least one section officially assigned to this faculty user. Otherwise 404.
4. **Anyone else:** 404 `{"success":false,"message":"Student not found.","errors":{}}`. A nonexistent id returns exactly the same.

## 5. Student number format (configurable)
- `STUDENT_NUMBER_PATTERN` in `.env` is a PCRE body without delimiters or anchors, wrapped as `\A(?:…)\z`. Default `[A-Z0-9][A-Z0-9-]{2,19}`: 3–20 uppercase letters, digits or hyphens. This deliberately assumes nothing about the school's final policy.
- Whatever the pattern, values must also be 1–32 characters from `[A-Z0-9-/]` after uppercasing.
- An invalid configured pattern fails at startup (generic 500), never silently accepting everything.

## 6. Program catalog interface (temporary, Team 3)
```php
interface ProgramCatalogInterface {
    public function find(string $code): ?Program;   // code: uppercase
    /** @return list<Program> */ public function all(): array;
}
// Program: code (string), name (string), max_year_level (int 1–8)
```
- The development implementation reads `runtime/students/programs.json`, written by `database/seeders/dev_students.php`. There is no HTTP endpoint for programs in T-10 (T-11 adds `GET /api/programs`).
- Team 6 owns the final schema. No relationships are hardcoded beyond "a profile references a program code".

## 7. Response formats

**Full profile** (registrar, admin, or own record):
```json
{"id":1,"user_id":4,"student_number":"2026-00001","first_name":"Ana","middle_name":null,"last_name":"Reyes",
 "program_code":"BSCS","year_level":2,"academic_status":"active","admission_year":2025,
 "created_at":"2026-11-17T10:00:00Z","updated_at":"2026-11-17T10:00:00Z"}
```
List: `{"students":[…full…],"pagination":{"page":1,"per_page":20,"total":1}}`.

**Faculty summary** (only via §4 rule 3):
```json
{"id":1,"student_number":"2026-00001","full_name":"Reyes, Ana","program_code":"BSCS","year_level":2,
 "sections":[{"section_id":12,"section_code":"CS201-A","enrollment_status":"approved"}]}
```
Never sent to faculty: `user_id`, `academic_status`, `admission_year`, timestamps, or any student outside their shared sections. Contact, address and financial data don't exist in T-10.

## 8. Dependencies on Teams 4 and 5
```php
interface FacultyStudentVisibility {
    /**
     * Sections officially assigned to $facultyUserId in which $studentId is enrolled.
     * [] means not visible. Must return only verified assignments.
     * @return list<array{section_id:int, section_code:string, enrollment_status:string}>
     */
    public function sharedSections(int $facultyUserId, int $studentId): array;
}
```
- **T-10 ships `DenyAllFacultyVisibility`, which always returns `[]`.** No class assignments are invented, so faculty receive 404 for every student.
- If a future implementation throws, Team 3 treats it as not visible (404) and logs only the exception class.
- The real implementation is wired in T-31, using Team 5's teaching assignments and Team 4's approved enrollments.

## 9. Errors
| Status | When |
|---|---|
| 401 / 403 | Guard (T-02): not signed in / role not in the allowlist / CSRF |
| 404 | Missing **or** not visible to the caller (identical body) |
| 409 | `student_number` taken, account already has a profile |
| 422 | Validation (field-keyed `errors`), unknown fields, account missing/inactive/not a student, unknown program |
| 500 | Storage unreadable or corrupt, invalid configured pattern (generic body only) |

## 10. Not in T-10
Academic record endpoint, `GET /api/programs` and `StudentDirectory` (T-11); grades (Team 5); enrollment (Team 4); credentials (Team 2); deletion; student-records audit log (not requested for T-10; it can follow the T-03 pattern later).
