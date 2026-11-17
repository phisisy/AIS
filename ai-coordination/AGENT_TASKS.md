# Agent tasks and file locks

The scope and approval of each task are kept in `TASK_BOARD.md`. This file records who owns which files and who reviews them. One file has one active owner at a time.

## T-20 — Terms & Sections (Team 4)

- **Task ID:** T-20
- **Task name:** Terms & sections catalog
- **Owner:** CLAUDE (lead). Phase 1 backend was done by CODEX, tests by Claude's qa-engineer, the seeder by database-engineer.
- **Reviewer:** AGY (independent). Claude's security-reviewer has already done a read-only review.
- **Branch:** `task/T-20-terms-sections`. Commits `b04fec4` (implementation), `909a000` (hash record), `0624eb7` (policy alignment: delivery_mode), plus a docs-only handoff commit. Base: `main` `592d912`.
- **Assigned files (locked to T-20; no other agent edits these until T-20 is merged or released):**
  - `app/Enrollment/**`
  - `routes/enrollment.php`
  - `app/bootstrap.php` (Enrollment wiring)
  - `database/seeders/dev_sections.php`
  - `tests/Enrollment/**`
  - `docs/plans/T-20-terms-sections.md`
  - `README.md` (Enrollment section)
  - `ai-coordination/T20_IMPLEMENTATION_REPORT.md`
- **Status:** READY_FOR_AGY_REVIEW (owner decisions Q1–Q7 applied; see the current handoff in `AGENT_HANDOFF.md`).
- **Dependencies:** T-02 ✔, B-04 ✔ (resolved by the owner)
- **Approval status:** implementation APPROVED; conditionally approved pending policy alignment (done) and AGY review. Merge NOT approved.

## T-11a — Academic Records (Team 3)

- **Owner:** CLAUDE. **Reviewer:** AGY (APPROVE on `0b91668` and `6ae3ac3`, pasted by the owner).
- **Branch:** merged into `main` as `d062a5e`.
- **Status:** COMPLETED. No files locked.

## Shared coordination files

| File | Who edits it |
|---|---|
| `AGENT_PROTOCOL.md`, `AGENT_REGISTRY.md`, `AGENT_TASKS.md`, `AGENT_HANDOFF.md`, `TASK_BOARD.md` | CLAUDE (lead); the owner may edit any of them |
| `AGENT_STATUS.md` | Each agent edits its own row |
| `AGY_REVIEW_REPORT.md` | AGY (or the owner pasting AGY's report). Claude doesn't write findings or verdicts there |
