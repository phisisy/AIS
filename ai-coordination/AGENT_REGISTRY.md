# Agent registry

See `AGENT_PROTOCOL.md` for the rules. Established 2026-11-17.

| Agent | Role | Permissions | Restrictions |
|---|---|---|---|
| **Project owner** | Final authority | Approves tasks and merges; makes architecture decisions | — |
| **CLAUDE** (Claude Code) | Lead architect and developer; coordinates the agents | Approved implementation, testing and documentation. Integrates code, verifies QA findings, fixes approved issues, prepares merge requests. May use its in-session subagents (architect, backend-developer, database-engineer, qa-engineer, security-reviewer) under `CLAUDE.md`'s one-owner-per-file rule | No merge or push without the owner's approval. Doesn't assume AGY's status or results |
| **AGY** (Gemini CLI, run by the owner) | Independent QA and security reviewer. **Temporarily `UNAVAILABLE_USAGE_LIMIT`** (owner, 2026-11-17); no active assignments | When available: read-only review, safe testing (preferably in a separate worktree or clone), reports in `AGY_REVIEW_REPORT.md` | No source edits, commits, merges or pushes without the owner's approval |
| **CODEX** (Codex CLI) | **Temporary independent reviewer** (owner, 2026-11-17): QA, security, regression, API contract, authorization, concurrency and integration review. Also secondary developer when explicitly assigned | Review: read-only inspection, running tests in a separate worktree, reports in `CODEX_REVIEW_REPORT.md`. Development: only explicitly assigned files | While reviewing: no edits to application source, no commits, merges or pushes. Never shares a working tree with Claude at the same time. Must disclose when it reviews code it wrote (T-20 phase 1) |

How each agent is reached:

- **Claude:** this Claude Code session.
- **Codex:** started by the owner directly, or by Claude through the Codex plugin. For independent review, the owner should start Codex, so the review isn't directed by the author. Claude starts a Codex review only if the owner asks it to.
- **AGY:** only through the owner and shared files. Claude has no direct channel to AGY.
