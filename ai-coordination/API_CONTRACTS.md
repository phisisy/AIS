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
| GET | /api/students/{id}/academic-record | 🔒 | registrar, admin, student | — | Student: own only, while holding `student` (else 404, same as missing). Faculty: 403 |
| GET | /api/students/me/academic-record | 🔒 | student | — | Own record; 404 if no profile |
| GET | /api/programs | 🔒 | any | — | Read-only program catalog `{code, name, max_year_level}` |

> **C-03 (T-60, 2026-11-21) — UNRESOLVED, no behaviour change.**
> - The implemented Student model (`app/Students/Models/Student.php`, `API_CONTRACT_T10_STUDENTS.md`) uses `academic_status` ∈ {active, inactive, graduated, transferred, on_leave}, a year level up to the program maximum, and no `email`. The shape below is the earlier sketch.
> - The database follows the implemented model.
> - Enrollment rule 1 ("regular/irregular") stays open **until T-22 defines enrollment eligibility and student-status semantics** (owner safeguard S4).

Student (API shape): `{ id, student_number, user_id, first_name, last_name, middle_name?, email?, program_code, year_level (1–6), academic_status: "regular"|"irregular"|"on_leave"|"graduated"|"withdrawn", admission_year }`
Academic record (T-11a, implemented; supersedes the earlier sketch): `{ student{id, student_number, full_name, program_code, program_name, year_level, academic_status, admission_year}, history{entries[{seq, at, field, from, to, actor_id (staff only)}], pagination{page, per_page, total}}, enrollment_summary, grades_summary }`. History is paginated (`?page=`, `?per_page=` ≤ 100) and has no cap (owner decision D4). The summaries are `{available:false}` until Teams 4 and 5 provide sources. No grades or enrollment copies.

### Team 4 — Enrollment & Scheduling

| Method | Path | Auth | Roles | CSRF | Rule |
|---|---|---|---|---|---|
| GET | /api/terms | 🔒 | any | — | List academic terms; at most one `is_current` (T-20) |
| POST | /api/registrar/terms | 🔒 | registrar, admin | ✔ | Create term; 409 duplicate `code` (T-20) |
| PATCH | /api/registrar/terms/{id} | 🔒 | registrar, admin | ✔ | `code` immutable; `is_current:true` clears others atomically (T-20) |
| GET | /api/sections | 🔒 | any | — | `?term_id=&course=&search=&page=&per_page=`; includes capacity, remaining slots, meetings; instructors from T-21 |
| GET | /api/sections/{id} | 🔒 | any | — | Section detail |
| POST | /api/registrar/sections | 🔒 | registrar, admin | ✔ | Create section + meetings |
| PATCH | /api/registrar/sections/{id} | 🔒 | registrar, admin | ✔ | Capacity can't go below approved count (409); `term_id`, `course_code`, `section_code` immutable |
| POST | /api/registrar/sections/{id}/instructors | 🔒 | registrar, admin | ✔ | `{faculty_id}` → 201 section (T-21). 422 unknown/inactive/unverifiable faculty (production: every id until T-30). 409 duplicate, cap of 5, or same-term schedule conflict. Audited |
| DELETE | /api/registrar/sections/{id}/instructors/{facultyId} | 🔒 | registrar, admin | ✔ | → 200 section; 404 not assigned. Never depends on identity or name lookup (T-21). Audited |
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

Section (T-20): `{ id, term_id, course_code, course_title, units, section_code, capacity, approved_count, remaining, delivery_mode: "scheduled"|"tba"|"online_async", meetings:[{day:"MON".."SUN", start:"HH:MM", end:"HH:MM", room}], created_at, updated_at }`. T-21 adds `instructors:[{faculty_id,name}]` after `meetings` (assignment order; `name` null when unavailable). A section PATCH that would create an instructor schedule conflict → 409.
- **Delivery mode (owner decision, 2026-11-17):** `delivery_mode` is optional on create (default `scheduled`) and can be changed by PATCH.
- **Meetings:** a `scheduled` section needs 1–14 meetings. `tba` and `online_async` sections may have 0–14. An empty list is never taken to mean TBA (422).
- **Overlap:** the rule is unchanged for every mode.
- **For T-22:** sections with no meetings never cause a schedule clash.
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
isAssigned(int $facultyId, int $sectionId): bool          // B-04: Team 4 owns assignments (T-21)
sectionIdsForFaculty(int $facultyId): int[]               // used by Team 3 / Team 5 for class access
assignedFacultyIds(int $sectionId): int[]
// Team 4 — App\Enrollment\Contracts\FacultyIdentityLookup  (port defined by Team 4, implemented by Team 5 in T-30;
//          replaces InstructorLookup; until T-30 every id is refused)
find(int $facultyId): ?FacultyIdentity                    // faculty_id, display_name, active
findMany(array $facultyIds): array

// Team 5 — App\Faculty\Contracts\FacultyDirectory
findByUserId(int $userId): ?FacultySummary              // isAssigned/sectionIdsForFaculty moved to SectionDirectory (B-04)
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
| 4 ↔ 5 | Rosters = `SectionDirectory::approvedStudentIds`. **B-04 (owner, 2026-11-17): Team 4 owns the authoritative instructor-to-section assignment** (validated, audited, server-side authorization); Team 5 owns faculty profiles and grades, implements `FacultyIdentityLookup`, and reads assignments only via `SectionDirectory`, keeping no conflicting assignment data. No duplicated faculty data. |
| 3 ↔ 5 | Grades reference `student_id`. Team 5 copies no profile data and serves names via `StudentDirectory`. Team 3's academic record does not embed grades in MVP. |
