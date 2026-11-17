# T-10 Implementation Report — Student Records (Team 3)

Date: 2026-11-17 · Implementer: Claude · Branch: `task/T-10-student-records` (from `main` `a8b81d4`)
Implementation commit: **`1ca73e6`** · Status: **ready for owner review, not merged** · T-11 and T-05 not started.

## 0. Preconditions completed

- **T-04 merged:** pre-merge checks passed:
  - 91/91 tests, 62/62 lint, Apache PASS
  - clean tree, only T-04 files plus the two requested design docs
  - no runtime events or credentials
  - the review and a re-verification (one leftover Low was fixed in `17d58dc`)

  **Merge commit `c280aa7`**; 91/91 tests and Apache PASS on `main`.
- **Planning delivered:** `PROPOSAL_ADMIN_PROTECTION_RECOVERY.md`, the detailed registry and owner-recovery proposal, for separate approval and not implemented.
- **Contract finalized before code:** `API_CONTRACT_T10_STUDENTS.md`, committed on `main` (`a8b81d4`). B-07 is marked resolved.

## 1. Implementation summary

Implemented, following the contract:
- Student profiles linked to existing Team 2 `student` accounts. No credentials are ever created.
- Unique, stable, configurable student numbers (`STUDENT_NUMBER_PATTERN`).
- Program and year-level validation against a temporary Team 3 program catalog.
- The five provisional academic statuses, kept separate from account status.
- Staff list and search.
- Self-access for students.
- B-07 faculty summaries through a `FacultyStudentVisibility` interface that **denies all** until a verified class-assignment source exists (T-31).

Layering: controller (thin, field allowlists) → `StudentService` (rules) → repository → `JsonStore`. Uniqueness checks, and the year-level/program re-check, run inside the write lock.

## 2. API endpoints

| Method | Path | Guard roles | Result |
|---|---|---|---|
| GET | `/api/students` | registrar, admin | 200 `{students, pagination}`; 422 bad query |
| POST | `/api/students` | registrar, admin | 201 `{student}`; 409 duplicate number or account; 422 validation (incl. invalid account) |
| GET | `/api/students/me` | student | 200 own profile; 404 none |
| GET | `/api/students/{id}` | registrar, admin, student, faculty | staff and owner: full; faculty: summary if verified; else **404** (same body as missing) |
| PATCH | `/api/students/{id}` | registrar, admin | 200; 404; 409 concurrent year/program conflict; 422 validation or immutable/unknown field |

Every route returns 401 when not signed in, 403 for a role not in the allowlist, and 403 when CSRF is missing on writes (T-02 guard).

## 3. Files changed (`git diff --stat main...1ca73e6`: 22 files, +1370 / −3)

| Area | Files |
|---|---|
| Models | `app/Students/Models/Student.php`, `Program.php` |
| Contracts | `app/Students/Contracts/ProgramCatalogInterface.php`, `FacultyStudentVisibility.php`, `DenyAllFacultyVisibility.php` |
| Repositories | `app/Students/Repositories/StudentRepositoryInterface.php`, `MockStudentRepository.php`, `JsonProgramCatalog.php` |
| Services | `app/Students/Services/StudentService.php`, `StudentNumberPolicy.php` |
| HTTP | `app/Students/Controllers/StudentController.php`, `routes/students.php` |
| Wiring/config | `app/bootstrap.php` (Team 3 block), `.env.example` (`STUDENT_NUMBER_PATTERN`) |
| Dev data | `database/seeders/dev_students.php` (3 fictional programs, 1 sample profile) |
| Tests | `tests/Students/StudentServiceTest.php`, `StudentHttpTest.php`, `StudentConcurrencyTest.php`, `StudentSupport.php`, `fixtures/student_worker.php` |
| Docs | `README.md` (Team 3 section), `ai-coordination/API_CONTRACT_T10_STUDENTS.md` (2 review clarifications) |

No Team 2 code changed; T-01–T-04 behaviour is untouched. Team 1 frontend, Team 6 schema and other modules were not touched.

## 4. Access-control matrix (verified over HTTP)

| Caller | List | Get own | Get other | `/me` | Create | Update |
|---|---|---|---|---|---|---|
| Anonymous | 401 | 401 | 401 | 401 | 401 | 401 |
| Student | 403 | 200 full | **404** | 200 / 404 if no profile | 403 | 403 |
| Faculty | 403 | — | **404** (deny-all today); summary only when a verified approved enrollment in their own section exists | 403 | 403 | 403 |
| Registrar | 200 | — | 200 full | 403 | 201 | 200 |
| Admin | 200 | — | 200 full | 403 | 201 | 200 |

Notes:
- **Admin** has these rights only because the routes list `admin` explicitly; there's no superuser bypass.
- **Multi-role users:** rules apply in order (staff, then owner, then faculty, then 404).
- **Faculty summary fields:** `id, student_number, full_name, program_code, year_level, sections` only. Never `user_id`, status, admission year or timestamps.

## 5. Test results

| Run | Result |
|---|---|
| `php tests/run.php` | **111 passed, 0 failed**: 91 pre-existing (all preserved) + 20 T-10 |
| PHP syntax | 79 files, 0 failures |
| Apache check | PASS, both document roots |
| Dev seeders | Seed in a temporary runtime; a re-run is refused |

Coverage of the owner's list:
- creation, including defaults and normalization
- duplicate numbers and duplicate account profiles, including **multi-process** races
- invalid academic status, invalid program, year above the program maximum
- unauthorized access and the role matrix
- student self-access
- faculty visibility (stub source), deny-all default, source errors, malformed answers, non-approved enrollments, missing class assignments
- registrar and admin permissions
- input validation, including types, lengths, UTF-8, ASCII/C1/bidi/zero-width characters and the configurable number format
- concurrent updates (no lost update; the program/year race never stores an invalid pair)
- storage failure (corrupt store: generic 500, nothing written; unwritable store: unchanged)
- regression: every earlier test still passes

Mutation checks (each reverted; code restored):

| Mutation | Result |
|---|---|
| Student reads anyone | 4 tests fail |
| Faculty without a verified section | 3 fail |
| Faculty gets the full record | 1 fails |
| Uniqueness check removed | 3 fail |
| In-lock year re-check removed | The race test failed in 3 of 3 runs |

## 6. Security findings (read-only reviewer)

No Critical, High or Medium findings.

| Finding | Severity | Resolution |
|---|---|---|
| Unicode controls and bidi overrides passed the name filter | Low | **Fixed** (`\p{Cc}\p{Cf}`), with a test |
| Concurrent PATCHes could store a year level above the program maximum | Low | **Fixed:** check re-run inside the write lock (409); multi-process test |
| Faculty response timing could reveal existing ids once T-31 adds a real lookup | Low | **Fixed:** the visibility source is queried for missing ids too |
| `enrollment_status` passed through unchecked | Info | **Fixed:** only `approved` makes a student visible |
| A configured pattern can escape the `\A(?:…)\z` wrapper | Info | Mitigated by the fixed 1–32 `[A-Z0-9/-]` check that runs first. The pattern is server config. Documented |
| An owner who later loses the `student` role still sees their own profile | Info | Matches the contract wording; kept |
| One corrupt row makes all student requests return 500 | Info | Fail-closed by design |

## 7. Integration dependencies

| Team | Dependency | Status |
|---|---|---|
| 2 | `RouteGuard`, `AuthorizationService`, `UserRepositoryInterface::findById` | Used; unchanged |
| 4 | Approved enrollments per section, for faculty visibility | Needed by T-31; deny-all until then |
| 5 | Official teaching assignments, for faculty visibility | Needed by T-31; deny-all until then |
| 4/5 | `StudentDirectory` (student summaries for enrollment and grades) | T-11 (not started) |

## 8. Team 6 database limitations

- File-backed storage only (`runtime/students/students.json`, `programs.json`). The field names are API names, **not** final columns. The only relationship assumed is "a profile references an account id and a program code".
- The program catalog is temporary. Team 6 owns the final schema.
- `APP_REPOSITORY=mysql` is still refused at startup, so there's no unsafe database mode. A future MySQL repository (T-40) must give the same guarantees:
  - uniqueness under concurrency (unique constraints plus transaction)
  - the in-transaction year/program re-check
  - fail-closed handling of corrupt or missing data

## 9. Remaining blockers

1. **T-31:** the verified faculty visibility source (Teams 4 and 5) is needed before faculty can see any student.
2. **B-03 / T-40:** Team 6 schema for students and programs.
3. **The institution's student-number policy:** set `STUDENT_NUMBER_PATTERN` when known. The default is deliberately generic.
4. **No student-records audit log** in T-10 (it wasn't requested). It could follow the T-03 pattern if wanted.
5. **Account ↔ profile consistency:** if an account is later deactivated or loses `student`, its profile stays. Today Team 2's login checks control access. Policy to confirm.

## 10. Git

- T-04 merge into `main`: `c280aa7`. Coordination docs on `main`: `a8b81d4`.
- T-10 implementation on `task/T-10-student-records`: **`1ca73e6`**, followed by a documentation-only commit adding this report and the board and review updates. **Not merged.**
