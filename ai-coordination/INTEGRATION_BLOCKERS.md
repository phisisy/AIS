# Integration Blockers & Open Decisions

Status key: **OPEN** (needs owner decision) · **DEFERRED** (accepted gap) · **RESOLVED**

| ID | Severity | Topic | Status | Blocks |
|---|---|---|---|---|
| B-01 | Critical | No Git repository | RESOLVED 2026-11-17 | — |
| B-02 | Critical | One app with modules vs. separate team apps | RESOLVED 2026-11-17 | — |
| B-03 | High | Team 6 shared schema not finalized | OPEN | T-40 (MySQL repositories) |
| B-04 | Medium | Who owns "instructor of a section" | OPEN | T-21, T-30 |
| B-05 | Medium | Grade scale and finalization workflow | OPEN | T-32 |
| B-06 | Medium | Login identifier: email or username | RESOLVED 2026-11-17 (email) | — |
| B-07 | Medium | Faculty access to student profiles | OPEN | T-11 |
| B-08 | Low | Enrollment eligibility beyond MVP rules | OPEN | T-22 |
| B-09 | Medium | Persistent session revocation | DEFERRED | — |
| B-10 | Low | Frontend origin (Team 1) | OPEN | Team 1 integration |

## B-01 — No Git repository (Critical)
The review process needs real diffs ("inspect the actual changes", "don't overwrite uncommitted changes").
Without Git, Claude can't see what Codex changed or recover from a bad edit.
**Resolved (owner, 2026-11-17):** Git initialized on `main` with an initial commit of the docs and guide files.
Codex's sandbox denies `.git` writes, so Claude creates the task branches and commits verified work. Codex edits files only. Only the owner merges to `main`. See PROJECT_CONTEXT "Git workflow".

## B-02 — Application shape (Critical)
**Resolved (owner, 2026-11-17):** one PHP application with Team 2–5 modules (see `ARCHITECTURE.md` §1).
Separate apps would need cross-app session sharing or token auth. That's extra work and a security surface nobody has asked for.

## B-03 — Team 6 schema not finalized (High)
Teams 2–5 build on mock (JSON) repositories behind interfaces. No shared tables are created.
MySQL repositories (T-40) start only when Team 6 publishes an approved schema.
**Needed from Team 6:** table and column names for users, roles, user_roles, audit_logs, students, programs, terms, sections, section meetings, enrollments, faculty, teaching assignments, grades. Also integer ID types, FK and unique constraints, and whether `users` has a session/auth version column (see B-09).

## B-04 — Section instructor ownership (Medium)
Team 4's prompt shows instructors on sections. Team 5's prompt owns teaching assignments.
**Recommendation:** Team 5 owns teaching assignments. Team 4 displays them through `InstructorLookup` (implemented by Team 5).

## B-05 — Grade scale and finalization (Medium)
Not specified. **Proposed default:** 1.00–3.00 in 0.25 steps, 5.00 (fail), INC, DRP. Workflow is draft → submitted (faculty) → finalized (registrar).
Students see only finalized grades. Owner must confirm the scale, whether registrar approval is required, and whether finalized grades can ever be corrected.

## B-06 — Login identifier (Medium)
The frontend guide shows "username/password". A future shared schema may only have email.
**Resolved (owner, 2026-11-17):** login with **email**, trimmed and lowercased, unique. Team 6 must keep a unique email column on users.

## B-07 — Faculty access to student profiles (Medium)
Team 3 must "restrict faculty access to explicitly authorized information".
**Recommendation:** faculty may view the summary (name, student number, program, year level) of students on their own rosters only. No other access.

## B-08 — Enrollment eligibility (Low)
MVP rules are in `API_CONTRACTS.md`. Not included: prerequisites, unit limits, enrollment periods, dropping or cancelling.
**Recommendation:** keep them out of MVP. A term-level `enrollment_open` flag is the only period control.

## B-09 — Persistent session revocation (DEFERRED)
A password reset or role change should invalidate existing sessions. A durable fix needs a per-user version column in the shared schema (Team 6).
MVP: mock repositories keep an `auth_version` per user, and every request checks it. MySQL mode depends on B-03.

## B-10 — Frontend origin (Low)
API is same-origin, with session cookies and no CORS. Team 1 pages must be served by this same app (`public/`) or a same-origin proxy.
