# Agent handoffs

Add the newest handoff at the top. A handoff is a request. It doesn't mean the receiving agent has read or accepted it.

---

## T-21 → RE-REVIEW of corrected commit (prepared by Claude, 2026-11-20) — READY_FOR_REVIEW, not started

- **Why:** review #1, a fresh Codex session reviewing `54d1a6b`, returned **REQUEST_CHANGES** (`CODEX_T21_REVIEW_REPORT.md`).
  - **Medium:** C10 depended on scheduling.
  - **Low:** there was no race test for assign and unassign of the same pair.
  - No production defect was found.
- **Fix:** made by Codex (the test owner) and committed by Claude on Codex's behalf (sandbox git limit; Codex had exited, the change was reviewed and the suite run).
  - Commit **`2bc6a90`**, merged into the task branch as **`e46b543`**.
  - **Delta vs `54d1a6b`:** only `tests/Enrollment/EnrollmentConcurrencyTest.php` and `tests/Enrollment/fixtures/enrollment_worker.php`, plus docs. **No production code changed.**
  - **C10:** the three new assignments go to a second section whose meetings don't overlap section 1's. All six writes must still succeed, with `seq` 1..9 and the exact final state on both sections.
  - **New C11:** assign vs unassign of one pair, three rounds. Both permitted outcomes are asserted against history and replay.
  - **Worker:** it now also reports the error message.
- **Exact re-review target:** the head of `task/T-21-instructor-assignments` at the commit that adds this section. Everything after `e46b543` changes only `ai-coordination/*.md`. Report the full hash you reviewed. Minimum scope: `54d1a6b..<head>`. A full re-check of `229618b..<head>` is welcome.
- **Verified by Claude at `e46b543` (2026-11-20):**
  - `php tests/run.php`: **184 passed, 0 failed**, including C10 and C11.
  - `php -l`: 115 files, 0 failures.
  - `tests/Core/apache_check.php`: PASS on both document roots.
  - Real DELETE probe through private Apache: PASS on both roots (anonymous 401, no CSRF 403, unassigned 404, missing section 404, production assign 422, `instructors` `[]`, `PUT` 405).
- **Open from review #1, no fix requested:**
  - Mutation checks are reproduced independently for only 6 of 12 (M10, M11, M12, M18, M19, M20); the rest were checked by inspection.
  - The one-off Apache DELETE probe isn't committed. It lives in Claude's scratch folder; committing it would need a Core-owned test change.
  - Accepted limitations: the A4 window, and history that isn't tamper-evident against someone able to rewrite both lists.
- **Reviewer:** the owner assigns one (the fresh Codex review session or AGY when available). Review in a separate worktree at the new head and write the report to the reviewer's report file. Claude has no channel to the reviewers.

---

## T-21 → independent review (prepared by Claude, 2026-11-20) — REASSIGNED to a fresh CODEX review session

> **Review outcome #1 (2026-11-20):** fresh Codex review of `54d1a6b` → **REQUEST_CHANGES** (`CODEX_T21_REVIEW_REPORT.md`).
> - **Medium:** C10 test depends on scheduling (cap 409 possible on correct code).
> - **Low:** no same-pair assign/unassign race test.
> - No production defect.
> - Claude verified the Medium finding; both items are assigned to Codex (test owner). A new review of the corrected commit follows.

> **Reviewer change (owner, 2026-11-20):**
> - **AGY** was assigned first. It hasn't started and is now `UNAVAILABLE_USAGE_LIMIT`.
> - **Replacement:** the owner assigned a **fresh Codex review session** in the separate worktree `..\AIS_REVIEW_T21`, detached at `54d1a6b`.
> - **Report file:** `ai-coordination/CODEX_REVIEW_REPORT.md`. Add the newest report at the top and keep the T-20 report. `AGY_REVIEW_REPORT.md` stays as history.
> - **Independence caveat:** Codex implemented T-21 phase 2 (`99c92b6`, `cc1dbf5`). A Codex review of that code isn't fully author-independent. The report must say so, and the owner decides whether it is enough.
> - **Target:** this handoff's review target is `54d1a6b`. Later commits on the branch change only `ai-coordination/*.md`.

- **Branch:** `task/T-21-instructor-assignments`
- **Exact review target:** the head of that branch at the commit that adds this section. Report the full hash.
  - Last code commit: **`e92f328`** (phase 3). Everything after it changes only `ai-coordination/*.md`.
  - **Review range:** `229618b..<head>`, where `229618b` is `main`.
  - If code changes after your review, the review has to be repeated.
- **Spec:** `docs/plans/T-21-instructor-assignments.md`, approved at `ce47dae`: §1.1 owner additions, A1–A14, §3–§10.
- **Owner decisions:**
  - Q1–Q4.
  - **DELETE response:** removal never depends on identity verification or name lookup. Remaining names are resolved after the removal commits, and a lookup failure gives `null` without rolling back.
- **Commits:**

| Commit | Author | What |
|---|---|---|
| `ce47dae` | Claude | approved plan; contracts in `app/Enrollment/Contracts/` (`FacultyIdentity`, `FacultyIdentityLookup`, `UnavailableFacultyIdentity`, `SectionSummary`, `SectionDirectory`); B-11 |
| `c810188` | Claude | Codex handoff |
| `99c92b6` | Codex (committed by Claude*) | phase 2: model, repository, services, `RepositorySectionDirectory`, controller, tests, implementation report |
| `cc1dbf5` | Codex (committed by Claude*) | DELETE names per the owner decision, plus 3 regression tests |
| `ab251da` | Claude | merge of `task/T-21-impl` |
| `e92f328` | Claude | phase 3: bootstrap (explicit `UnavailableFacultyIdentity`), the 2 routes, README, `API_CONTRACTS.md`, the comment-only fix in `FacultyStudentVisibility.php` |

\* Codex's sandbox can't write a linked worktree's git metadata. Claude committed Codex's changes unedited, after confirming Codex had exited, reviewing the changes, checking scope and running the suite (owner policy, 2026-11-20).

### Verified by Claude at `e92f328` (2026-11-20)

| Check | Result |
|---|---|
| `php tests/run.php` | **183 passed, 0 failed.** The 4 phase-2 expected failures are resolved by the wiring, with no assertion changed. |
| `php -l` | 115 files, 0 failures |
| `tests/Core/apache_check.php` | PASS, public and repository document roots |
| Real DELETE through private XAMPP Apache, both document roots, real session + CSRF (one-off probe, not committed) | anonymous 401; no CSRF 403; unassigned pair 404 "Instructor assignment not found."; missing section 404; production assign 422 "Unknown or inactive faculty member."; `instructors` `[]`; `PUT` 405 |
| Successful DELETE 200 | Not possible in production, where nobody can be assigned. Covered over real HTTP (PHP server) by Codex's seeded-store test `Assignment HTTP: reads expose instructors and seeded DELETE audits once` |
| Concurrency C6–C10 | pass within the suite (Codex: 3 rounds each) |
| Mutation checks M9–M20 | **12/12 killed**, run by Codex in a scratch copy with phase-3 wiring simulated. **Not re-run by Claude.** M17 survived at first; Codex strengthened the matrix so a denied role gets 403 before body validation, and it was killed on rerun. See `T21_IMPLEMENTATION_REPORT.md`. |

### Claude's review of `99c92b6` and `cc1dbf5` (summary)

- **Scope:** only Codex's assigned files. Contracts, bootstrap, routes, Students and Core untouched.
- **Authorization:** guard (registrar/admin + CSRF) plus `requireRole` in the service. The body allowlist is `faculty_id` only, and DELETE refuses any body field.
- **Identity:** checked before the lock (A4). Null, inactive, a mismatch or a `Throwable` all give the same 422 (A8). Unassign never calls `find()`.
- **Conflicts:** one helper for assign and section PATCH. Same term, same day, `a.start < b.end && b.start < a.end`. Every meeting counts, whatever the mode (Q1). The 409 names only the clashing section.
- **Atomicity:** the existence, duplicate, cap (5), conflict and history-append steps all happen in one `JsonStore` transaction.
- **Snapshot:** validates both new keys (exact shapes, known sections, no duplicates, at most 5, no stored conflict), checks that history `seq` is gap-free, and replays history against `assignments`. T-20 stores load unchanged.
- **A14:** `isAssigned` and `sectionIdsForFaculty` require a confirmed active identity; `assignedFacultyIds` isn't filtered.
- **T-20 tests:** the exact-response tests now assert the full key order **and** `instructors === []`. They're stricter, not weaker.
- **Findings:** none blocking. The only change was the DELETE names, required by the owner's decision.

### Focus areas for AGY

1. **RBAC and CSRF** on the two new routes; the service-level `requireRole`; faculty and students can't assign or unassign.
2. **Production fail-closed:** the only `FacultyIdentityLookup` in `app/` is `UnavailableFacultyIdentity`; the stub lives under `tests/` only; nothing selects a lookup by config or env.
3. **Conflict detection:** correctness and atomicity (C6, C9), the PATCH re-check, cross-term and back-to-back cases, and meetings of `tba` and `online_async` sections counting.
4. **Audit history:** append-only, gap-free, exactly one entry per success, none on failure; replay validation.
5. **Corrupt store handling:** a generic 500, nothing written.
6. **A14:** retained assignments of inactive faculty grant no access.
7. **DELETE:** never blocked by identity or name lookup.
8. **API compatibility with T-20** (only the additive `instructors` key) and T-00 to T-20 regression.

### Known limitations (documented)

- **Production assigns nothing until T-30.** That's by design.
- **B-11** (user id ↔ `faculty_id`) blocks T-31.
- **The A4 window:** the identity is checked before the lock, so a faculty member deactivated in those milliseconds could still be assigned.
- Replay and conflict validation scale linearly with store size (until T-40).
- L-3 (Core error keys).
- DELETE 200 is covered only with a seeded store.

### Review request

- Read-only review in a separate worktree, for example `git worktree add ..\AIS_REVIEW_T21 <head>`. Use your own `APP_RUNTIME_PATH`, and coordinate with Claude before running the Apache check.
- Write the report to `ai-coordination/AGY_REVIEW_REPORT.md`, with the exact commit and a verdict.
- Claude has no channel to AGY; the owner starts it.

---

## T-21 phase 2 → CODEX implementation (prepared by Claude, 2026-11-20) — ASSIGNED, not confirmed

- **Owner authorization:** T-21 implementation approved on 2026-11-20. Codex implements the backend and the tests.
- **Spec (authoritative):** `docs/plans/T-21-instructor-assignments.md`, approved revision at `ce47dae`. Follow §1.1, §2 (A1–A14), §3–§7, §9 and §12 exactly.
- **Baseline:** branch `task/T-21-instructor-assignments` at the commit that adds this handoff. Its code is identical to phase-1 commit `ce47dae`, which contains Claude's contracts in `app/Enrollment/Contracts/`. `main` is at `229618b`.
- **Your branch and worktree:** `task/T-21-impl` in `..\AIS_CODEX_T21`. Claude creates both from that commit. Work and commit only there.

### Files you own (phase 2; plan §12) — edit nothing else

- `app/Enrollment/Models/Section.php`
- `app/Enrollment/Repositories/CatalogRepositoryInterface.php`
- `app/Enrollment/Repositories/MockCatalogRepository.php`
- `app/Enrollment/Services/SectionService.php`
- `app/Enrollment/Services/RepositorySectionDirectory.php` (new)
- `app/Enrollment/Controllers/SectionController.php`
- `tests/Enrollment/**`: the existing files, plus the new `AssignmentServiceTest.php`, `SectionDirectoryTest.php`, `AssignmentHttpTest.php`, `FacultyIdentityPortTest.php` and `fixtures/StubFacultyIdentity.php`
- `ai-coordination/T21_IMPLEMENTATION_REPORT.md` (new)

### Files you must not touch

- `app/Enrollment/Contracts/*`. These are Claude's. If a contract change is needed, stop and report.
- `app/bootstrap.php`, `routes/enrollment.php`, `README.md`, `app/Students/**`, `app/Core/**`, `app/Auth/**`, `database/**`.
- `ai-coordination/*` except your report, and `docs/**`.
- Any other agent's worktree.

Bootstrap and routes wiring is Claude's phase 3. Until then:
- `SectionService` takes the optional `?FacultyIdentityLookup` as its third argument. `null` means `UnavailableFacultyIdentity`.
- `RepositorySectionDirectory` takes `(CatalogRepositoryInterface, FacultyIdentityLookup)`.

### Binding requirements

1. **Ownership:** Team 4 owns the assignments. Store only `{section_id, faculty_id}`, with no faculty data copied. Names come from `FacultyIdentityLookup::findMany` at read time; if it fails, `name` is `null` (A9).
2. **Fail closed in production:** with `UnavailableFacultyIdentity`, every assign gets 422 and nothing is written. Test doubles exist **only** under `tests/`; never in `app/`, never selected by config or env.
3. **Identity refusal:** null, inactive, an id mismatch or any `Throwable` all give 422 `{faculty_id: "Unknown or inactive faculty member."}` (A8). The lookup runs before the lock (A4). Unassign never calls the lookup (A7).
4. **Conflict rule:** same `term_id`, any overlapping meeting (same day, `a.start < b.end && b.start < a.end`) gives **409**. Every actual meeting counts, including those of `tba` and `online_async` sections (Q1). Sections with no meetings never conflict. The 409 names only the clashing section.
   - The check runs atomically in the single store lock, as one helper shared by `assignInstructor` and `updateSection` (A3, A5).
   - A section PATCH that would create a conflict gives 409 and writes nothing.
5. **Cap and duplicates:** at most 5 instructors per section; a 6th gives 409. A duplicate pair gives 409.
6. **Audit:** the append-only `assignment_history` (`seq` gap-free, with `at`, `action`, `section_id`, `faculty_id`, `actor_id`) is written in the same transaction. Failures write nothing.
7. **Store:**
   - New top-level keys `assignments` and `assignment_history`. If they're missing, they're empty, so T-20 stores load unchanged.
   - Validation fails closed on: an unknown section, a duplicate, more than 5, a stored conflict, malformed history, or a history replay that doesn't match `assignments` (A11).
8. **Inactive faculty (Q4, A14):**
   - Retained assignments and history are kept and shown.
   - New assignments to inactive faculty are refused.
   - `RepositorySectionDirectory::isAssigned` and `sectionIdsForFaculty` count an assignment only when `find()` returns an active identity with the same id, and fail closed otherwise. `assignedFacultyIds` isn't filtered.
9. **Authorization:** registrar/admin only (the guard is wired in phase 3), plus `requireRole` in the service. Section reads stay open to any signed-in user.
10. **T-20 tests:** update the exact-response tests for the new `instructors` key **without weakening them**: keep exact key order and value equality, and don't relax anything to a subset. Grep `tests/Enrollment/` for every exact section-shape assertion.
11. **No schema or DB changes.** No T-22, T-30 or T-31 work. `approvedStudentIds` returns `[]` with the `shortcut:` comment.

### Required tests and checks (plan §9)

- Unit tests (§9.1), directory tests including A14 (§9.2), store tests (§9.3), and the HTTP tests (§9.4).
- Concurrency races C6–C10 with worker processes (§9.5).
- The production fail-closed test and the source-scan test (§9.6).
- Mutation checks M9–M20 (§9.7), in a scratch copy **outside every worktree**.
- Run `php tests/run.php` (all 163 existing tests must still pass) and `php -l` on every PHP file.
- **Expected red until phase 3:** the HTTP route tests (§9.4) and the production-wiring test (§9.6) need Claude's bootstrap and routes wiring. List each one by name in your report. Everything else must be green.
- **Runtime:** set `APP_RUNTIME_PATH` to a scratch folder outside all worktrees and outside OneDrive. Don't run the Apache check; Claude runs it in phase 3.

### Deliverables

- Commit on `task/T-21-impl`. Commits only there; no merge, no push, no history rewriting.
- Write `ai-coordination/T21_IMPLEMENTATION_REPORT.md`, covering:
  - files changed and any deviations from the plan, with the reason
  - test counts (pass, fail, and the expected-red list)
  - the `php -l` result
  - mutation results
  - risks
  - the exact commit hash
- Then stop. Claude reviews and integrates. AGY reviews after phase 3.

---

## T-20 — MANDATORY AGY review before merge (owner decision, 2026-11-20) — REVIEWED, MERGED

> **Outcome:** AGY APPROVE on `81097d5` (`AGY_REVIEW_REPORT.md`). Merged into `main` as `8c2b980` on 2026-11-20. Not pushed.

- **Owner decision:** AGY's independent review is mandatory. T-20 may be merged locally only if all of the following hold:
  - AGY reports **APPROVE** on the **exact final revision**.
  - No Critical, High or Medium findings, and no merge blockers, remain unresolved.
  - Tests, `php -l` and the Apache check pass.
  - The tree is clean.
  - The merge brings in no unrelated changes.
- **Exact review target:** the head of `task/T-20-terms-sections` at the commit that adds this section. Report the full hash you reviewed.
  - The last code change is `0624eb7`. Everything after it changes only `ai-coordination/*.md`.
  - Review range: `592d912..<head>`.
  - If anything other than docs changes after your review, the review has to be repeated.
- **Background:** Codex reviewed `60ac236` (APPROVE) but wrote the phase-1 backend (`b04fec4`), so that review isn't fully independent of the author.
- **Not started:** Claude has no channel to AGY. The owner starts AGY. Write the report to `ai-coordination/AGY_REVIEW_REPORT.md` using its template.
- **Worktree:** review in a separate worktree, for example `git worktree add ..\AIS_REVIEW_T20 <head>`. Use your own `APP_RUNTIME_PATH`. Don't run the seeder against a shared runtime. Coordinate the Apache check, because it uses fixed ports.
  - **Priority files for an author-independent review:** `app/Enrollment/{Models,Repositories,Services,Controllers}/*`, `routes/enrollment.php`, and the Enrollment wiring in `app/bootstrap.php`.
  - **Focus areas:** the same as the Codex handoff below.
- **If the owner skips it:** T-20 merges on the strength of the Codex APPROVE, with the caveat on record.
- **Status:** not assigned to AGY. Claude has no channel to AGY; the owner starts it.
- **Re-verified at `41e8a56` (2026-11-20):** 163/163 tests, 104/104 lint, Apache PASS on both document roots, clean tree.

---

## T-20 — Terms & Sections → CODEX independent review (prepared by Claude, 2026-11-17) — REVIEWED

> **Outcome:** Codex APPROVE on `60ac236` (`CODEX_REVIEW_REPORT.md`). `60ac236..6bec6e6` changes only `ai-coordination/*.md`. The post-review integration check passed (see `T20_IMPLEMENTATION_REPORT.md` §12).

- **Status:** READY_FOR_CODEX_REVIEW.
  - The reviewer was reassigned from AGY (`UNAVAILABLE_USAGE_LIMIT`) to Codex by the owner.
  - **Not started:** Codex hasn't confirmed.
  - The AGY handoff below is superseded but kept.
- **Branch:** `task/T-20-terms-sections`
- **Exact review target:**
  - Code: **`0624eb7`**, the last commit that changes code or tests. It was verified by Claude: 163/163 tests, 104/104 lint, Apache PASS. 163/163 was re-run at `60ac236` on 2026-11-17.
  - The commits after it (`60ac236` and the Codex-reassignment commit) change only `ai-coordination/*.md`.
  - **Review range:** `592d912..task/T-20-terms-sections` (base `main` `592d912`). Report the exact head hash you reviewed.
- **Independence caveat:**
  - **Codex wrote the phase-1 backend in `b04fec4`:** `app/Enrollment/{Models,Repositories,Services,Controllers}/*`, `routes/enrollment.php`, the Enrollment wiring in `app/bootstrap.php`, and the README Enrollment section.
  - Claude later changed `Section.php`, `SectionService.php`, `SectionController.php` and `MockCatalogRepository.php` in `0624eb7`. Claude's backend-developer agent made the L-1 fix to `MockCatalogRepository.php`.
  - Codex didn't write the tests (Claude's qa-engineer), the seeder (Claude's database-engineer) or the plan (Claude's architect).
  - Please review in a fresh session, without relying on memory of writing the code, and state the caveat in the report.

### Worktree safety

- Review in a **separate worktree**. The owner can create one, for example `git worktree add ..\AIS_REVIEW_T20 task/T-20-terms-sections`. Don't use the shared working tree at `AIS_SYSTEM`.
- Claude won't edit `task/T-20-terms-sections` until the review is reported, unless the owner says otherwise.
- Test runs write runtime files, so point `APP_RUNTIME_PATH` at a scratch folder outside both trees. Don't run the seeder against a shared runtime.
- **No application source edits, commits, merges or pushes.** The only file Codex writes is `ai-coordination/CODEX_REVIEW_REPORT.md`, and only in its own worktree. The owner or Claude copies the report back.

### What to review

- **Scope:** everything in `592d912..HEAD`. The details of the original implementation and of the policy change (`delivery_mode`) are in the superseded handoffs below and in `T20_IMPLEMENTATION_REPORT.md` (§1–§11).
- **Specs:**
  - `docs/plans/T-20-terms-sections.md` (§3 API, P9, §5–§6, §14–§16)
  - `ai-coordination/API_CONTRACTS.md` (Team 4 section and `Section` shape)
  - `ai-coordination/ARCHITECTURE.md` §6 (security checklist)
- **Focus areas:**
  1. **Authorization:** RBAC and CSRF on all 7 routes. The service-level `requireRole` on writes. Anonymous, student and faculty can't write.
  2. **Mass assignment:** allowlists at the controller, service and repository layers. `approved_count`, `remaining`, `id`, `term_id`, `course_code`, `section_code`, `code` are not client-writable, and `delivery_mode` is the only new writable field.
  3. **Concurrency:** these checks all run inside one lock:
     - unique term code
     - a single current term
     - the term exists
     - unique section triple
     - capacity ≥ `approved_count`
     - the mode/meetings rule on the merged row during PATCH
  4. **Fail-closed storage:** `snapshot()` and `fromArray`, including duplicate natural keys, invalid or missing `delivery_mode`, and a `scheduled` row with no meetings. Failures write nothing.
  5. **Delivery mode:** scheduled needs at least 1 meeting; only an explicit `tba` or `online_async` may have 0; no TBA inference from an empty list; overlap unchanged for every mode.
  6. **Input validation:** meetings as `stdClass` with exact keys, HH:MM times, the Unicode control and format character filter, and array-valued query parameters refused.
  7. **Regression:** T-00 to T-11a behaviour unchanged.
  8. **Seeder:** command line only, mock repository only, never overwrites, and reports a partial failure.

### Checks to run independently

- `php tests/run.php`. Expected: 163 passed.
- `php -l` on every PHP file. Expected: 104 files.
- `php tests/Core/apache_check.php`. This needs XAMPP and must run outside a sandbox.
- Any additional authorization, contract or concurrency probes you think are needed. Keep them in your worktree or a scratch folder.

### Known limitations (already documented, no need to re-report)

- L-3: "Unknown field" errors use client-supplied keys (Core, predates T-20).
- I-3: the store accepts only the top-level keys `terms` and `sections`, so T-21 and T-22 must extend the list.
- D1: course data stored on the section.
- Whole-store reads on every request.
- Mutation M4 is equivalent: the service repeats the role check.
- Claude's security-reviewer wasn't re-run on `0624eb7`.

### Report

Write to `ai-coordination/CODEX_REVIEW_REPORT.md` using its template, with:

- task ID, branch and exact commit hash
- the tests you ran
- findings by severity, with evidence and file paths
- recommended corrections
- the independence caveat
- a verdict: `APPROVE`, `CHANGES_REQUESTED` or `BLOCKED`

Claude will treat the review as complete only after reading that report.

---

## T-20 — Terms & Sections, after policy alignment → AGY review (prepared by Claude, 2026-11-17) — SUPERSEDED (AGY unavailable; reassigned to Codex above)

- **Status:** READY_FOR_AGY_REVIEW. This replaces the `909a000` handoff below.
- **Branch:** `task/T-20-terms-sections`
- **Commits to review:**
  - `b04fec4`: the original implementation.
  - `909a000`: hash record.
  - **`0624eb7`: policy alignment (delivery_mode), the QA-found bug fix, decision records and the coordination files.**
  - The final handoff/status commit, which comes after `0624eb7`, changes Markdown only.
  - Diff base: `main` `592d912`. **Review range: `592d912..task/T-20-terms-sections`.** Pay particular attention to `909a000..0624eb7`.
- **Owner decisions applied:** Q1–Q7 (see `T20_IMPLEMENTATION_REPORT.md` §11 and plan §14–§16).

### Verified by Claude at `0624eb7` (2026-11-17)

| Check | Result |
|---|---|
| `php tests/run.php` | 163 passed, 0 failed |
| `php -l`, every PHP file | 104 files, 0 failures |
| `php tests/Core/apache_check.php` (XAMPP) | PASS, public and repository document roots |
| QA mutation checks for the new rules | all killed |

### What changed since `909a000`

- **New section field `delivery_mode`:** one of `scheduled` (the default), `tba` or `online_async`. Optional on create; PATCH can change it.
- **Meetings:**
  - `scheduled` needs 1–14 meetings.
  - `tba` and `online_async` may have 0–14.
  - An empty list is never treated as TBA (422).
  - The overlap check is unchanged.
- **PATCH:** the mode/meetings rule is checked on the merged row inside the lock (`SectionService::update`, the `$check` closure).
- **Stored rows:** `Section::fromArray` now requires a valid `delivery_mode` and at least one meeting for `scheduled` rows. A store without the field fails closed (generic 500).
- **Bug fix:** on create, a per-item meeting error no longer adds a misleading "needs a meeting" error.
- **Tests:** about 6 new or extended tests: service, HTTP over real sessions, and 6 new corrupt-store cases.
- **Decisions recorded:** future archival requirements (plan §15), the impact on T-21 and T-22 (plan §16), and the 5-instructor cap for T-21.
- **New files:** the coordination files `AGENT_*.md` and `AGY_REVIEW_REPORT.md`.

### Security-sensitive areas (delta)

1. **Mass assignment:** `delivery_mode` was added to the controller, service and repository allowlists. Check that nothing else was widened.
2. **Merged-row check:** it must run inside the store lock. A rejected PATCH must leave the file byte-identical.
3. **Strict validation:** `delivery_mode` must accept only the three exact strings. Check that `null`, `"TBA"`, arrays and other values are refused.
4. **Fail-closed rows:** `Section::fromArray` and `snapshot()` must still refuse invalid stored rows.
5. Everything listed in the earlier handoff below still applies.

### Known limitations (delta)

- Claude's own security-reviewer was **not** re-run for this change. AGY's review is the security review for it.
- Two older tests were loosened while the bug was open. The new regression test asserts the exact behaviour.
- The earlier limitations still apply: L-3, I-3, D1, store performance, and mutation M4.

### Review request for AGY

1. Read-only review of `592d912..task/T-20-terms-sections`, focusing on `909a000..0624eb7`. Check it against the plan (§3.2, P9, §14–§16) and `API_CONTRACTS.md`.
2. Independently run `php tests/run.php`, `php -l` and `php tests/Core/apache_check.php`.
   - Preferably do this in a separate worktree or clone at the branch head, because the test runs write runtime files.
   - Don't run the seeder against the shared `runtime/`.
3. Check for regressions in T-00 to T-11a.
4. Write the report to `ai-coordination/AGY_REVIEW_REPORT.md`, including the exact commit reviewed and a verdict.

---

## T-20 — Terms & Sections → AGY review (prepared by Claude, 2026-11-17) — SUPERSEDED by the handoff above

- **Status:** READY_FOR_AGY_REVIEW
- **Branch:** `task/T-20-terms-sections`
- **Commits to review:**
  - `b04fec4`: implementation, tests, seeder, plan, B-04 docs.
  - `909a000`: records the hash in the report and task board.
  - Diff base: `main` `592d912`. The review range is `592d912..909a000`.
- **Owner decision still open:**
  - The owner has mentioned support for **TBA and online asynchronous sections**. It is **not implemented**: `SectionService::meetings()` requires 1–14 meetings (plan P9 and Q3).
  - If the owner approves that change, Claude will implement it in a new commit, and **this handoff will be replaced**. AGY should then review the new commit; an approval of `909a000` wouldn't cover it.
  - The owner should say whether AGY reviews `909a000` now or waits.

### Verified by Claude at `909a000` (2026-11-17)

| Check | Result |
|---|---|
| `php tests/run.php` | 158 passed, 0 failed (re-run at `909a000`) |
| `php -l`, every PHP file | 104 files, 0 failures (run before the commit; the second commit changed only Markdown) |
| `php tests/Core/apache_check.php` (XAMPP) | PASS, public and repository document roots (run before the commit) |
| `git status` | clean |

### Implementation summary

- **Terms:**
  - `GET /api/terms` for anyone signed in.
  - `POST /api/registrar/terms` and `PATCH /api/registrar/terms/{id}` for registrar and admin, with CSRF.
  - Term `code` is unique and can't be changed. At most one term is current, switched atomically. Dates must be real, with `start < end` on the merged row. Terms carry an `enrollment_open` flag.
- **Sections:**
  - `GET /api/sections` (filters `term_id`, `course`, `search`, and `page`/`per_page`) and `GET /api/sections/{id}` for anyone signed in.
  - `POST /api/registrar/sections` and `PATCH /api/registrar/sections/{id}` for registrar and admin, with CSRF.
  - `(term_id, course_code, section_code)` must be unique; those three fields can't be changed.
  - The term must exist (422 otherwise).
  - Meetings: 1–14 per section, with no overlap between a section's own meetings.
  - Capacity is 1–1000 and never below `approved_count`. `approved_count` can't be set by clients. `remaining` is computed.
- **Storage:** one JSON store, `runtime/enrollment/enrollment.json`. Every rule that depends on other rows is checked inside one exclusive-lock transaction, and the snapshot is validated so a bad store fails closed (generic 500).
- **Not included:** T-21 (instructors) and T-22 (enrollments). There is no `instructors` key yet, and no database or schema change.
- **Full detail:** `docs/plans/T-20-terms-sections.md` and `ai-coordination/T20_IMPLEMENTATION_REPORT.md`.

### Files changed (`592d912..909a000`)

- `app/Enrollment/Models/{Term,Section}.php`
- `app/Enrollment/Repositories/{CatalogRepositoryInterface,MockCatalogRepository}.php`
- `app/Enrollment/Services/{TermService,SectionService}.php`
- `app/Enrollment/Controllers/{TermController,SectionController}.php`
- `routes/enrollment.php`
- `app/bootstrap.php` (Enrollment wiring only)
- `database/seeders/dev_sections.php`
- `tests/Enrollment/*` (6 test files plus `fixtures/enrollment_worker.php`)
- `README.md` (Enrollment section)
- `docs/plans/T-20-terms-sections.md`
- `ai-coordination/{TASK_BOARD,API_CONTRACTS,ARCHITECTURE,INTEGRATION_BLOCKERS,T20_IMPLEMENTATION_REPORT}.md`

### Security-sensitive areas to focus on

1. **RBAC and CSRF** on all 7 routes (`routes/enrollment.php`), plus the service-level `requireRole` in `TermService` and `SectionService`.
2. **Mass assignment:** check the allowlists at the controller, service and repository (`*_MUTABLE`) layers. Clients must not be able to set `approved_count`, `remaining`, `id` or the fixed fields.
3. **Locking:** these checks must all run inside the lock in `MockCatalogRepository`:
   - unique term code
   - a single current term (`clearCurrent`)
   - the term exists
   - unique section triple
   - capacity ≥ `approved_count` on the merged row (the `$check` closure)
4. **Fail-closed snapshot validation** (`snapshot()`), including the L-1 duplicate natural-key checks. Nothing should be written when a write fails.
5. **Input validation:** meetings must be `stdClass` objects with exact keys; check the HH:MM rules, the overlap rule, the Unicode control and format character filter, and that array-valued query parameters are refused.
6. **Seeder:** command line only, mock repository only, never overwrites, and the L-2 partial-failure reporting.

### Known limitations and open items

- **L-3 (Core, predates T-20):** "Unknown field" errors use client-supplied keys. The frontend must render them as text only.
- **I-3:** the snapshot allows only `terms` and `sections` as top-level keys, so T-21 and T-22 must extend that list.
- **D1:** course data is stored on the section and checked for format only.
- **Store performance:** every request reads the whole store (fine until T-40).
- **Mutation M4:** a weakened route guard isn't caught on its own, because the service-level check returns the same 403.
- **Seeder:** the partial-failure path wasn't exercised.
- **Owner questions Q1–Q7 (plan §14):** still unconfirmed. Q3 (TBA/online) is now pending as a requested change.

### Review request for AGY

1. Read-only review of `592d912..909a000`: security, RBAC, API contract against `docs/plans/T-20-terms-sections.md` §3, and concurrency and fail-closed behaviour.
2. Independently run `php tests/run.php`, `php -l` and `php tests/Core/apache_check.php`.
   - Preferably do this in a separate worktree or clone at `909a000`, because the test runs write runtime files and the working tree is shared.
   - Don't run the seeder against the shared `runtime/`.
3. Confirm that T-00 to T-11a behaviour is unchanged (regression).
4. Write the report to `ai-coordination/AGY_REVIEW_REPORT.md` using its template, with a verdict.
