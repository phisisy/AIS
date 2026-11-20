# Agent synchronization protocol

Established 2026-11-17 by the owner's instruction "Claude + AGY multi-agent synchronization protocol". This file complements `TASK_BOARD.md` (task scope and status) and `PROJECT_CONTEXT.md`; it doesn't replace them.

## Three-agent workflow restored (owner, 2026-11-20)

The temporary reassignment below has **ended**. From 2026-11-20:

- **Claude:** lead architecture, planning, integration, code review and final merge preparation.
- **Codex:** backend development, automated tests and assigned bug fixes. Codex no longer reviews independently.
- **AGY:** independent QA, security audits and integration checks. Reports go to `AGY_REVIEW_REPORT.md`.

Reports written during the reassignment (`CODEX_REVIEW_REPORT.md`) are kept as history.

An agent's current activity is still recorded only from that agent's own report, or from the owner. "Restored" means AGY is available for assignment, not that AGY is currently working.

## Temporary reassignment (owner, 2026-11-17) — ENDED 2026-11-20

- **AGY:** has hit its weekly usage limit and is `UNAVAILABLE_USAGE_LIMIT`, with no active assignments. It may return after the limit resets.
- **Codex:** takes over AGY's role as the independent QA, security and integration reviewer, with immediate effect. Wherever this protocol says "AGY" for reviewing, read "the current independent reviewer". That is Codex until the owner reassigns the role.
- **Report file:** Codex writes reviews to `CODEX_REVIEW_REPORT.md`. `AGY_REVIEW_REPORT.md` is kept unchanged as history.
- **Handoff status:** `READY_FOR_CODEX_REVIEW` (instead of `READY_FOR_AGY_REVIEW`).
- **Independence caveat:**
  - Codex wrote part of the T-20 code: the phase-1 backend in `b04fec4`.
  - A review of that code by Codex is therefore not fully independent. The report must say so.
  - The owner decides whether the review is enough for merging, or whether AGY (once available) or another reviewer should also review those files.
- **While reviewing**, Codex makes no source edits, commits, merges or pushes.

## Participants

There are four participants. See `AGENT_REGISTRY.md` for each one's permissions.

- **Project owner:** final authority.
- **Claude Code:** lead architect and developer.
- **AGY (Gemini CLI):** a separate process run by the owner, doing independent QA and security review.
- **Codex:** secondary developer.

## Communication

Agents share no memory. All coordination goes through:

1. Files in `ai-coordination/`.
2. Git commits and diffs.
3. Explicit instructions from the owner.
4. Review reports written by the agents themselves.

Rules for what an agent may claim:

- Nobody claims to have messaged another agent unless a real channel was used.
- Writing a file doesn't mean the other agent has read it. The owner may need to point AGY at a handoff.
- An agent's status is known only from that agent's own dated update, or from the owner. A stale status file proves nothing. Anything else is recorded as `UNKNOWN`.

## Status values

`IDLE`, `PLANNING`, `IMPLEMENTING`, `TESTING`, `REVIEWING`, `BLOCKED`, `WAITING_FOR_OWNER`, `COMPLETED`, `UNKNOWN` and `UNAVAILABLE_USAGE_LIMIT`.

The task-level statuses `READY_FOR_AGY_REVIEW` and `READY_FOR_CODEX_REVIEW` are used in `AGENT_TASKS.md`.

## Worktrees and runtime data (three agents)

- Each agent that edits files works in its own worktree, on its own task branch.
  - Claude: the main folder `AIS_SYSTEM`.
  - Codex: for example `..\AIS_CODEX_<task>`.
  - AGY: a read-only review worktree, for example `..\AIS_REVIEW_<task>`, checked out at the exact commit under review.
- The owner creates worktrees, or approves Claude creating them, with `git worktree add`.
- **Runtime data:** every agent sets its own `APP_RUNTIME_PATH`, outside all worktrees, for test runs and seeders. Nobody runs a seeder or a test against another agent's runtime.
- **Apache check:** it uses fixed local ports, so only one agent runs it at a time. The owner or Claude coordinates this.
- **Integration:** a task branch is merged into `main` only by Claude, and only with the owner's approval. Other agents rebase or merge from `main` only when told to.

## File ownership and locking

1. Only one agent modifies a given file at a time. `AGENT_TASKS.md` lists the assigned files for each task.
2. AGY may read any file. It doesn't edit source code, commit, merge or push without the owner's approval.
3. Before editing a shared file, Claude checks ownership, `git status` and `AGENT_STATUS.md`.
4. Codex edits only the files assigned to it, never files assigned to Claude.
5. Nobody overwrites another agent's uncommitted work. Nobody uses `git reset --hard`, `git clean`, or destructive stash operations on another agent's work.
6. Implementation happens on task branches.
7. **The working tree is shared:**
   - Test runs and seeders write runtime files, and branch checkouts change what everyone sees.
   - Run them one agent at a time.
   - AGY should prefer a separate read-only worktree or clone at the commit under review, for example `git worktree add ../AIS_REVIEW <commit>`, created by the owner or with the owner's approval.

## Handoff: Claude to AGY

When Claude finishes a task:

1. Claude runs the required checks.
2. Claude records the branch and exact commit in `AGENT_HANDOFF.md`, with a summary, files changed, test results, limitations, security-sensitive areas and the exact review request.
3. Claude marks the task `READY_FOR_AGY_REVIEW`.
4. Claude stops and tells the owner. Claude doesn't assume AGY has received the request.

## Feedback: AGY to Claude

The reviewer writes its report to its own file: `CODEX_REVIEW_REPORT.md` for Codex, `AGY_REVIEW_REPORT.md` for AGY. The owner may paste a report instead. Each report includes:

- the task ID, the branch, and the exact commit reviewed
- the tests that were run
- findings by severity, with evidence and file paths
- recommended fixes
- a verdict: `APPROVE`, `CHANGES_REQUESTED` or `BLOCKED`

Claude declares a review complete only after reading the actual report.

## Fix loop

When AGY returns `CHANGES_REQUESTED`:

1. Claude verifies each finding and proposes fixes.
2. The owner approves.
3. Claude implements the fixes, runs the tests and commits.
4. Claude writes a new handoff.
5. AGY reviews the **new** commit. An earlier approval doesn't cover later changes.

## Checkpoints

- **Before starting work:** read `AGENT_STATUS.md` and `AGENT_TASKS.md`, check `git status` and the current branch, read the latest AGY report, and check the owner's approvals.
- **Before changing files:** confirm ownership, check for uncommitted changes, and check whether another agent is working in the same area.
- **Before committing:** verify the changed files, run the tests, and update the handoff.
- **Before merging:**
  - AGY has reviewed the exact final commit.
  - No blocking findings are open.
  - The owner has explicitly approved the merge.
