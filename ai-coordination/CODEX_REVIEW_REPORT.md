# Codex independent review — T-20 Terms & Sections

**Verdict: APPROVE**

## Reviewed task and revision

- Task: T-20, terms and sections catalog.
- Branch: `task/T-20-terms-sections`.
- Reviewed head: `60ac2366cb606b97825aab9278646f544a083970` (handoff/status commit); implementation through `0624eb7`.
- Baseline: `main` at `592d9126d3193f5a5a757f0876b24f0335c1fed9`.
- Range reviewed: `592d912..60ac236`, with additional attention to `909a000..0624eb7`.
- The handoff, task status, branch, and Git history agreed. The working tree was clean before review.
- Worktree: the shared branch worktree. Tests used isolated runtime directories; no branch checkout or source edit was made.
- Independence disclosure: Codex implemented the original T-20 phase 1 backend in `b04fec4`. This review independently inspected the committed code and reran checks, but it is not a review by an agent uninvolved in that implementation. Claude implemented the later policy alignment in `0624eb7`.

## Tests run independently

| Check | Result |
|---|---|
| `php tests/run.php` | PASS: 163 passed, 0 failed. Includes the seven-route HTTP role matrix, CSRF, delivery modes, corrupt stores, five named enrollment concurrency cases plus term PATCH concurrency, and T-00 through T-11a regression tests. Test harness uses private temporary runtime directories. |
| `php -l` on all files from `rg --files -g '*.php'` | PASS: 104 files, 0 syntax failures. |
| `php tests/Core/apache_check.php` | PASS for both public and repository document roots: routing, error envelopes, request rejection, and private-file protection. Initial sandboxed run could not let Apache access the existing `public` directory; the approved outside-sandbox rerun passed. |
| `php database/seeders/dev_sections.php` twice with fresh `APP_RUNTIME_PATH` under system Temp, `APP_REPOSITORY=mock`, `APP_ENV=testing` | PASS: first run created 2 terms and 4 sections; second reported no changes. SHA-256 of the store was identical before and after the second run. No shared runtime data was used. |
| `git diff --check 592d912..60ac236` | PASS: no whitespace errors. |

## Security findings

No new security defect found. All seven routes use authentication guards; writes require registrar or admin plus CSRF. Write services repeat role checks. The controller, service, and repository allowlists reject client changes to IDs, natural keys, `approved_count`, and `remaining`. Reads expose the documented catalog fields. Corrupt stores produce generic HTTP 500 responses without exposing paths or stored contents.

## Correctness findings

No correctness defect found. Term code and section triple uniqueness are checked under the shared store lock. Switching the current term, checking section term existence, and validating a merged PATCH against the approved count and delivery-mode meeting rule happen in locked transactions. Failed checks leave the store unchanged. Meeting validation enforces exact object keys, day/time/room formats, 14-meeting limit, and same-day overlap rules. `scheduled` requires a meeting; explicit `tba` and `online_async` can have none. `remaining` is computed from stored capacity and approved count.

## Integration findings

The implemented request and response shapes agree with the T-20 plan and `API_CONTRACTS.md`. The full suite passed, including existing authentication, admin, and student/academic-record tests. The reviewed branch contains no T-21 instructor assignments or T-22 enrollments.

## Known limitations

- A section written by the earlier T-20 implementation without `delivery_mode` is rejected as a corrupt row, making catalog routes return generic 500 until that runtime data is migrated or rebuilt. This is the documented fail-closed policy. Check for such data before deployment to any environment that ran the earlier commit.
- Seeder initialization consists of several repository transactions. A process failure midway can leave partial seed data; the seeder reports that condition and will not overwrite it on a rerun. This is documented and is not a production data migration path.
- Course identity is format-checked only, pending the planned course-catalog integration. The JSON store reads the full catalog on requests, as documented.
- The pre-existing Core unknown-field response uses a client-supplied field name as an error key. Consumers must render error keys as text. This was not introduced by T-20.
- The Apache check verifies transport and document-root protection; enrollment-specific HTTP behavior was exercised by the full test suite using the private PHP server.

## Merge blockers

None identified in this review. Owner approval to merge is still required by the coordination protocol.

## Final verdict

**APPROVE** for the exact reviewed head `60ac2366cb606b97825aab9278646f544a083970`. Later implementation commits require a new review.

---

## Report template for future reviews

Record the task and exact branch head, worktree, reviewer involvement in the implementation, independently run checks, findings by severity with file and line evidence, recommended corrections, limitations, merge blockers, and one verdict: `APPROVE`, `CHANGES_REQUESTED`, or `BLOCKED`.
