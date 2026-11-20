# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-21 integrated (`e92f328`); review reassigned to Codex | `task/T-21-instructor-assignments` | Lead, integration, review | WAITING_FOR_OWNER (independent review) | 2026-11-20, Claude (this session) | — | Read the Codex T-21 review report; verify its findings; fixes go to their owners; then the merge recommendation |
| AGY | None. T-21 review reassigned to Codex (AGY's assignment of 2026-11-20 never started) | — | Independent QA, security, integration | UNAVAILABLE_USAGE_LIMIT (owner, 2026-11-20) | 2026-11-20, owner. Last AGY report: T-20 APPROVE | Token limit | May return after its limit resets |
| CODEX | (1) T-21 phase 2 done (`99c92b6`, `cc1dbf5`). (2) **Assigned by the owner: T-21 independent review** in a fresh session, worktree `..\AIS_REVIEW_T21` @ `54d1a6b` | `task/T-21-impl` (dev, finished); review worktree detached @ `54d1a6b` | Backend development; temporary independent reviewer for T-21 | REVIEWING (per the owner, 2026-11-20; no Codex-authored status yet) | 2026-11-20, owner (review assignment) | Reviews code it implemented (independence caveat) | Write the T-21 report to `CODEX_REVIEW_REPORT.md` |
