# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-21 phase 3 integrated (`e92f328`); AGY handoff prepared | `task/T-21-instructor-assignments` | Lead, integration, review | WAITING_FOR_OWNER (AGY review) | 2026-11-20, Claude (this session) | — | Read AGY's T-21 report; fix findings through their owners; then stop for merge approval |
| AGY | Assigned: T-21 independent review. **Not started**: AGY hasn't confirmed | `task/T-21-instructor-assignments` (review worktree `..\AIS_REVIEW_T21`) | Independent QA, security, integration | UNKNOWN (assigned, not confirmed) | 2026-11-20, Claude (handoff). Last AGY report: T-20 APPROVE | — | Owner starts AGY on the T-21 handoff (top of `AGENT_HANDOFF.md`) |
| CODEX | T-21 phase 2 done (`99c92b6`, `cc1dbf5`; committed by Claude because of the sandbox git limit) | `task/T-21-impl` in `..\AIS_CODEX_T21` | Backend development, tests, assigned fixes | COMPLETED | 2026-11-20, Codex reports (job exited code 0) | Sandbox can't commit in a linked worktree | Wait; fixes for its files if AGY finds any |
