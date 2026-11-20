# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-21 merged (`d83e207`) | `main` | Lead, integration, review | WAITING_FOR_OWNER | 2026-11-20, Claude (this session) | — | Wait for the owner's next task (T-22 and T-30 not started) |
| AGY | None. T-21 review reassigned to Codex (AGY's assignment of 2026-11-20 never started) | — | Independent QA, security, integration | UNAVAILABLE_USAGE_LIMIT (owner, 2026-11-20) | 2026-11-20, owner. Last AGY report: T-20 APPROVE | Token limit | May return after its limit resets |
| CODEX | T-21 review #1 done (REQUEST_CHANGES on `54d1a6b`); review fixes done (`2bc6a90`, committed by Claude) | `task/T-21-impl`; review worktree `..\AIS_REVIEW_T21` | Backend development; temporary T-21 reviewer | COMPLETED (both assignments) | 2026-11-20, Codex reports | — | Owner may assign the re-review |
