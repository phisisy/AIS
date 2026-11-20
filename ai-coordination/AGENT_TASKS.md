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
- **Status:** READY_FOR_AGY_REVIEW. The AGY review is mandatory (owner, 2026-11-20); a local merge is conditionally approved on AGY APPROVE. Codex review: **APPROVE** on `60ac236` (`CODEX_REVIEW_REPORT.md`). `60ac236..HEAD` changes only `ai-coordination/*.md`; the last code change is `0624eb7`.
- **Dependencies:** T-02 ✔, B-04 ✔ (resolved by the owner)
- **Approval status:** implementation APPROVED; policy alignment done; independent review APPROVE. Merge NOT yet approved by the owner.

## T-21 — Draft file ownership (proposal only; NOT approved, nothing assigned)

To be confirmed in the T-21 plan once T-20 is merged and the owner approves T-21. Each agent works in its own worktree. No file has two owners at the same time.

| Agent | Worktree / branch | Files (draft) |
|---|---|---|
| CLAUDE (lead) | `AIS_SYSTEM`, branch `task/T-21-…` | `docs/plans/T-21-*.md`; `app/Enrollment/Contracts/*` (interfaces: `SectionDirectory`, `SectionSummary`, `FacultyIdentityLookup`, `FacultyIdentity`, `UnavailableFacultyIdentity`); `app/bootstrap.php` (Enrollment wiring); `routes/enrollment.php`; `README.md` (Enrollment section); `ai-coordination/*` |
| CODEX (backend + tests) | `..\AIS_CODEX_T21`, same task branch, sequenced after Claude's interface commit | `app/Enrollment/Repositories/{CatalogRepositoryInterface,MockCatalogRepository}.php` (assignments, `assignment_history`, extending the allowed store keys); `app/Enrollment/Services/{AssignmentService,RepositorySectionDirectory}.php` (new); `app/Enrollment/Services/SectionService.php` + `app/Enrollment/Models/Section.php` (`instructors` in JSON); `app/Enrollment/Controllers/AssignmentController.php` (new); `tests/Enrollment/*` |
| AGY (review) | `..\AIS_REVIEW_T21` at the exact handoff commit, read-only | None (writes only `ai-coordination/AGY_REVIEW_REPORT.md` in its own worktree) |

Sequence: Claude's plan and interfaces, then Codex's implementation and tests, then Claude's review and integration, then the AGY review, then fixes by the owning agent, then the owner's merge approval. While Codex holds the branch, Claude makes no commits to it.

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
