# Agent tasks and file locks

The scope and approval of each task are kept in `TASK_BOARD.md`. This file records who owns which files and who reviews them. One file has one active owner at a time.

## T-20 — Terms & Sections (Team 4)

- **Task ID:** T-20
- **Task name:** Terms & sections catalog
- **Owner:** CLAUDE (lead). Phase 1 backend was done by CODEX, tests by Claude's qa-engineer, the seeder by database-engineer.
- **Reviewer:** CODEX, temporary independent reviewer during AGY's unavailability (2026-11-17), verdict APPROVE on `60ac236`. AGY (restored 2026-11-20) has not reviewed T-20. Claude's security-reviewer has already reviewed `b04fec4`. Independence caveat: Codex wrote the phase-1 backend (`b04fec4`).
- **Branch:** `task/T-20-terms-sections`. Commits `b04fec4` (implementation), `909a000` (hash record), `0624eb7` (policy alignment: delivery_mode; **last code change**), `60ac236` (AGY handoff, docs), and the Codex-reassignment commit (docs). Base: `main` `592d912`.
- **Assigned files (locked to T-20; no other agent edits these until T-20 is merged or released):**
  - `app/Enrollment/**`
  - `routes/enrollment.php`
  - `app/bootstrap.php` (Enrollment wiring)
  - `database/seeders/dev_sections.php`
  - `tests/Enrollment/**`
  - `docs/plans/T-20-terms-sections.md`
  - `README.md` (Enrollment section)
  - `ai-coordination/T20_IMPLEMENTATION_REPORT.md`
- **Status:** COMPLETED. Merged into `main` as `8c2b980` (2026-11-20) after AGY APPROVE on `81097d5`. **Files released.** Codex review: **APPROVE** on `60ac236` (`CODEX_REVIEW_REPORT.md`). `60ac236..HEAD` changes only `ai-coordination/*.md`; the last code change is `0624eb7`.
- **Dependencies:** T-02 ✔, B-04 ✔ (resolved by the owner)
- **Approval status:** implementation APPROVED; policy alignment done; independent review APPROVE. Merge NOT yet approved by the owner.

## T-21 — SectionDirectory, faculty identity port, instructor assignments (Team 4)

- **Task ID:** T-21
- **Owner:** CLAUDE (lead: plan, contracts, integration, review). **Implementer:** CODEX.
- **Reviewer:** AGY was assigned first and never started. It became `UNAVAILABLE_USAGE_LIMIT` on 2026-11-20. **Replacement reviewer: a fresh CODEX review session** (owner, 2026-11-20), in `..\AIS_REVIEW_T21` at `54d1a6b`, reporting to `CODEX_REVIEW_REPORT.md`. Independence caveat: Codex implemented phase 2.
- **Approval status:** implementation APPROVED (owner, 2026-11-20). Merge into `main` NOT approved.
- **Branches and worktrees:**
  - Claude: `task/T-21-instructor-assignments` in `AIS_SYSTEM`, from `main` `229618b`.
  - Codex: `task/T-21-impl` in `..\AIS_CODEX_T21`, from Claude's phase-1 commit.
  - AGY: read-only `..\AIS_REVIEW_T21` at the handoff commit, created later.
- **Dependencies:** T-20 ✔, B-04 ✔. T-30 for real identities. B-11 for T-31.
- **Status:** COMPLETED. Merged into `main` as `d83e207` (2026-11-20) after re-review #2 APPROVE on `417ba6a`. **All T-21 files released.**

| Phase | Agent | Files (locked to that agent during the phase) |
|---|---|---|
| 1 ✔ | CLAUDE | `app/Enrollment/Contracts/{FacultyIdentity,FacultyIdentityLookup,UnavailableFacultyIdentity,SectionSummary,SectionDirectory}.php`; `docs/plans/T-21-instructor-assignments.md` |
| 2 | CODEX | `app/Enrollment/Models/Section.php`; `app/Enrollment/Repositories/{CatalogRepositoryInterface,MockCatalogRepository}.php`; `app/Enrollment/Services/{SectionService,RepositorySectionDirectory}.php`; `app/Enrollment/Controllers/SectionController.php`; `tests/Enrollment/**` (existing and new: `AssignmentServiceTest`, `SectionDirectoryTest`, `AssignmentHttpTest`, `FacultyIdentityPortTest`, `fixtures/StubFacultyIdentity.php`); `ai-coordination/T21_IMPLEMENTATION_REPORT.md` |
| 3 | CLAUDE | `app/bootstrap.php` (Enrollment block); `routes/enrollment.php`; `README.md` (Enrollment section); `app/Students/Contracts/FacultyStudentVisibility.php` (docblock only); `ai-coordination/*` (except the reports) |
| 4 | AGY | `ai-coordination/AGY_REVIEW_REPORT.md` (in its own worktree) |

**Rules:**
- The phases run strictly in order.
- While Codex holds phase 2, Claude makes no commits to either T-21 branch and edits none of Codex's files.
- Fixes go to the owner of the file.
- Each agent sets its own `APP_RUNTIME_PATH` outside all worktrees and outside OneDrive.
- Only Claude runs the Apache check (phase 3).

## T-11a — Academic Records (Team 3)

- **Owner:** CLAUDE. **Reviewer:** AGY (APPROVE on `0b91668` and `6ae3ac3`, pasted by the owner).
- **Branch:** merged into `main` as `d062a5e`.
- **Status:** COMPLETED. No files locked.

## Shared coordination files

| File | Who edits it |
|---|---|
| `AGENT_PROTOCOL.md`, `AGENT_REGISTRY.md`, `AGENT_TASKS.md`, `AGENT_HANDOFF.md`, `TASK_BOARD.md` | CLAUDE (lead); the owner may edit any of them |
| `AGENT_STATUS.md` | Each agent edits its own row |
| `AGY_REVIEW_REPORT.md` | AGY (or the owner pasting AGY's report). Kept as history while AGY is unavailable. Claude doesn't write findings or verdicts there |
| `CODEX_REVIEW_REPORT.md` | Codex as reviewer (or the owner pasting Codex's report). Claude doesn't write findings or verdicts there |
