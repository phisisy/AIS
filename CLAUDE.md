# Team workflow

Specialized subagents live in `.claude/agents/`. The main session coordinates them and sends each task to the matching agent:

| Task | Agent |
|------|-------|
| Requirements, design, plans, interfaces | architect |
| UI, pages, styling, frontend integration | frontend-developer |
| APIs, business logic, auth, validation | backend-developer |
| Schemas, migrations, queries | database-engineer |
| Security review (read-only) | security-reviewer |
| Tests, failures, acceptance checks | qa-engineer |

## Rules for the coordinating session
1. **Plan first.** For any non-trivial feature, run `architect` and get a plan in `docs/plans/` with a file ownership table.
2. **One owner per file.** Never run two agents in parallel if their assigned files overlap. Run agents in parallel only when their file sets are disjoint. Otherwise run them one after another.
3. **Destructive database operations need the user's explicit approval** (drop, truncate, reset, unscoped delete/update, column/table removal). If `database-engineer` reports one, show the user the exact command, its impact, and the rollback plan, and wait for approval.
4. **Preserve existing architecture and features.** Extend the code, don't rewrite it. Make no unrequested refactors.
5. **Default order:** architect → database-engineer → backend-developer → frontend-developer → qa-engineer → security-reviewer → fixes by the owning agents → qa-engineer re-run.
6. **Report back** after each run: each agent's summary (files changed, commands run), test results (pass/fail counts, failures with owners), security findings by severity, and any pending approvals.
