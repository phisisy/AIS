# Agent registry

See `AGENT_PROTOCOL.md` for the rules. Established 2026-11-17.

| Agent | Role | Permissions | Restrictions |
|---|---|---|---|
| **Project owner** | Final authority | Approves tasks and merges; makes architecture decisions | — |
| **CLAUDE** (Claude Code) | Lead architect and developer; coordinates the agents | Approved implementation, testing and documentation. Integrates code, verifies QA findings, fixes approved issues, prepares merge requests. May use its in-session subagents (architect, backend-developer, database-engineer, qa-engineer, security-reviewer) under `CLAUDE.md`'s one-owner-per-file rule | No merge or push without the owner's approval. Doesn't assume AGY's status or results |
| **AGY** (Gemini CLI, run by the owner) | **Independent QA, security audits and integration checks** (restored by the owner, 2026-11-20). Was `UNAVAILABLE_USAGE_LIMIT` from 2026-11-17 to 2026-11-20 | Read-only review; safe testing in its own review worktree; reports in `AGY_REVIEW_REPORT.md` | No source edits, commits, merges or pushes without the owner's approval |
| **CODEX** (Codex CLI) | **Backend development, automated tests, assigned bug fixes** (owner, 2026-11-20). Was the temporary independent reviewer from 2026-11-17 to 2026-11-20 (report kept in `CODEX_REVIEW_REPORT.md`) | Only explicitly assigned tasks and files (`AGENT_TASKS.md`), in its own worktree and branch | No edits outside its assigned files; no merges or pushes; commits only on its assigned branch when the owner or Claude authorizes them. Never shares a working tree with another agent at the same time |

How each agent is reached:

- **Claude:** this Claude Code session.
- **Codex:** started by the owner directly, or by Claude through the Codex plugin for assigned development.
- **AGY:** only through the owner and shared files. Claude has no direct channel to AGY.
