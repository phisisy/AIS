# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-20 post-review integration check done; READY_FOR_MERGE | `task/T-20-terms-sections` @ `0624eb7` (+ docs-only handoff commit) | Lead, coordination, docs | WAITING_FOR_OWNER | 2026-11-17, Claude (this session) | None | Wait for the owner's merge approval |
| AGY | None (the T-20 review was reassigned to Codex) | — | Independent reviewer when available | UNAVAILABLE_USAGE_LIMIT | 2026-11-17, owner (weekly usage limit reached). AGY's last report: T-11a APPROVE | Usage limit | May return after its limit resets; the owner reassigns |
| CODEX | Independent review of T-20: done (verdict APPROVE on `60ac236`) | `task/T-20-terms-sections` (reviewed in the shared worktree with isolated runtime directories) | Temporary independent QA and security reviewer | COMPLETED | 2026-11-17, Codex-authored report in `CODEX_REVIEW_REPORT.md`; the owner confirmed completion | None (independence caveat disclosed in the report) | Wait for the next assignment |
