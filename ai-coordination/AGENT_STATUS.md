# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-21 phase 1 (plan final, contracts) done; Codex handoff prepared | `task/T-21-instructor-assignments` | Lead, planning, integration, review | WAITING_FOR_OWNER (waiting for Codex phase 2) | 2026-11-20, Claude (this session) | — | Review Codex's `task/T-21-impl` when it reports; then phase 3 |
| AGY | Independent review of T-20: done (verdict APPROVE on `81097d5` / `0624eb7`) | `task/T-20-terms-sections` | Independent QA, security, integration | COMPLETED | 2026-11-20, AGY (this session). Report recorded in `AGY_REVIEW_REPORT.md` | None | Await next QA/security review assignment |
| CODEX | Assigned: T-21 phase 2 (backend + tests), owner 2026-11-20. **Not started**: Codex hasn't confirmed | `task/T-21-impl` in `..\AIS_CODEX_T21` | Backend development, tests, assigned fixes | UNKNOWN (assigned, not confirmed) | 2026-11-20, Claude (assignment) | — | Implement per the T-21 handoff in `AGENT_HANDOFF.md` |
