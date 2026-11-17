# Agent handoffs

Add the newest handoff at the top. A handoff is a request. It doesn't mean the receiving agent has read or accepted it.

---

## T-20 — Terms & Sections, after policy alignment → AGY review (prepared by Claude, 2026-11-17) — CURRENT

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
