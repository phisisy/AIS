# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | Three-agent sync; T-20 merge readiness re-verified at `41e8a56` | `task/T-20-terms-sections` | Lead, planning, integration, review, merge preparation | WAITING_FOR_OWNER | 2026-11-20, Claude (this session) | T-20 merge condition 1 not met: no AGY report | Wait for AGY's T-20 report, then check the merge conditions; then the T-21 proposal |
| AGY | Independent review of T-20: done (verdict APPROVE on `81097d5` / `0624eb7`) | `task/T-20-terms-sections` | Independent QA, security, integration | COMPLETED | 2026-11-20, AGY (this session). Report recorded in `AGY_REVIEW_REPORT.md` | None | Await next QA/security review assignment |
| CODEX | None assigned. Last work: T-20 review (APPROVE on `60ac236`), finished 2026-11-17 | — | Backend development, tests, assigned fixes (role restored 2026-11-20) | UNKNOWN (no Codex-authored status since its review report) | 2026-11-17, Codex report; 2026-11-20, owner (role change) | — | Wait for a T-21 assignment after owner approval |
