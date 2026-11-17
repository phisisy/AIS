# Agent synchronization protocol

Established 2026-11-17 by the owner's instruction "Claude + AGY multi-agent synchronization protocol". This file complements `TASK_BOARD.md` (task scope and status) and `PROJECT_CONTEXT.md`; it doesn't replace them.

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

`IDLE`, `PLANNING`, `IMPLEMENTING`, `TESTING`, `REVIEWING`, `BLOCKED`, `WAITING_FOR_OWNER`, `COMPLETED` and `UNKNOWN`.

The task-level status `READY_FOR_AGY_REVIEW` is used in `AGENT_TASKS.md`.

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

AGY writes its report to `AGY_REVIEW_REPORT.md`, or the owner pastes it. Each report includes:

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
