# T-20 implementation report — Terms & Sections (Team 4)

- **Branch:** `task/T-20-terms-sections`, from `main` `592d912`. Not merged, not pushed.
- **Status:** READY_FOR_REVIEW. Awaiting independent review (AGY) and owner approval.
- **Plan:** `docs/plans/T-20-terms-sections.md` (architect). Owner approval: 2026-11-17, which also resolved B-04.

## 1. Scope implemented

- **Academic term management:**
  - list terms
  - registrar/admin create and update
  - unique, immutable `code`
  - real calendar dates with `start_date < end_date` on the merged row
  - at most one `is_current` term, switched atomically
  - an `enrollment_open` flag for T-22 (B-08)
- **Section management:**
  - list (filters + pagination), detail, registrar/admin create and update
  - unique `(term_id, course_code, section_code)`
  - the term must exist
  - meetings validated
  - capacity 1–1000 and never below `approved_count`
  - `remaining` is computed and never stored
- **Validation and RBAC:**
  - strict allowlists, so unknown or immutable fields → 422 and `approved_count` is never client-writable
  - reads open to any signed-in user; writes for registrar/admin with CSRF, checked again in the service
- **Repository and concurrency:**
  - one `CatalogRepositoryInterface` with a JSON mock over one store file
  - every rule that depends on other rows is checked inside one exclusive-lock transaction
  - a corrupt store fails closed (generic 500, nothing written)
- **Seeder:** a development seeder with fictional data.
- **Not included:**
  - T-21 (instructors, `SectionDirectory`) and T-22 (enrollments)
  - no `instructors` key in section JSON
  - no database or schema changes

## 2. API endpoints

| Method | Path | Guard | CSRF | Success | Errors |
|---|---|---|---|---|---|
| GET | `/api/terms` | signed in | — | 200 `terms[]`, by `start_date`, `id` | 401 |
| POST | `/api/registrar/terms` | registrar, admin | ✔ | 201 `term` | 401, 403, 409 code, 415, 422 |
| PATCH | `/api/registrar/terms/{id}` | registrar, admin | ✔ | 200 `term` | 401, 403, 404, 415, 422 |
| GET | `/api/sections` | signed in | — | 200 `sections[]` + `pagination` | 401, 422 |
| GET | `/api/sections/{id}` | signed in | — | 200 `section` | 401, 404 |
| POST | `/api/registrar/sections` | registrar, admin | ✔ | 201 `section` | 401, 403, 409 triple, 415, 422 (incl. unknown term) |
| PATCH | `/api/registrar/sections/{id}` | registrar, admin | ✔ | 200 `section` | 401, 403, 404, 409 capacity, 415, 422 |

**Shapes:**
- Term: `{id, code, name, start_date, end_date, is_current, enrollment_open, created_at, updated_at}`.
- Section: `{id, term_id, course_code, course_title, units, section_code, capacity, approved_count, remaining, meetings[{day,start,end,room}], created_at, updated_at}`.

**List query:** `term_id`, `course`, `search` (≤ 100 chars), `page` (1–1e6) and `per_page` (1–100, default 20). Arrays and out-of-range values → 422.

**What PATCH can change:**
- Terms: `name`, dates, `is_current`, `enrollment_open`.
- Sections: `course_title`, `units`, `capacity`, `meetings` (the list is replaced as a whole).

**Meetings:**
- 1–14 JSON objects, each with exactly `day` (MON..SUN), `start`/`end` (HH:MM, start < end) and `room` (1–30 chars).
- No two meetings on the same day may overlap (422). Back-to-back meetings are allowed.
- Stored sorted by day, then start.

## 3. Files changed

| File | Owner (agent) |
|---|---|
| `app/Enrollment/Models/{Term,Section}.php` (new) | backend (Codex) |
| `app/Enrollment/Repositories/{CatalogRepositoryInterface,MockCatalogRepository}.php` (new) | backend (Codex); L-1 fix by backend-developer |
| `app/Enrollment/Services/{TermService,SectionService}.php` (new) | backend (Codex) |
| `app/Enrollment/Controllers/{TermController,SectionController}.php` (new) | backend (Codex) |
| `routes/enrollment.php` | backend (Codex) |
| `app/bootstrap.php` (Enrollment wiring only) | backend (Codex) |
| `README.md` (new Enrollment section) | backend (Codex) |
| `database/seeders/dev_sections.php` (new) | database-engineer |
| `tests/Enrollment/{EnrollmentSupport,TermServiceTest,SectionServiceTest,CatalogStoreTest,EnrollmentHttpTest,EnrollmentConcurrencyTest}.php`, `tests/Enrollment/fixtures/enrollment_worker.php` (new) | qa-engineer |
| `docs/plans/T-20-terms-sections.md` (new) | architect; seeder lines aligned by the lead after L-2 |
| `ai-coordination/{ARCHITECTURE,INTEGRATION_BLOCKERS}.md` (B-04) | architect |
| `ai-coordination/{TASK_BOARD,API_CONTRACTS}.md`, this report | lead (Claude Code) |

## 4. Agent ownership and sequence

**Roles:**
- **Claude Code:** lead. Did the planning hand-off and review of the backend code, plus the docs, B-04 contract edits, board and report.
- **architect:** plan with file-ownership table.
- **Codex** (via the Codex plugin): backend phase 1.
- **qa-engineer:** tests and mutation checks.
- **database-engineer:** seeder.
- **security-reviewer:** read-only review.
- **Fixes:** backend-developer (L-1), database-engineer (L-2), qa-engineer (L-1 tests).

**Order:** architect → Codex backend (alone, because `bootstrap.php` is shared) → QA tests and seeder in parallel (separate files) → security review → fixes by the owning agents (L-1 backend and L-2 seeder in parallel, separate files) → QA re-run.

No two agents edited the same file at the same time.

## 5. Tests and results (2026-11-17)

| Check | Result |
|---|---|
| `php tests/run.php` | **158 passed, 0 failed.** That is 124 from before T-20 plus 34 new: 7 term, 9 section, 5 store, 7 HTTP, 6 concurrency |
| `php -l`, every tracked and new PHP file | 104 files, 0 failures |
| `tests/Core/apache_check.php` | PASS, public and repository document roots |
| HTTP role matrix | all 7 routes × anonymous, student, faculty, registrar, admin, with exact 401/403 bodies. Missing or invalid CSRF → 403 on every write |
| Concurrency (real worker processes) | **C1** duplicate term code: exactly one winner. **C2** concurrent `is_current` switches: exactly one current. **C3** duplicate section triple: one winner. **C4** capacity PATCH racing `approved_count` bumps: never below the count. **C5** concurrent PATCHes of different fields: all kept |
| Corrupt store | 25 variants (including duplicate code and duplicate triple after L-1). Each throws on every read and write, leaves the file byte-identical, gives a generic 500 over HTTP and leaks no path |
| Mutation checks (scratch copy, workspace untouched) | M1–M3, M5–M8 and the L-1 mutant killed. M4 (POST-section guard reduced to `authenticated`) survives on its own because the service-level `requireRole` returns the same 403. That second layer working is the reason it survives. M4 together with M8 is killed |
| Seeder | first run seeds 2 terms and 4 sections. A re-run changes nothing (exit 0), checked against a temporary runtime directory. The partial-failure path was not exercised |

## 6. Security findings

| ID | Severity | Finding | Outcome |
|---|---|---|---|
| L-1 | Low | The store snapshot check didn't reject duplicate term codes or section triples, so a hand-edited or restored file could load with ambiguous rows | **Fixed** in `MockCatalogRepository::snapshot()`. Two corrupt-store test cases were added and the mutant is killed |
| L-2 | Low | The seeder's 6 separate writes could leave a partly seeded store that a re-run silently skipped | **Fixed.** It now checks terms and sections and reports the state. A failure partway reports a possible partial seed and says a reset is manual and needs owner approval. The plan was updated to match (exit 0 when data already exists) |
| L-3 | Low | "Unknown field" errors use client-supplied field names as keys. This is Core `Request` behaviour that predates T-20 and is not exploitable in JSON | **Open, out of T-20 scope.** The frontend (Team 1) must render error keys and messages as text only. A Core change, such as collapsing to `body` or capping key length, needs a separate task |
| I-1 | Info | U+2028/U+2029 and private-use characters are allowed in names, titles and rooms; control and format characters are refused | Not changed |
| I-2 | Info | Each request reads the whole store, with no limit on terms or sections (only staff can write) | Accepted until T-40 |
| I-3 | Info | The snapshot allows only the top-level keys `terms` and `sections` | **T-22 and T-21 must extend this list**, or every catalog endpoint returns 500 |
| I-4 | Info | The 409 capacity message includes `approved_count` (staff only; already visible as `remaining`) | No leak |

**Passed:**
- RBAC and CSRF on all routes.
- Three layers of allowlists.
- Strict input types and bounds.
- Every rule that depends on other rows is checked inside the lock.
- Nothing is written on failure.
- No error leakage.
- The seeder runs from the command line only and only against the mock repository.

## 7. B-04 integration contract (owner decision 2026-11-17)

Full detail is in plan §9, `ARCHITECTURE.md` §4.1, `API_CONTRACTS.md` §2–§4 and `INTEGRATION_BLOCKERS.md` B-04 (RESOLVED).

- **Team 4** owns the authoritative instructor-to-section assignment: storage of `{section_id, faculty_id}` in the enrollment store, the rules, and the endpoints `POST`/`DELETE /api/registrar/sections/{id}/instructors` (registrar/admin, CSRF).
- **Audit:** every successful change appends one entry `{seq, at, action, section_id, faculty_id, actor_id}` to `assignment_history`, in the same transaction.
- **Team 5** owns faculty profiles and grades. It implements Team 4's `FacultyIdentityLookup` port (`find`, `findMany` → `{faculty_id, display_name, active}`), and reads assignments only through `SectionDirectory` (`isAssigned`, `sectionIdsForFaculty`, `assignedFacultyIds`). It keeps no assignment data of its own.
- **Validation:**
  - Faculty ids must be known and active according to `FacultyIdentityLookup`.
  - A missing or failed lookup means refusal (422).
  - Until T-30, `UnavailableFacultyIdentity` refuses every id.
- **No duplicated faculty data:** names are resolved at read time. No Team 6 schema change.
- **Superseded:** the Team 5 `POST /api/registrar/teaching-assignments` endpoint and the `InstructorLookup` design.

## 8. Dependencies for T-21

- **Scope:** revised for B-04; owner confirmation pending (plan Q7, which also proposes at most 5 instructors per section).
- **Store and data:**
  - Extend the snapshot's allowed top-level keys with `assignments` and `assignment_history` (I-3).
  - Read sections through `CatalogRepositoryInterface::findSection`.
  - Add `instructors` to the section JSON (`Section` / `SectionService`).
- **Code needed:**
  - `SectionDirectory` and `FacultyIdentityLookup` with `UnavailableFacultyIdentity`.
  - The assignment endpoints with audit history, plus tests using a stub lookup.
- **Router:** confirm that `DELETE` routes are supported (not checked).

**Later tasks:**
- **T-22:** adds `enrollments` to the same store, so that approving an enrollment and raising `approved_count` commit together. It reads `enrollment_open`, handles "no current term", and decides whether edits to meetings or capacity are restricted once enrollments exist.

## 9. Remaining blockers and open questions

- **D1 (course catalog owner) is open:** `course_code`, `course_title` and `units` are stored on the section and checked for format only. They will need reconciling when a catalog exists (T-11b).
- **B-03 (Team 6 schema) is open:** MySQL stays fail-closed (T-40).
- **Plan defaults applied, pending owner confirmation (plan §14):**
  - **Q1:** term code immutable with no delete.
  - **Q2:** units are whole numbers 1–12.
  - **Q3:** every section needs at least one meeting (no TBA or online-only sections).
  - **Q4:** course and section codes immutable.
  - **Q5:** no delete or archive.
  - **Q6:** terms list not paginated.
  - **Q7:** T-21 expanded, with at most 5 instructors.
- **L-3** (Core error keys) is for a separate task and for the Team 1 frontend.

## 10. Commits

- Implementation commit: `b04fec4` on `task/T-20-terms-sections`. The follow-up `909a000` records the hash.
- Policy alignment commit: `0624eb7` (delivery_mode, bug fix, decision records, coordination files).
- Not merged, not pushed.

## 11. Policy alignment (owner decisions, 2026-11-17)

T-20 is conditionally approved, pending policy alignment and an independent review.

| # | Decision | Applied |
|---|---|---|
| 1 | Term codes are immutable | Unchanged (already enforced) |
| 2 | Units are whole numbers 1–12, provisionally | Unchanged |
| 3 | Sections with no meetings only when explicitly TBA or online asynchronous | **Implemented**: new `delivery_mode` |
| 4 | Course and section codes are immutable | Unchanged |
| 5 | No hard deletion; future archival documented | No delete exists. Requirements are in plan §15 |
| 6 | Terms list unpaginated for MVP | Unchanged |
| 7 | At most 5 instructors per section in T-21 | Recorded in the T-21 task and plan §14 |

**Delivery mode (decision 3):**
- **The field:** `delivery_mode` is one of `scheduled` (the default), `tba` or `online_async`. It is optional on create and can be changed by PATCH. It is stored between `approved_count` and `meetings`, and in the response it appears after `remaining`.
- **Meetings:**
  - `scheduled` needs 1–14 meetings.
  - `tba` and `online_async` may have 0–14.
  - An empty list is never taken to mean TBA (422).
  - The overlap check applies to every mode.
- **PATCH:** the mode/meetings pairing is re-checked on the merged row inside the store lock. A rejected PATCH leaves the store unchanged.
- **Stored rows:** each row must have a valid mode, and a `scheduled` row must have at least one meeting. Otherwise the store fails closed. Stores written before this change, which lack `delivery_mode`, now fail closed. No such store exists in the workspace, and T-20 was never merged.

**Files changed:** `Section.php`, `SectionService.php`, `SectionController.php` and `MockCatalogRepository.php`; `tests/Enrollment/{SectionServiceTest,EnrollmentHttpTest,CatalogStoreTest}.php` (qa-engineer); the README, `API_CONTRACTS.md`, the plan (P9, §3.2, §4, §14–§16) and the task board.

**Bug found by QA and fixed:**
- **Symptom:** on create, a meeting item with an error, such as `day:"mon"`, also produced a misleading `meetings: "needs at least one meeting"` error.
- **Fix:** the mode/meetings check is now skipped when any `meetings` or `meetings.N.*` error already exists. A regression test was added.

**Verification:**

| Check | Result |
|---|---|
| `php tests/run.php` | 163 passed, 0 failed |
| `php -l` | 104 files, 0 failures |
| Apache check | PASS, both document roots |
| Mutation checks (scratch copy) | All killed: `requireMeetingsForMode` made a no-op; the merged-row check removed from the PATCH; the stored-row rule loosened to allow 0 meetings for scheduled |

**Impact on later tasks (plan §16):**
- **T-21:** none, apart from the 5-instructor cap.
- **T-22:** sections with no meetings never clash. `/api/schedules/me` must list them without time slots. Edits that add meetings or switch a section to `scheduled` after enrollments exist can create clashes, so T-22 must decide whether to restrict them.
- **T-40:** a `delivery_mode` column; a section may have zero meeting rows only when it isn't `scheduled`.

**Not done for this change:**
- Claude's own security-reviewer wasn't re-run for this delta. AGY's independent review covers it.
- Two older tests were loosened while the bug was open. The new regression test now asserts the exact behaviour.
