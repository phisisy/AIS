# T-21 phase 2 implementation report

- Branch: `task/T-21-impl` (started at `c810188`).
- Commit: **not created**. `git add` was blocked by the sandbox: it could not create the shared worktree index lock at `AIS_SYSTEM/.git/worktrees/AIS_CODEX_T21/index.lock` (permission denied). The branch remains at `c810188`; the source changes are in this worktree only.
- Scope: instructor assignment backend, directory adapter, and Enrollment tests. No phase-3 bootstrap or route changes were made in this worktree.

## Files changed

- Production: `app/Enrollment/Models/Section.php`, `app/Enrollment/Repositories/CatalogRepositoryInterface.php`, `app/Enrollment/Repositories/MockCatalogRepository.php`, `app/Enrollment/Services/SectionService.php`, `app/Enrollment/Services/RepositorySectionDirectory.php`, `app/Enrollment/Controllers/SectionController.php`.
- Tests: `tests/Enrollment/EnrollmentSupport.php`, `SectionServiceTest.php`, `CatalogStoreTest.php`, `EnrollmentHttpTest.php`, `EnrollmentConcurrencyTest.php`, `AssignmentServiceTest.php`, `SectionDirectoryTest.php`, `AssignmentHttpTest.php`, `FacultyIdentityPortTest.php`, `fixtures/enrollment_worker.php`, `fixtures/StubFacultyIdentity.php`.
- This report.

The repository now stores assignment pairs and gap-free, append-only history under the enrollment lock. Its snapshot validates both new keys, replays history, and rejects stored conflicts. Assign and section PATCH use one conflict helper. The service validates a faculty identity before the write lock and defaults to `UnavailableFacultyIdentity`. The directory grants access only for a confirmed active identity. Section responses include the ordered `instructors` key.

## Checks

| Check | Result |
|---|---|
| `php tests/run.php` with `APP_RUNTIME_PATH` under `%TEMP%` | **176 passed, 4 failed, 180 total**. All 163 existing tests pass. The 13 new tests that do not depend on phase-3 wiring pass. |
| C6-C10 worker races | Passed, three rounds each, within the full suite. |
| `php -l` on all PHP files | **115 files, 0 failures**. |
| `git diff --check` | Passed. |
| Phase-3 wiring simulation in a `%TEMP%` scratch copy | All three assignment HTTP tests and the source-scan test pass (4/4). Only the specified bootstrap binding and two routes were added to that scratch copy. |
| Mutation checks M9-M20 in the same scratch copy | **12/12 killed** by the specified unit, store, HTTP, directory, or concurrency tests. M17 initially survived a valid-body matrix; the matrix was strengthened to assert that a denied role receives 403 before body validation, and M17 was killed on rerun. |

The four **expected-red** tests in this phase-2 worktree are:

1. `Assignment HTTP: real-session role matrix, CSRF and route methods` — assignment routes are not registered yet.
2. `Assignment HTTP: reads expose instructors and seeded DELETE audits once` — DELETE route is not registered yet.
3. `Assignment HTTP: production identity refuses every id without changing the store` — POST route is not registered yet.
4. `Faculty identity port: production has only the unavailable lookup and explicit binding` — bootstrap does not yet bind `UnavailableFacultyIdentity` explicitly.

`tests/Core/apache_check.php` was not run, as directed; phase 3 owns that check.

## Deviations and risks

- The immediate DELETE response renders retained instructor names as `null`, then a later GET resolves them through `findMany`. This follows A7's literal rule that unassign calls no identity lookup, including `findMany`; it does not affect the stored assignment facts or other section reads. Phase-3 review should confirm this interpretation against A9.
- The planned production wiring and HTTP route checks remain incomplete in this worktree by the phase split. They passed with phase-3 wiring simulated in scratch, and must pass in the integration branch before merge.
- Staging and committing remain incomplete because this worktree's Git administrative files reside in `AIS_SYSTEM`, outside the writable root. No Git metadata was changed and no alternate repository or worktree was created. A runner with write access to the shared Git metadata must stage and commit this worktree's changes.
- Snapshot replay and conflict validation are linear or greater in the JSON store size; the plan accepts this for the development store. Faculty deactivation between pre-lock verification and commit remains the approved A4 window.
