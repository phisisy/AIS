# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-20 handoff prepared; coordination protocol set up | `task/T-20-terms-sections` @ `909a000` | Lead, coordination, docs | WAITING_FOR_OWNER | 2026-11-17, Claude (this session) | Owner decisions: T-20 Q1–Q7, especially Q3 (TBA/online sections, not implemented) | Wait for the owner to decide whether to change T-20 for TBA/online before AGY reviews, or to review as is |
| AGY | Unknown. No AGY-authored status or report is in the repository | Unknown | Independent QA and security review | UNKNOWN | None in the repository. An AGY T-11a review was pasted by the owner on 2026-11-17 (recorded in `T11A_IMPLEMENTATION_REPORT.md` §13) | — | Owner instructs AGY to read `AGENT_HANDOFF.md` (T-20) |
| CODEX | None. The last assignment (T-20 phase 1 backend) finished | — | Assigned backend tasks | UNKNOWN (no Codex-authored status). Claude's last Codex job on T-20 ended 2026-11-17; Codex processes run by the owner, if any, are not tracked here | 2026-11-17, Claude (Codex plugin job finished) | — | Wait for an assignment |
