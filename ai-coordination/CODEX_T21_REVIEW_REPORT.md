# Independent T-21 security and code review

**Verdict: REQUEST_CHANGES** — one Medium test reliability finding. No production security or functional defect was found in the reviewed scope. This report does not authorize a merge.

## Target and method

- Exact commit: `54d1a6bb20e2fba8cf0a8282ac1676defab8dbad`.
- Range: `229618b..54d1a6b`.
- Reviewed the approved T-21 plan, handoff, implementation report, integration blockers, changed code and tests. The checkout was clean before the report was written. Implementation files were not changed. Mutation probes used a copy under `%TEMP%`.

## Verification

| Check | Result |
|---|---|
| `php tests/run.php`, separate `APP_RUNTIME_PATH` under `%TEMP%` | **183 passed, 0 failed**, exit 0. Includes C6–C10, each run for three rounds by its test. |
| `php -l` on all PHP files found by `rg --files -g '*.php'` | **115 files, 0 failures**. |
| `git diff --check 229618b..54d1a6b` | Passed. |
| Apache evidence | `ai-coordination/AGENT_HANDOFF.md:35-39` records a passing private XAMPP Apache check for both document roots and a separate real-session DELETE probe: anonymous 401, missing CSRF 403, absent pair/section 404, production assign 422, and PUT 405. The committed `tests/Core/apache_check.php:117-139` checks routing and private-file protection, but does not itself exercise assignment routes. The one-off DELETE probe and its raw output are not committed. I reviewed this evidence but did not run Apache: the plan reserves that check for Claude because of listener coordination. The portable real-session HTTP tests passed here. |

## Findings

### Medium — C10 assumes an impossible guarantee about race order

`tests/Enrollment/EnrollmentConcurrencyTest.php:251-268` starts with three assignments to one section, then races three removals against three new assignments to that same section. Line 263 requires all six writes to succeed. If all three assignments take the lock first, the third correctly receives 409 because the section is already at the five-instructor cap (`app/Enrollment/Repositories/MockCatalogRepository.php:219-224`). The test can therefore fail on a correct implementation. The approved plan's C10 invariant (`docs/plans/T-21-instructor-assignments.md:386-396`) requires gap-free history matching successful writes; it does not require a particular lock order. A deterministic fixture can preserve the six-success assertion by putting the three new assignments on a second section while retaining mixed concurrent assignment and removal operations. This was established from the transaction and test setup; the observed full-suite run happened to pass. **Request:** make C10 independent of scheduling without weakening its success, sequence, replay, or final-state assertions.

### Low — missing direct same-pair race regression

C6–C10 cover competing overlapping assignments, the cap, duplicate adds, PATCH versus add, and mixed writes (`tests/Enrollment/EnrollmentConcurrencyTest.php:189-269`). They do not directly race assign and unassign of the same pair. Such a test would verify the permitted outcomes and history replay under that ordering. This is a coverage improvement, not evidence of a production defect.

## Security and functional assessment

- **Authentication, authorization and CSRF:** the POST and DELETE routes use the registrar/admin role guard (`routes/enrollment.php:17-18`); `SectionService` repeats the role check (`app/Enrollment/Services/SectionService.php:128-129,157-158`). The guard checks authentication, role, then CSRF (`app/Auth/Middleware/RouteGuard.php:55-78`). Real-session HTTP tests cover anonymous, student, faculty, staff, and missing CSRF (`tests/Enrollment/AssignmentHttpTest.php:13-88`). No bypass found.
- **Faculty identity:** production explicitly binds `UnavailableFacultyIdentity` (`app/bootstrap.php:99-103`), whose `find` returns null (`app/Enrollment/Contracts/UnavailableFacultyIdentity.php:12-20`). Assignment rejects null, inactive, mismatched and throwing lookups with the same 422 before a write (`app/Enrollment/Services/SectionService.php:128-153`). Access-granting directory methods require a matching active identity and fail closed (`app/Enrollment/Services/RepositorySectionDirectory.php:33-69`).
- **Conflicts, duplicates and cap:** assignment checks section existence, duplicate, cap and half-open same-term/same-day meeting overlap inside one transaction (`app/Enrollment/Repositories/MockCatalogRepository.php:207-235,295-322`). Section PATCH checks the merged row for each assigned instructor under the same lock (`:148-175`). Back-to-back and cross-term cases are covered by the service tests. No inconsistent stored schedule was found.
- **Atomicity, audit and corrupt stores:** the assignment and one history append occur in the same `JsonStore` transaction (`app/Enrollment/Repositories/MockCatalogRepository.php:230-263`; `app/Core/JsonStore.php:35-48`). Snapshot validation rejects malformed assignments/history, unknown sections, duplicates, excess instructors, stored conflicts, sequence gaps and replay mismatches before reads or writes (`app/Enrollment/Repositories/MockCatalogRepository.php:334-434`). C6–C10 passed in the full suite, subject to the C10 finding.
- **Removal and names:** DELETE requires no identity lookup; it returns the committed transaction result, then resolves remaining names. A failed `findMany` yields null names without undoing removal (`app/Enrollment/Services/SectionService.php:155-206`). Inactive assignments remain visible but grant no directory access. The seeded-store HTTP DELETE test passed.
- **Compatibility:** missing assignment keys are treated as empty, allowing T-20 stores to load (`app/Enrollment/Repositories/MockCatalogRepository.php:375-378`). Existing exact section key-order assertions were extended with `instructors` and explicit empty lists, not relaxed (`tests/Enrollment/SectionServiceTest.php:16-30`; `tests/Enrollment/EnrollmentHttpTest.php:138-145`). Team 3 remains wired to `DenyAllFacultyVisibility` (`app/bootstrap.php:80`), and `approvedStudentIds` remains the documented empty shortcut (`app/Enrollment/Services/RepositorySectionDirectory.php:26-30`).

## Mutation-check assessment

The implementation report claims M9–M20 were all killed (12/12), without per-mutant output (`ai-coordination/T21_IMPLEMENTATION_REPORT.md:24-25`). I inspected each planned mutation target and its proposed test (`docs/plans/T-21-instructor-assignments.md:409-426`). In a separate scratch copy, I independently applied and killed **M10, M11, M12, M18, M19 and M20** using focused original tests; their unmutated baselines passed. M9, M13–M17 were assessed by source/test inspection only, so the 12/12 claim is not independently reproduced in full.

| Mutation | Assessment |
|---|---|
| M9 pre-lock conflict read | C6 exercises two overlapping writes; inspected, not rerun as a mutant. |
| M10 permit sixth | Independently killed by the duplicate/cap service test. |
| M11 inclusive interval boundary | Independently killed by the back-to-back service test. |
| M12 skip PATCH conflict check | Independently killed by the conflict/PATCH service test. |
| M13 ignore term | Cross-term service case inspected. |
| M14 allow a throwing identity lookup | Rejection and byte-identity service case inspected. |
| M15 production lookup returns active | Production 422 and unchanged-store HTTP case inspected. |
| M16 skip removal history append | Unassign audit and replay cases inspected. |
| M17 use authenticated guard for POST | Denied-role HTTP matrix, including unknown-body precedence, inspected. |
| M18 skip stored-conflict validation | Independently killed by the corrupt-store test. |
| M19 remove service role check | Independently killed by the direct-service role test. |
| M20 ignore identity active flag | Independently killed by the directory test. |

## Accepted limitations and remaining risk

- The approved A4 design checks faculty identity before taking the enrollment lock, leaving a brief deactivation window (`docs/plans/T-21-instructor-assignments.md:46,531-536`). T-30 supplies the real faculty lookup; until then production assignment intentionally fails closed.
- The JSON store's audit replay detects malformed or inconsistent data, but does not authenticate the history against a malicious actor who can rewrite both lists. The approved plan requires replay, not tamper-evident storage.
- The Apache assignment-route evidence is documented in the handoff rather than retained as a runnable committed probe. I did not independently verify Apache behavior in this review.

**Final verdict: REQUEST_CHANGES.** Correct C10's schedule-dependent fixture and rerun the full suite before seeking review of a new exact commit. No merge authorization is given.
