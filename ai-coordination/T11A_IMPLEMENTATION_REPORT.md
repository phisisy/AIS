# T-11a implementation report — Academic Records (Team 3)

- **Branch:** `task/T-11a-academic-records` (from `main` `d15490a`).
- **Implementation commit:** `0b91668`.
- **Status:** VERIFIED. **Merged into `main` as `d062a5e`** (owner approval, 2026-11-17). Not pushed.
- **Spec:** `PROPOSAL_T11_ACADEMIC_RECORDS.md` (D5 split: T-11a only), with owner decisions of 2026-11-17.

## 1. Approved scope (owner, 2026-11-17)

1. Student academic record retrieval.
2. Student self-access.
3. Registrar and admin access.
4. Faculty access restrictions.
5. Academic change history.
6. Atomic history updates.
7. Read-only program catalog.
8. Internal `StudentDirectory` interface.
9. Unavailable state for the enrollment and grade summaries, which aren't integrated yet.
10. Automated tests.

- **D4:** no history cap, and responses are paginated.
- **Not approved:** T-11b (course catalog, curriculum, real summaries).

## 2. API endpoints

| Method | Path | Guard roles | Behaviour |
|---|---|---|---|
| GET | `/api/students/{id}/academic-record` | registrar, admin, student | Staff: any record. Student: own record only, while holding `student`. Everything else → 404 `Student not found.`, identical to a missing record |
| GET | `/api/students/me/academic-record` | student | Own record; 404 if no profile |
| GET | `/api/programs` | any signed-in user | `{programs:[{code,name,max_year_level}]}`, sorted by code, read-only |

- **Query:** `page` (1–1,000,000, default 1) and `per_page` (1–100, default 50). Anything else → 422. Validation runs before the record lookup, so a 422 can't be used to probe which ids exist.
- **Response:** `data.academic_record = {student, history{entries, pagination}, enrollment_summary, grades_summary}`.
- **Errors:** 401, 403, 404 and 422, plus a generic 500 for corrupt storage. No internals are leaked.

## 3. Authorization matrix

| Caller | `{id}` own | `{id}` other / missing | `/me` | `/api/programs` |
|---|---|---|---|---|
| Anonymous | 401 | 401 | 401 | 401 |
| Student (holds `student`) | 200, without `actor_id` | 404 | 200 / 404 | 200 |
| Student with no profile | — | 404 | 404 | 200 |
| Profile owner who lost `student` (no staff role) | 403 (guard) | 403 | 403 | 200 |
| Faculty | 403 | 403 | 403 | 200 |
| Registrar / admin | 200, with `actor_id` | 200 / 404 | 403 unless also student | 200 |

- No writes go through these routes, so CSRF doesn't apply.
- Record writes happen only as a side effect of the T-10 `POST`/`PATCH /api/students`.

## 4. Academic history

- **Tracked fields:** `program_code`, `year_level` and `academic_status`. Creation writes one entry for each initial value (`from: null`).
- **Not tracked:** name and admission-year corrections, and no-op updates.
- **Entry shape:** `{seq, at, field, from, to, actor_id}`.
  - `seq` is per student, starts at 1 and has no gaps.
  - `actor_id` is 0 for the system or a seeder.
  - There is no free text.
- **Atomicity:** entries are written inside the same `JsonStore::transaction` (exclusive lock, temp file then rename) as the profile row, in the same `students.json`. If the in-lock check fails, if the history is corrupt, or if the write fails, the profile and the history both stay unchanged.
- **Fail-closed:**
  - The read path validates the whole history map strictly.
  - Since the security fix, the write path refuses (`RuntimeException` → 500, nothing written) to record a value the reader would reject. A legacy bad value therefore aborts only its own update, and the rest of the store keeps working.
- **Retention (D4):**
  - No cap, never truncated, and an update is never refused because of history size.
  - History is kept when the account is deactivated or loses `student` (consistent with decision 3).
- **Visibility:** `actor_id` is shown to registrar and admin only.

## 5. StudentDirectory (in-process, Teams 4 and 5)

```php
interface StudentDirectory {
    public function findById(int $studentId): ?StudentSummary;
    public function findByUserId(int $userId): ?StudentSummary;
    public function isEnrollmentEligible(int $studentId): bool;
}
// StudentSummary: id, userId, studentNumber, fullName, programCode, yearLevel, academicStatus (readonly)
```

- **Implementation:** `RepositoryStudentDirectory`, at `$container['student_directory']`. It has no HTTP route.
- **Eligibility (D2, Team 3's part only):** academic status `active`, plus an active account that holds `student`. Team 4 enforces all other enrollment rules.

## 6. Summaries

- **Enrollment and grade summaries:** both are wired to `UnavailableSummarySource` and return `{"available": false}`.
- **Future source output:** it is accepted only as a non-empty map of `snake_case` key → non-negative int. A null, an error or any other shape gives `{"available": false}`.
- No counts are invented.

## 7. Files changed

| File | Change |
|---|---|
| `app/Students/Contracts/{AcademicSummarySource,StudentDirectory,StudentSummary,UnavailableSummarySource}.php` | new |
| `app/Students/Controllers/AcademicRecordController.php` | new |
| `app/Students/Services/AcademicRecordService.php` | new: access rules, pagination, record view, summaries |
| `app/Students/Services/RepositoryStudentDirectory.php` | new |
| `app/Students/Repositories/StudentRepositoryInterface.php` | `history()`; optional `$actorId` on `create`/`update` |
| `app/Students/Repositories/MockStudentRepository.php` | history written in the same transaction; strict validation on read and on write |
| `app/Students/Services/StudentService.php` | passes the actor id to the repository |
| `app/bootstrap.php` | Students wiring only (shared repository, record controller, directory) |
| `routes/students.php` | 3 GET routes |
| `tests/Students/{AcademicRecordTest,AcademicRecordHttpTest}.php` | new |
| `tests/Students/{StudentConcurrencyTest,StudentServiceTest,StudentSupport}.php` | history concurrency test, fixtures, signature updates |
| `README.md`, `ai-coordination/{API_CONTRACTS,PROPOSAL_T11_ACADEMIC_RECORDS,TASK_BOARD}.md`, this report | documentation |

## 8. Verification (2026-11-17)

| Check | Result |
|---|---|
| `php tests/run.php` | **124 passed, 0 failed** (112 T-10 baseline + 12 T-11a) |
| `php -l` on every changed or new PHP file | no syntax errors |
| `php tests/Core/apache_check.php` | PASS, public and repository document roots |
| Security fix present in `MockStudentRepository.php` | confirmed. A mutation check (guard removed) makes the new test fail (123/1), and restoring the guard brings it back to 124/124 |
| Atomic history | one transaction (code) + concurrency test (every concurrent tracked change gets exactly one gap-free entry) + byte-identical store on aborted writes |
| Faculty denied | role-matrix tests: 403 on both record routes |
| Unavailable summaries | test: explicit `{available:false}`; invalid or failed sources never surface as data |
| Working tree | only T-11a files. No runtime data, `.env` or credentials staged |

## 9. Security review (security-reviewer, read-only)

- **Critical, High and Medium:** none.
- **Low 1, fixed:**
  - **Issue:** the history write path didn't validate values. One non-conforming profile value could have poisoned the store for everyone.
  - **Fix:** the write path now validates values, and a test was added (database-engineer).
- **Low 2, resolved by the owner's decision:** a history cap was specified but not implemented. D4 = no cap. The resulting performance limit is documented (§10).
- **Info:**
  - Corrupt history fails closed for the whole store, as specified.
  - Profiles created before T-11a have no creation entries.
- **Passed:**
  - IDOR and ownership.
  - `/me` vs `{id}` routing.
  - Hiding `actor_id`.
  - Pagination validation.
  - Summary sanitization.
  - No private fields in `StudentDirectory`.
  - No error leakage.

## 10. Known limitations

- **File-backed storage:** every request reads and validates the whole `students.json` (all profiles and all history). Cost grows with total history, and there is no cap (D4). This is fine for development. Production waits for Team 6's schema (T-40).
- **Corruption scope:** corrupt history blocks reads and profile writes for all students, not just one (fail-closed by design).
- **Pre-T-11a profiles:** their history starts at their first tracked change.

## 11. Integration dependencies

- **Team 4 (T-22):** consumes `StudentDirectory::findByUserId` and `isEnrollmentEligible`. It must provide an enrollment `AcademicSummarySource` for T-11b.
- **Team 5 (T-32):** consumes `StudentDirectory`. It must provide a grade `AcademicSummarySource` for T-11b.
- **Team 6:**
  - Needs tables for programs and student academic history.
  - The MySQL implementation must write the profile and its history in one transaction.
  - No schema was created.
- **D1 (course catalog owner):** still open, and blocks T-11b.

## 12. Commit

- Implementation commit: `0b91668` on `task/T-11a-academic-records`; docs commit `6ae3ac3`.
- Merge commit: `d062a5e` on `main` (local only, not pushed).

## 13. Independent review and merge (2026-11-17)

- **Reviewer:** AGY (independent, read-only). Inspected `0b91668` and `6ae3ac3`.
- **Recommendation:** **APPROVE**.
- **Findings:**
  - Critical, High and Medium: none.
  - **L-01:** performance of the file-based store as history grows. This is the known limitation in §10, accepted under D4, and resolved in production by MySQL (T-40). No change needed.
  - **I-01:** the guard runs before any parameter validation, so anonymous requests get 401 first.
  - **I-02:** errors from summary sources degrade to `{available:false}`.
- **Clarifications agreed with the reviewer:**
  - The reviewer's lint count (68) covered app/config/routes/public/tests; the full tracked set is 88. Both had 0 failures.
  - The production database is MySQL (T-40).
  - The merge unblocks T-22 (together with T-21). T-20 was already unblocked by T-02.
- **Merge:** owner-approved `--no-ff` merge into `main` as `d062a5e`. Not pushed.
- **Post-merge on `main`:** `php tests/run.php` 124 passed, 0 failed; `tests/Core/apache_check.php` PASS for both document roots.
