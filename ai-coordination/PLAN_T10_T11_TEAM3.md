# Phase 3 plan — Team 3: T-10 Student Records, T-11 Academic Records

Status: **PLAN for owner review. Not implemented.** Date: 2026-11-17. Base: `main` `11bfe8b`.

## 1. Existing Team 3 code (inspected)

There is none in `AIS_SYSTEM`:
- no `app/Students/`
- `routes/students.php` is an empty registration closure from T-00
- no `tests/Students/`

Requirements therefore come from the Team 3 master prompt, the frontend guide and `API_CONTRACTS.md`.

Usable foundations, already built and merged:

| Foundation | From | How Team 3 uses it |
|---|---|---|
| Request/Response/Router/JsonStore, RuntimeDirectory | T-00/T-01 | HTTP, JSON envelopes, mock storage under the runtime path |
| `AuthService::requireUser()` via `RouteGuard` | T-01/T-02 | Identity on every request |
| `$guard->roles([...], $handler)` | T-02 | Every Team 3 route, enforced by the guard-coverage test |
| `AuthorizationService::requireOwnerOrRole()` (404) | T-02 | "A student sees only their own record" |
| `UserRepositoryInterface::findById()` | T-01 | Checking that a linked account exists, is active and has the `student` role |

## 2. Features

| Feature | Source | T-10 | T-11 |
|---|---|---|---|
| Create student profile (registrar/admin) | Prompt §3, contract | ✔ | |
| Unique student number; one profile per account | Prompt §3 | ✔ | |
| View / search / list (registrar/admin) | Prompt §3 | ✔ | |
| Update profile (registrar/admin); `student_number` and `user_id` immutable | Contract | ✔ | |
| Student views own profile (`/me`) and only their own | Prompt §3 | ✔ | |
| Faculty sees summary fields only, only for students in their assigned classes (B-07) | Owner decision | ✔ (deny-all until Teams 4/5 exist, see §5) | |
| Programs (code, name) | Prompt §6 | | ✔ |
| Academic record: program, year level, academic status, current term | Prompt §3 | | ✔ |
| `StudentDirectory` interface for Teams 4/5 | Contract §3 | | ✔ |

Explicitly **not** in scope (owner exclusions and the MVP list): grades (Team 5), enrollment data (Team 4), credentials (Team 2), home address, personal contact details, financial records, profile deletion.

## 3. Proposed endpoints (from `API_CONTRACTS.md`; unchanged unless noted)

| Method | Path | Roles (guard) | Additional server rule |
|---|---|---|---|
| GET | `/api/students` | registrar, admin | Search by name or student number; paginated (≤100) |
| POST | `/api/students` | registrar, admin | Linked account must exist, be active and hold `student`. 409 on duplicate number or account |
| GET | `/api/students/me` | student | Profile linked to the session user; 404 if none |
| GET | `/api/students/{id}` | registrar, admin, student, faculty | Student: own only, else **404**. Faculty: see §4, else **404** (changed from the contract's 403 so faculty can't probe ids) |
| PATCH | `/api/students/{id}` | registrar, admin | `student_number` and `user_id` rejected (422) |
| GET | `/api/students/{id}/academic-record` | registrar, admin, student | Student: own only (404) |
| GET | `/api/programs` | any signed-in user | Read-only list (T-11) |

## 4. Field contract (for review before implementation)

**Full profile** (registrar/admin; the student for their own record):

| Field | Type | Notes |
|---|---|---|
| `id` | int | Team 3 student id (not the account id) |
| `user_id` | int | Linked account (Team 2). **Not returned to faculty** |
| `student_number` | string | Unique, immutable. Format to confirm, e.g. `^\d{4}-\d{5}$` |
| `first_name`, `middle_name?`, `last_name` | string | 1–100 chars, trimmed |
| `program_code` | string | Must exist in programs |
| `year_level` | int | 1–6 |
| `academic_status` | enum | `regular`, `irregular`, `on_leave`, `graduated`, `withdrawn` |
| `admission_year` | int | Plausible range, e.g. 1990 to the current year |

No address, phone, personal email, date of birth or financial data in the MVP. Adding any of these later is a separate, owner-approved change.

**Faculty summary** (B-07), returned only when the student is enrolled (approved) in a section assigned to the requesting faculty member:

| Field | Source | Notes |
|---|---|---|
| `id` | Team 3 | Student id |
| `student_number` | Team 3 | |
| `full_name` | Team 3 | "Last, First Middle" |
| `program_code`, `year_level` | Team 3 | Teaching-relevant academic information |
| `sections` | Team 4/5 | Only the shared sections, as `{section_id, section_code, enrollment_status}` |

Never returned to faculty: `user_id`, `academic_status` history, and any record outside their shared sections. That last case answers **404**, the same as a missing record.

## 5. Dependencies

| On | What | When | Until then |
|---|---|---|---|
| Team 2 (done) | Guard, `requireOwnerOrRole`, user lookup | Now | — |
| Team 4 (T-21/T-22) | Approved enrollments per section, through `SectionDirectory::approvedStudentIds()` | After T-22 | Faculty visibility source is missing |
| Team 5 (T-30) | Sections assigned to a faculty account, through `FacultyDirectory::sectionIdsForFaculty()` | After T-30 | Same |
| Team 6 (B-03) | Final tables/columns for students and programs | T-40 | Mock JSON store only; no SQL; MySQL mode stays fail-closed |

To avoid a circular build order, Team 3 defines a `FacultyStudentVisibility` interface. It answers one question: may faculty user X see student Y, and through which sections. It ships with a **deny-all** default, so faculty get 404 for every student until T-31 wires the real implementation from Teams 4 and 5. This is deny-by-default and needs no change to Team 3 later.

## 6. Proposed files (Team 3 owns `app/Students/**`)

```
app/Students/Models/Student.php, Program.php
app/Students/Repositories/StudentRepositoryInterface.php, MockStudentRepository.php
app/Students/Repositories/ProgramRepositoryInterface.php, MockProgramRepository.php (T-11)
app/Students/Services/StudentService.php, AcademicRecordService.php (T-11)
app/Students/Contracts/StudentDirectory.php (T-11), FacultyStudentVisibility.php + DenyAllFacultyVisibility.php
app/Students/Controllers/StudentController.php, AcademicRecordController.php (T-11)
routes/students.php; app/bootstrap.php (Students wiring only)
database/seeders/dev_students.php
tests/Students/*
README.md section
```

Storage: `runtime/students/students.json` (profiles, plus programs for T-11), in one file so a profile and its uniqueness checks commit atomically.

## 7. Required tests

- **CRUD:** create, list, search, paginate, get, update.
- **Validation:** names, year level, status enum, admission year, program exists, student-number format; unknown fields 422.
- **Uniqueness:** duplicate student number and duplicate account link are 409, including under concurrent creates (multi-process, like T-03).
- **Account linking:** a missing, inactive or non-student account is refused; Team 3 never writes users.
- **Ownership:** student A reading B is 404 (no id probing); `/me` works; a student without a profile gets 404.
- **Faculty (B-07):** deny-all default gives 404 for every student. A test-only visibility stub proves summary-only fields and the 404 for non-shared students. No `user_id` or private fields in faculty responses.
- **Role matrix** over HTTP; the guard-coverage test passes for the new routes; CSRF on writes.
- **T-11:** academic record ownership; programs read-only; `StudentDirectory` returns summaries only and no private fields.
- Mutation checks for the ownership and faculty-visibility rules.

## 8. Integration blockers and questions for the owner

1. **Field contract (§4):** approve or change it, especially the student-number format and the `academic_status` values.
2. **Faculty 404 vs 403** for non-visible students: the plan uses 404 to prevent probing.
3. **Programs ownership:** the Team 3 prompt says Team 3 proposes `programs`. Confirm Team 3 owns the program list until Team 6 finalizes it.
4. **Profile ↔ account:** does creating a student profile require an existing `student` account (current contract), or may registrar create a profile first and link it later?
5. **B-03 (Team 6):** final schema. Mock-only until then.
6. Faculty visibility is fully testable only after T-22 and T-30 (planned as T-31 wiring).

## 9. Proposed sequence

T-10 (profiles + ownership + deny-all faculty visibility) → review → T-11 (programs, academic record, `StudentDirectory`) → review. Each on its own branch from `main`, stopping for approval. Neither has been started.
