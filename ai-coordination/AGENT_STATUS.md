# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | Three-agent sync; T-20 merge readiness re-verified at `41e8a56` | `task/T-20-terms-sections` | Lead, planning, integration, review, merge preparation | WAITING_FOR_OWNER | 2026-11-20, Claude (this session) | Owner decisions (T-20 merge; whether AGY reviews T-20 first) | Merge T-20 when the owner approves; then the T-21 proposal |
| AGY | None assigned. No AGY report on T-20 | — | Independent QA, security, integration | UNKNOWN (available per the owner, 2026-11-20; no AGY-authored status since its limit reset) | 2026-11-20, owner (workflow restored). Last AGY report: T-11a APPROVE (2026-11-17) | — | Owner decides whether AGY reviews T-20 before merging (see `AGENT_HANDOFF.md`) |
| CODEX | None assigned. Last work: T-20 review (APPROVE on `60ac236`), finished 2026-11-17 | — | Backend development, tests, assigned fixes (role restored 2026-11-20) | UNKNOWN (no Codex-authored status since its review report) | 2026-11-17, Codex report; 2026-11-20, owner (role change) | — | Wait for a T-21 assignment after owner approval |
