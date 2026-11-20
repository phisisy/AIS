# Integration Blockers & Open Decisions

Status key: **OPEN** (needs owner decision) · **DEFERRED** (accepted gap) · **RESOLVED**

| ID | Severity | Topic | Status | Blocks |
|---|---|---|---|---|
| B-01 | Critical | No Git repository | RESOLVED 2026-11-17 | — |
| B-02 | Critical | One app with modules vs. separate team apps | RESOLVED 2026-11-17 | — |
| B-03 | High | Team 6 shared schema not finalized | OPEN | T-40 (MySQL repositories) |
| B-04 | Medium | Who owns "instructor of a section" | RESOLVED 2026-11-17 (Team 4) | — |
| B-05 | Medium | Grade scale and finalization workflow | OPEN | T-32 |
| B-06 | Medium | Login identifier: email or username | RESOLVED 2026-11-17 (email) | — |
| B-07 | Medium | Faculty access to student profiles | RESOLVED 2026-11-17 | — |
| B-08 | Low | Enrollment eligibility beyond MVP rules | OPEN | T-22 |
| B-09 | Medium | Persistent session revocation | DEFERRED | — |
| B-10 | Low | Frontend origin (Team 1) | OPEN | Team 1 integration |
| B-11 | Medium | Faculty user id ↔ Team 5 `faculty_id` mapping | OPEN (recorded 2026-11-20) | T-31 (unblocked by T-30) |

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
~~Recommendation: Team 5 owns teaching assignments. Team 4 displays them through `InstructorLookup` (implemented by Team 5).~~ (superseded)
**Resolved (owner, 2026-11-17):**
- **Team 4 owns the authoritative instructor-to-section assignment**, including the endpoint `POST`/`DELETE /api/registrar/sections/{id}/instructors`.
- **Team 5 owns faculty profiles and grade management.** It consumes assignments through Team 4's `SectionDirectory` (`isAssigned`, `sectionIdsForFaculty`) and must not keep a conflicting authoritative assignment.
- **Instructor ids must reference valid faculty identities.** They are checked through `FacultyIdentityLookup`, a Team 4 port implemented by Team 5. It fails closed until T-30.
- **Authorization is server-side.** Changes are validated and audited, with one `assignment_history` entry per change in the same transaction.
- **No duplicated faculty data and no Team 6 schema changes.**
- **Implementation:** T-21 (expanded). T-30 implements the lookup. T-31 uses Team 4's `isAssigned`. See `ARCHITECTURE.md` §4.1 and `docs/plans/T-20-terms-sections.md` §9.

## B-05 — Grade scale and finalization (Medium)
Not specified. **Proposed default:** 1.00–3.00 in 0.25 steps, 5.00 (fail), INC, DRP. Workflow is draft → submitted (faculty) → finalized (registrar).
Students see only finalized grades. Owner must confirm the scale, whether registrar approval is required, and whether finalized grades can ever be corrected.

## B-06 — Login identifier (Medium)
The frontend guide shows "username/password". A future shared schema may only have email.
**Resolved (owner, 2026-11-17):** login with **email**, trimmed and lowercased, unique. Team 6 must keep a unique email column on users.

## B-07 — Faculty access to student profiles (Medium)
Team 3 must "restrict faculty access to explicitly authorized information".
**Resolved (owner, 2026-11-17):** faculty may view only the approved summary fields (`API_CONTRACT_T10_STUDENTS.md` §7), only for students enrolled in classes officially assigned to them. If the assignment relationship is unavailable or unverified, access is denied. Non-visible records return 404, identical to a missing record. Enforced server-side; deny-all until T-31 provides a verified source.

## B-08 — Enrollment eligibility (Low)
MVP rules are in `API_CONTRACTS.md`. Not included: prerequisites, unit limits, enrollment periods, dropping or cancelling.
**Recommendation:** keep them out of MVP. A term-level `enrollment_open` flag is the only period control.

## B-09 — Persistent session revocation (DEFERRED)
A password reset or role change should invalidate existing sessions. A durable fix needs a per-user version column in the shared schema (Team 6).
MVP: mock repositories keep an `auth_version` per user, and every request checks it. MySQL mode depends on B-03.

## B-10 — Frontend origin (Low)
API is same-origin, with session cookies and no CORS. Team 1 pages must be served by this same app (`public/`) or a same-origin proxy.

## B-11 — Faculty user id ↔ `faculty_id` (Medium)
- **The mismatch:** B-07 `FacultyStudentVisibility::sharedSections(int $facultyUserId, …)` is keyed by the Team 2 **user id**. T-21 assignments and `SectionDirectory` use the Team 5 **`faculty_id`**.
- **The mapping** needs `FacultyDirectory::findByUserId` (T-30). T-31 uses it: session user id → `faculty_id` → `SectionDirectory::isAssigned` / `sectionIdsForFaculty`, which also require an active identity (T-21 A14). If the mapping is missing or the lookup fails, access is denied.
- **Until T-30 and T-31:** `DenyAllFacultyVisibility` stays wired.
- **Unblocked by:** T-30. **Consumer:** T-31. Recorded by the owner's T-21 approval, 2026-11-20. See `docs/plans/T-21-instructor-assignments.md` §8.
