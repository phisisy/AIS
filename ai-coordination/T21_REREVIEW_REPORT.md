# T-21 independent re-review #2

**Verdict: APPROVE.** No blocking security or functional defect was found. This review does not authorize or perform a merge.

## Target and scope

- Exact commit reviewed: `417ba6a76421d3dfdf19cd6a1d0b4f9736baa89e` on `task/T-21-instructor-assignments`.
- Correction range: `54d1a6b..417ba6a`. The only code changes are `tests/Enrollment/EnrollmentConcurrencyTest.php` and `tests/Enrollment/fixtures/enrollment_worker.php`; the other changes are coordination documents. `git diff --name-only 54d1a6b..417ba6a -- app routes config public database` returned no files. **No production code changed.** `git diff --check` passed.
- Read the approved plan, handoff, implementation report, review #1, corrected tests and worker, and the affected authorization, service, repository, store validation, route, identity and Apache-check code. The pre-existing untracked `.claude/settings.local.json` was left untouched. This was a separate review session; phase-2 implementation was also done by Codex, so this is not author-independent across agent families.

## C10 and C11

- **C10 — corrected.** The fixture seeds section 1 with faculty 10, 11 and 12. Three workers remove those distinct pairs while three workers add faculty 20, 21 and 22 to section 2. Each removal starts from an existing pair; each addition has a distinct faculty ID, and section 2 never exceeds three of its five allowed instructors. The sections meet on different days. Thus every serialization of the six transactions can succeed. The test still requires six successes, history `seq` exactly 1 through 9, an empty final section 1, final section 2 exactly `{20,21,22}`, and a repository read that validates history replay. The cap is still enforced in the repository (`>= 5`), its C7 race and service tests; the correction did not relax it.
- **C11 — adequate.** The pair is initially absent. If assign takes the lock first, both operations succeed, history actions are `assigned, unassigned`, and the final pair is absent. If unassign takes the lock first, it gets the exact 404 message, assign succeeds, history has only `assigned`, and the final pair is present. The test requires assign success, dense sequence numbers equal to the successful-write count, the correct actions and pair IDs, and a repository read that validates replay. Earlier service tests check history actor and timestamp. C11 runs three rounds per invocation.

## Verification

| Check | Result |
|---|---|
| `php tests/run.php` with a unique `APP_RUNTIME_PATH` under `%TEMP%` | **184 passed, 0 failed, 184 total; exit 0.** Includes C10 and C11, three rounds each. |
| `php -l` over `rg --files -g '*.php'` | **115 files, 0 syntax failures.** |
| Focused C10 and C11 stress run | **15 additional invocations each, 45 additional rounds per test; all passed.** Combined with the full suite, each ran 48 rounds here. |
| `git diff --check 54d1a6b..417ba6a` | Passed. |

## Mutation-check assessment

Review #1 independently killed M10, M11, M12, M18, M19 and M20. I reproduced the six previously unverified checks in a temporary copy of the source and tests. Each mutated PHP file passed syntax checking; each targeted test passed on the original source in the full suite, then failed for the intended behavior under the isolated mutation. No mutation was applied to the worktree.

| Mutation | Independent result |
|---|---|
| M9: use a pre-lock snapshot for assignment conflict checks | **Killed.** C6 observed two successes where it requires one. |
| M13: ignore term in conflict checks | **Killed.** The cross-term assignment that must succeed raised a 409. |
| M14: accept a throwing identity lookup | **Killed.** The rejection test found that no `HttpException` was thrown. |
| M15: production unavailable lookup returns an active identity | **Killed.** The production HTTP test received 201 instead of 422. |
| M16: omit unassign history append | **Killed.** The service audit test expected sequence 1–4 and found 1–3. |
| M17: change the POST route guard to authenticated | **Killed.** The denied-role HTTP matrix received 422 instead of the required 403 on an unknown-body request. |

Together with review #1, all planned M9–M20 mutations have independently failed their specified checks across the two reviews. The implementation report's original 12/12 claim is therefore supported, although the probes were not retained as a committed mutation harness.

## Security and functional findings

**No blocking findings.** The corrections touch only tests and a worker's error-message output. I rechecked the prior review's trust boundaries: the POST and DELETE routes use registrar/admin guards with CSRF; the service repeats role checks; production binds `UnavailableFacultyIdentity`; lookup failures, inactive identities and mismatches fail closed; access-granting directory methods require an active matching identity. DELETE does not depend on identity lookup. The repository checks duplicate pairs, the five-instructor cap and same-term meeting conflicts inside the store transaction, shares the conflict helper with section PATCH, and appends one audit entry per successful mutation. Snapshot validation rejects malformed or inconsistent history and assignments. The full HTTP, store, directory, concurrency and regression tests passed. T-20 stores without assignment keys remain supported, and the section response change remains additive.

## Apache evidence and remaining risks

- The handoff reports a passing private XAMPP Apache check for both repository and `public/` document roots, plus a one-off real-session DELETE probe on both roots: anonymous 401, missing CSRF 403, absent pair/section 404, production assign 422, empty `instructors`, and PUT 405. I assessed this evidence but did not rerun Apache because the plan reserves the listener-based check for the coordinated phase-3 owner. The committed `tests/Core/apache_check.php` checks general routing and private-file protection, not assignment routes. The one-off probe and raw output are not committed; successful seeded DELETE 200 is covered by the portable real-HTTP test, not by the reported Apache probe. This is a verification gap, not an observed defect.
- The approved A4 design checks faculty identity before the enrollment lock; deactivation during that interval can leave a newly committed assignment. T-30 will supply the real lookup. Until then production assignment intentionally returns 422.
- History replay detects inconsistent data but is not tamper-evident against an actor able to rewrite both the assignment and history lists. The JSON store's replay cost grows with store size; the plan defers database storage to T-40.

**Final verdict: APPROVE** for exact commit `417ba6a76421d3dfdf19cd6a1d0b4f9736baa89e`. Stop after review; do not merge or push.
