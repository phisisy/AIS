# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-20 policy alignment done; handoff for the Codex review prepared | `task/T-20-terms-sections` @ `0624eb7` (+ docs-only handoff commit) | Lead, coordination, docs | WAITING_FOR_OWNER | 2026-11-17, Claude (this session) | None | Owner starts the Codex review; Claude reads `CODEX_REVIEW_REPORT.md` when it exists |
| AGY | None (the T-20 review was reassigned to Codex) | — | Independent reviewer when available | UNAVAILABLE_USAGE_LIMIT | 2026-11-17, owner (weekly usage limit reached). AGY's last report: T-11a APPROVE | Usage limit | May return after its limit resets; the owner reassigns |
| CODEX | Assigned: independent review of T-20 (owner, 2026-11-17). **Not started**: Codex hasn't confirmed | `task/T-20-terms-sections` (review in a separate worktree) | Temporary independent QA and security reviewer | UNKNOWN (assigned, not confirmed) | 2026-11-17, owner (reassignment). No Codex-authored status yet | Reviews its own T-20 phase-1 code (independence caveat) | Owner starts Codex on the handoff in `AGENT_HANDOFF.md`; Codex updates this row when it starts |
