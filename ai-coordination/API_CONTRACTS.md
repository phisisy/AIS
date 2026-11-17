# API & Module Contracts (PROPOSED)

Status: **PROPOSED**. Endpoints follow the teams' master development prompts and the Teams 2–5 frontend
guide. Field lists describe what the API exposes. They are **not** final database tables (Team 6 owns those).

Legend: 🔒 = signed-in required · CSRF = `X-CSRF-Token` required · Roles = allowlist (any match; no hierarchy).

## 1. Identity model (Team 2 → everyone)

```
User (public): { id: int, email: string, roles: string[], status: "active"|"inactive" }
Roles: student | faculty | registrar | admin
```

- `User.id` is the **account** ID. It is never the same thing as a student ID or faculty ID.
- Team 3 maps account → student profile (`student.user_id`); Team 5 maps account → faculty profile (`faculty.user_id`).
- A role alone grants module access, **not** access to a specific record. Ownership is decided by the owning module.
- Login identifier: **email**, trimmed and lowercased (B-06, approved by owner 2026-11-17).

## 2. HTTP endpoints

### Team 2 — Auth & Administration

| Method | Path | Auth | Roles | CSRF | Purpose |
|---|---|---|---|---|---|
| GET | /api/auth/csrf | — | — | — | Get CSRF token (starts anonymous session) |
| POST | /api/auth/login | — | — | ✔ | `{email,password}` → `{user, csrf_token}`; same 401 for every failure; 429 when throttled |
| POST | /api/auth/logout | 🔒 | any | ✔ | Destroy session |
| GET | /api/auth/me | 🔒 | any | — | Current user, reloaded from storage |
| GET | /api/admin/dashboard | 🔒 | admin | — | Totals, active/inactive, counts per role |
| GET | /api/admin/users | 🔒 | admin | — | `?search=&page=&per_page=` (≤100) |
| POST | /api/admin/users | 🔒 | admin | ✔ | `{email,password,roles[],status?}` → 201 |
| GET | /api/admin/users/{id} | 🔒 | admin | — | One user |
| PATCH | /api/admin/users/{id} | 🔒 | admin | ✔ | `{email?,roles?}`; 409 on duplicate email or last-admin removal |
| PATCH | /api/admin/users/{id}/status | 🔒 | admin | ✔ | `{status}`; 409 on deactivating last active admin |
| POST | /api/admin/users/{id}/reset-password | 🔒 | admin | ✔ | `{password,current_password}` (admin re-confirms) |
| GET | /api/admin/audit-logs | 🔒 | admin | — | `?page=&action=` read-only ⚠ (new; not in master prompt) |

### Team 3 — Students & Academic Records

| Method | Path | Auth | Roles | CSRF | Ownership rule |
|---|---|---|---|---|---|
| GET | /api/students | 🔒 | registrar, admin | — | Search by name / student number |
| POST | /api/students | 🔒 | registrar, admin | ✔ | 409 duplicate `student_number` or `user_id` already linked |
| GET | /api/students/me | 🔒 | student | — | Profile linked to session user; 404 if none |
| GET | /api/students/{id} | 🔒 | registrar, admin, student, faculty | — | Student: own only (else 404). Faculty: only students on their roster (else 403) ⚠ (B-07) |
| PATCH | /api/students/{id} | 🔒 | registrar, admin | ✔ | `student_number` and `user_id` immutable after create ⚠ |
| GET | /api/students/{id}/academic-record | 🔒 | registrar, admin, student | — | Student: own only |

Student (API shape): `{ id, student_number, user_id, first_name, last_name, middle_name?, email?, program_code, year_level (1–6), academic_status: "regular"|"irregular"|"on_leave"|"graduated"|"withdrawn", admission_year }`
Academic record: `{ student_id, program_code, program_name, year_level, academic_status, current_term_id? }`. No grades or enrollment copies.

### Team 4 — Enrollment & Scheduling

| Method | Path | Auth | Roles | CSRF | Rule |
|---|---|---|---|---|---|
| GET | /api/terms | 🔒 | any | — | List academic terms; one `is_current` |
| GET | /api/sections | 🔒 | any | — | `?term_id=&course=&search=`; includes capacity, remaining slots, meetings, instructor |
| GET | /api/sections/{id} | 🔒 | any | — | Section detail |
| POST | /api/registrar/sections | 🔒 | registrar, admin | ✔ | Create section + meetings |
| PATCH | /api/registrar/sections/{id} | 🔒 | registrar, admin | ✔ | Capacity can't go below approved count (409) |
| POST | /api/enrollments | 🔒 | student | ✔ | `{section_id}` only; student resolved from session |
| GET | /api/enrollments/me | 🔒 | student | — | Own requests, all statuses |
| GET | /api/schedules/me | 🔒 | student | — | Approved enrollments for `?term_id=` (default current) |
| GET | /api/registrar/enrollments | 🔒 | registrar, admin | — | `?status=&term_id=&section_id=` |
| PATCH | /api/registrar/enrollments/{id} | 🔒 | registrar, admin | ✔ | `{status:"approved"|"rejected", reason?}` |

Enrollment rules (checked in the service, inside one transaction):
1. Student profile must exist and `academic_status` must allow enrollment (regular/irregular).
2. Section must be in an open term ⚠ (B-08).
3. No existing `pending` or `approved` enrollment for the same student + section → else 409.
4. Approved count < capacity → else 409 (checked again on approval).
5. No time overlap with the student's `pending`/`approved` meetings in the same term → else 409 with the clashing section.
6. Status flow: `pending → approved | rejected`; `approved/rejected` are final in MVP ⚠. Students cannot approve.

Section: `{ id, term_id, course_code, course_title, units, section_code, capacity, approved_count, remaining, meetings:[{day:"MON".."SUN", start:"HH:MM", end:"HH:MM", room}], instructors:[{faculty_id,name}] }`
Enrollment: `{ id, student_id, section_id, term_id, status, requested_at, decided_at?, decided_by?, reason? }`

### Team 5 — Faculty & Grades

| Method | Path | Auth | Roles | CSRF | Rule |
|---|---|---|---|---|---|
| GET | /api/faculty/me | 🔒 | faculty | — | Profile linked to session user |
| GET | /api/faculty/classes | 🔒 | faculty | — | Own teaching assignments `?term_id=` |
| GET | /api/faculty/classes/{id}/students | 🔒 | faculty | — | Must be assigned to section `{id}`; else 403. `?search=` |
| GET | /api/faculty/classes/{id}/grades | 🔒 | faculty | — | Assigned only |
| PATCH | /api/faculty/grades/{id} | 🔒 | faculty | ✔ | Only `draft` grades of own class; validated value |
| POST | /api/faculty/classes/{id}/submit-grades | 🔒 | faculty | ✔ | All roster students graded, then draft → `submitted` |
| GET | /api/grades/me | 🔒 | student | — | Own **finalized** grades only |
| GET | /api/registrar/grades | 🔒 | registrar, admin | — | `?section_id=&status=submitted` |
| PATCH | /api/registrar/grades/sections/{id}/finalize | 🔒 | registrar, admin | ✔ | submitted → finalized ⚠ (B-05) |
| POST | /api/registrar/teaching-assignments | 🔒 | registrar, admin | ✔ | `{faculty_id, section_id}` ⚠ (B-04) |

`{id}` under `/api/faculty/classes/` is the **section ID**.
Grade: `{ id, section_id, student_id, value, remarks?, status: "draft"|"submitted"|"finalized", updated_at }`
Grade value scale ⚠ (B-05). Proposed default: `1.00, 1.25, …, 3.00, 5.00` plus `INC` / `DRP`.
Status flow: `draft → submitted → finalized`; no edits after `submitted` in MVP.

## 3. In-process module interfaces (PHP)

Providers own the interface and both implementations. Consumers type-hint the interface only.

```php
// Team 2 — App\Auth\Services
AuthService::requireUser(): User                          // throws 401
AuthorizationService::requireRole(User $u, array $roles): void   // throws 401 inactive / 403

// Team 3 — App\Students\Contracts\StudentDirectory
findById(int $studentId): ?StudentSummary
findByUserId(int $userId): ?StudentSummary
// StudentSummary: id, student_number, user_id, full_name, program_code, year_level, academic_status

// Team 4 — App\Enrollment\Contracts\SectionDirectory
findSection(int $sectionId): ?SectionSummary              // id, term_id, course_code, section_code
approvedStudentIds(int $sectionId): int[]                 // the roster source for Team 5
// Team 4 — App\Enrollment\Contracts\InstructorLookup  (implemented by Team 5)
instructorsForSection(int $sectionId): array              // [{faculty_id, name}]

// Team 5 — App\Faculty\Contracts\FacultyDirectory
findByUserId(int $userId): ?FacultySummary
isAssigned(int $facultyId, int $sectionId): bool
sectionIdsForFaculty(int $facultyId): int[]               // used by Team 3 for faculty roster access
// Team 5 — App\Faculty\Contracts\GradeReader
finalizedGradesForStudent(int $studentId): array          // read-only, finalized only
```

## 4. Team pair contracts (summary)

| Pair | Contract |
|---|---|
| 2 ↔ 3 | Team 3 gets the user via `requireUser()`, maps `User.id → student.user_id`. Creating a student profile requires an existing active account with role `student` (checked through a Team 2 read interface ⚠). Team 3 never writes users/roles. |
| 2 ↔ 4 | All enrollment routes use Team 2 middleware. Students act only on their own profile, resolved from the session. Registrar/admin roles gate approval. |
| 2 ↔ 5 | `User.id → faculty.user_id`. Faculty role + teaching assignment = access to a class. Role alone = nothing. |
| 3 ↔ 4 | Enrollment stores `student_id` (Team 3 ID), checks existence + status via `StudentDirectory`. Team 3 stores no enrollment data. |
| 4 ↔ 5 | Rosters = `SectionDirectory::approvedStudentIds`. Team 5 owns teaching assignments; Team 4 shows instructors via `InstructorLookup`. |
| 3 ↔ 5 | Grades reference `student_id`. Team 5 copies no profile data and serves names via `StudentDirectory`. Team 3's academic record does not embed grades in MVP. |
