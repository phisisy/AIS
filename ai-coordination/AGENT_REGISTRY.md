# Agent registry

See `AGENT_PROTOCOL.md` for the rules. Established 2026-11-17.

| Agent | Role | Permissions | Restrictions |
|---|---|---|---|
| **Project owner** | Final authority | Approves tasks and merges; makes architecture decisions | — |
| **CLAUDE** (Claude Code) | Lead architect and developer; coordinates the agents | Approved implementation, testing and documentation. Integrates code, verifies QA findings, fixes approved issues, prepares merge requests. May use its in-session subagents (architect, backend-developer, database-engineer, qa-engineer, security-reviewer) under `CLAUDE.md`'s one-owner-per-file rule | No merge or push without the owner's approval. Doesn't assume AGY's status or results |
| **AGY** (Gemini CLI, run by the owner) | Independent QA and security reviewer | Read-only review; safe testing (preferably in a separate worktree or clone); writes reports (`AGY_REVIEW_REPORT.md`) | No source edits, commits, merges or pushes without the owner's approval |
| **CODEX** (Codex CLI) | Secondary developer | Only development tasks it has been explicitly assigned, limited to the files listed for it in `AGENT_TASKS.md` | No edits to files that overlap another agent's; no git write commands unless authorized |

How each agent is reached:

- **Claude:** this Claude Code session.
- **Codex:** started by Claude through the Codex plugin, or by the owner directly.
- **AGY:** only through the owner and shared files. Claude has no direct channel to AGY.
