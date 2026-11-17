# Agent status

Each agent updates only its own row. Claude may record an owner-confirmed status for AGY and cite the source. A status nobody has confirmed is `UNKNOWN`.

| Agent | Current task | Branch | Responsibilities | Status | Last reported update | Blockers | Next action |
|---|---|---|---|---|---|---|---|
| CLAUDE | T-20 policy alignment done; handoff for AGY prepared | `task/T-20-terms-sections` @ `0624eb7` (+ docs-only handoff commit) | Lead, coordination, docs | WAITING_FOR_OWNER | 2026-11-17, Claude (this session) | None | Owner points AGY at `AGENT_HANDOFF.md`; Claude reads AGY's report when it exists |
| AGY | Unknown. No AGY-authored status or report is in the repository | Unknown | Independent QA and security review | UNKNOWN | None in the repository. An AGY T-11a review was pasted by the owner on 2026-11-17 (recorded in `T11A_IMPLEMENTATION_REPORT.md` §13) | — | Owner instructs AGY to read `AGENT_HANDOFF.md` (T-20) |
| CODEX | None. The last assignment (T-20 phase 1 backend) finished | — | Assigned backend tasks | UNKNOWN (no Codex-authored status). Claude's last Codex job on T-20 ended 2026-11-17; Codex processes run by the owner, if any, are not tracked here | 2026-11-17, Claude (Codex plugin job finished) | — | Wait for an assignment |
