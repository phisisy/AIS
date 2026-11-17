# Project Context — Academic Information System (AIS) Backend

Last updated: 2026-11-17 by Claude (Lead Architect & Reviewer)

## Starting point

`AIS_SYSTEM/` is a **new, empty workspace**. The project owner confirmed the backend is built **from zero** here.
Discovery on 2026-11-17 found only agent/guide files (`AGENTS.md`, `CLAUDE.md`, `.claude/`). There is:

- no application source, routes, tests, schema, or documentation for Teams 2–5
- no Git repository (see blocker B-01)
- no approved shared database from Team 6

Projects in other folders on this machine are **not** part of this workspace and are not dependencies.

**Owner decision 2026-11-17:** Team 2 is **rebuilt from zero** here. The earlier Team 2 status (Phase E/F, 506 offline
and 206/312 MariaDB assertions) belongs to a separate `ais-auth-admin` project. It does **not** apply to this
workspace and must not be copied in or cited as evidence.

## Verified local environment

| Item | Version / state |
|---|---|
| PHP (XAMPP CLI) | 8.2.12 at `C:\xampp\php\php.exe` |
| PHP extensions | PDO, pdo_mysql, pdo_sqlite, mysqlnd, json, mbstring |
| Database server | MariaDB 10.4.32 (XAMPP) at `C:\xampp\mysql\bin` |
| Git | 2.53.0, repository initialized on `main` (2026-11-17) |

## Teams and ownership

| Team | Owns | Backend module (proposed) |
|---|---|---|
| Team 1 | Frontend (HTML/CSS/vanilla JS) | `public/` pages (not in backend scope) |
| Team 2 | Authentication, sessions, RBAC, user administration, audit logging | `app/Auth/` |
| Team 3 | Student profiles, academic records | `app/Students/` |
| Team 4 | Terms, sections, schedules, enrollment | `app/Enrollment/` |
| Team 5 | Faculty profiles, teaching assignments, rosters, grades | `app/Faculty/` |
| Team 6 | Shared database schema (**not finalized**) | `database/` (Team 6 approval required) |
| Shared | HTTP core used by every module | `app/Core/` |

## Technology constraints

PHP 8+, OOP, custom MVC, REST/JSON, PDO, MariaDB/MySQL, XAMPP compatible, automated backend tests.
No frameworks (no Laravel/Symfony/Slim). No Composer dependencies unless explicitly approved.

## Roles in this collaboration

| Actor | Responsibility |
|---|---|
| Project owner (human) | Approves tasks (`PROPOSED → APPROVED`), architectural decisions, destructive operations |
| Claude | Architecture, specs, contracts, task board, reviews (`CLAUDE_REVIEW.md`), progress reports. Does **not** edit application source. |
| Codex | Implements `APPROVED` tasks only, within each task's "Files allowed to change", reports in `CODEX_REPORT.md` |

### Takeover (owner-approved 2026-11-17)

Codex reached its usage limit partway through T-01. **Claude is now the primary implementation engineer** for Teams 2–5:

- The owner still approves each task (`PROPOSED → APPROVED`) and each merge to `main`.
- Claude implements on the task branch within the task's allowed files and preserves all existing work, including Codex's partial T-01 files.
- Independent check before `VERIFIED`: the full test suite, a syntax check, a manual HTTP probe, and a review by the read-only `security-reviewer` subagent (`.claude/agents/security-reviewer.md`). Results go in `CLAUDE_REVIEW.md`. Implementation notes go in `CODEX_REPORT.md` under "Claude implementation".
- Claude stops after every task with a report.
- Phases: (1) T-01 → (2) T-02, T-03 → (3) T-10, T-11 → (4) T-20–T-22 → (5) T-30–T-32 → (6) T-39. Phases 3–5 need B-04, B-05, B-07 and B-08 decided first.

If Codex returns, the original Claude-reviews / Codex-implements protocol below applies again.

## Coordination protocol

Writing a file does not notify the other AI. The human relays "task X is ready" between terminals.

1. Only the project owner moves a task to `APPROVED`.
2. Codex sets the task `IN_PROGRESS` before editing. Only one `IN_PROGRESS` task may touch a given file.
3. Codex edits only the files listed for that task. Needing another file → stop and note it in `CODEX_REPORT.md`.
4. When done, Codex appends a section to `ai-coordination/CODEX_REPORT.md` (task ID, files changed, commands run with real output, known gaps) and sets `READY_FOR_REVIEW`.
5. Claude reviews only `READY_FOR_REVIEW` tasks: reads the report, inspects the diff, runs safe checks (syntax, offline tests), writes `ai-coordination/CLAUDE_REVIEW.md`, and sets `VERIFIED`, `CHANGES_REQUESTED`, or `BLOCKED`.

### Git workflow (owner decision 2026-11-17)

Codex's sandbox denies writes to `.git` by design, so **Codex never runs git write commands** (checkout, add, commit, stash, reset). Read-only git (`status`, `diff`, `log`) is fine.

1. Before a task starts, Claude creates `task/T-xx-<name>` from `main` and checks it out. The task entry names the branch.
2. Codex edits working-tree files only. Changes stay uncommitted.
3. Claude reviews the uncommitted changes with `git status` + `git diff` (and `git diff --no-index` for new files).
4. On `VERIFIED`, Claude commits the task's files on the task branch. Merging to `main` needs the owner's approval for each merge. Claude may run the merge once approved (T-00 merged this way 2026-11-17).
5. On `CHANGES_REQUESTED`, the changes stay uncommitted for Codex to fix. Nobody discards them.
6. Claude never edits application source while a task is `IN_PROGRESS`, and never overwrites uncommitted work.
7. No destructive database operations. No changes to Team 6's shared schema without Team 6 + owner approval.

## Current progress (2026-11-17)

| Team | Implemented | Verified | Notes |
|---|---|---|---|
| 2 | Nothing | Nothing | Greenfield |
| 3 | Nothing | Nothing | Greenfield |
| 4 | Nothing | Nothing | Greenfield |
| 5 | Nothing | Nothing | Greenfield |

No completion percentages are given. See `TASK_BOARD.md` for planned work and `INTEGRATION_BLOCKERS.md` for open decisions.
