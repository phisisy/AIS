# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-20 merged (`8c2b980`); T-21 plan drafted (`docs/plans/T-21-instructor-assignments.md`, planning only) | `main` | Lead, planning, integration, review, merge preparation | WAITING_FOR_OWNER | 2026-11-20, Claude (this session) | Owner approval of the T-21 plan and Q1–Q4 | Wait for owner approval; no Codex assignment yet |
| AGY | Independent review of T-20: done (verdict APPROVE on `81097d5` / `0624eb7`) | `task/T-20-terms-sections` | Independent QA, security, integration | COMPLETED | 2026-11-20, AGY (this session). Report recorded in `AGY_REVIEW_REPORT.md` | None | Await next QA/security review assignment |
| CODEX | None assigned. Last work: T-20 review (APPROVE on `60ac236`), finished 2026-11-17 | — | Backend development, tests, assigned fixes (role restored 2026-11-20) | UNKNOWN (no Codex-authored status since its review report) | 2026-11-17, Codex report; 2026-11-20, owner (role change) | — | Wait for a T-21 assignment after owner approval |
