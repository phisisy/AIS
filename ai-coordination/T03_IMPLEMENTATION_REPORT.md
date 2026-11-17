# T-03 Implementation Report — User Administration & Audit Logging (Team 2)

Date: 2026-11-17 · Implementer: Claude (owner-approved takeover) · Branch: `task/T-03-admin`
Base: `main` at `96052c2` (T-02 merge) · Implementation commit: **`bf5a268`** · Status: **ready for owner review, not merged**

## 0. Preconditions completed

- **T-02 merged:** `task/T-02-rbac` (`dda0829`) merged into `main` with `--no-ff`. **Merge commit `96052c2`.** Pre-merge checks:
  - 62/62 tests and 49/49 PHP files lint clean
  - clean tree, only T-02 files
  - review findings resolved or documented
  - merge-base = `main` (`c434a27`)
  - 62/62 tests again on `main` after the merge
- **Decision 2 (role-change security) verified against the code before any change:**
  - `AuthService::currentUser()` reloads the account on every request (`AuthService.php:63-65`), and the guard checks roles on that copy. Demotion is therefore already immediate; the T-02 test "role changes apply on the next request" proves it.
  - No forced re-sign-in was added for role changes, as the policy directs.

## 1. Features implemented

The owner's A1–A10 list, mapped to the code:
- **A1–A2:** listing (email search, paginated) and retrieval.
- **A3:** creation with an initial role set and status.
- **A4:** email updates.
- **A5–A6:** activation and deactivation.
- **A7–A8:** role assignment and removal, sent as the full new role set.
- **A9:** admin-initiated password reset with admin re-confirmation.
- **A10:** validation and case-insensitive duplicate-email prevention.

Every admin change is audited atomically. Refused attempts are audited separately.

Not built, to avoid unnecessary endpoints:
- the admin dashboard
- an audit-log read endpoint
- account deletion (nothing deletes accounts)

## 2. Files changed (`git diff --stat main...bf5a268`: 14 files, +1280 / −10)

| File | Change |
|---|---|
| `app/Auth/Services/UserService.php` | New. All admin operations, validation, the actor re-check under the lock, denial auditing |
| `app/Auth/Controllers/UserController.php` | New. Thin HTTP layer with field allowlists |
| `app/Auth/Repositories/UserAdminRepositoryInterface.php` | New. `search`, `transaction` (with the last-admin invariant), `auditEntries` |
| `app/Auth/Repositories/UserTransactionInterface.php` | New. `find`, `findByEmail`, `insert`, `update`, `appendAudit`, plus the contract a MySQL version must meet |
| `app/Auth/Repositories/MockUserTransaction.php` | New. Working copy inside the lock; email uniqueness; `auth_version` bumps; audit key allowlist and secret filter |
| `app/Auth/Repositories/MockUserRepository.php` | Now implements `UserAdminRepositoryInterface`. T-01 methods unchanged |
| `routes/auth.php` | 6 admin routes, all `guard->roles(['admin'])` |
| `app/bootstrap.php` | Wiring; separate re-auth rate-limit store |
| `tests/Auth/AdminServiceTest.php`, `AdminHttpTest.php`, `AdminConcurrencyTest.php`, `AdminSupport.php`, `fixtures/admin_worker.php` | New tests |
| `README.md` | Admin API, audit behaviour, session effects |

T-01 and T-02 code was not rebuilt. No other team's module, `app/Core` file, or database was touched.

## 3. Endpoints added

| Method | Path | Body / query | Success | Notable errors |
|---|---|---|---|---|
| GET | `/api/admin/users` | `search`, `page` (1–1e6), `per_page` (1–100) | 200 `{users, pagination}` | 422 bad query |
| POST | `/api/admin/users` | `{email,password,roles[],status?}` | 201 `{user}` | 409 duplicate email, 422 |
| GET | `/api/admin/users/{id}` | — | 200 | 404 |
| PATCH | `/api/admin/users/{id}` | `{email?,roles?}` | 200 | 409 duplicate / self-role, 422, 404 |
| PATCH | `/api/admin/users/{id}/status` | `{status}` | 200 | 409 self / last admin, 422, 404 |
| POST | `/api/admin/users/{id}/reset-password` | `{password,current_password}` | 200 | 403 confirmation, 409 self, 429, 422, 404 |

Every route also returns 401 when not signed in and 403 for a non-admin or a missing or invalid CSRF token on writes. Unknown JSON fields return 422.

## 4. Authorization rules

- **Route level:** `RouteGuard` with the `admin` role only, then CSRF on POST/PATCH. A guard-coverage test fails if any route is unwrapped.
- **Service level:** every method calls `requireRole(admin)` itself. Every mutation also re-reads the actor **inside the exclusive lock**, and rejects them if they are deleted, inactive, demoted, or have a changed `auth_version` (password reset). This closes the gap between the guard check and the write.
- **No self-escalation or self-lockout:** admins cannot change their own roles, status or password through these endpoints (409). Changing only their own email is allowed. No hierarchy: admin rights come only from the `admin` role.
- **No mass assignment:**
  - Field allowlists per route.
  - Updates accept only `email` and `roles`. `id`, `auth_version` and `password_hash` cannot be set by clients.
  - Roles must be a non-empty list of known roles, matched exactly.
- **Responses:** `{id,email,roles,status}` only. Never hashes, `auth_version`, tokens or sessions.

## 5. Audit logging

- **Atomic:** users and audit live in one JSON file, written by one exclusive `JsonStore` transaction. A change and its audit entry commit together or not at all. If the audit write fails, the change is not saved. Tests cover an audit-build failure, a secret-key refusal, and a real store-write failure where a second process holds the file open.
- **Entry fields:** `id, at (UTC ISO-8601), actor_id, target_id, action, outcome, changes`.
  - Actions: `user.created`, `user.updated`, `user.activated`, `user.deactivated`, `user.password_reset`.
  - `changes` holds before/after values for email, roles and status. A reset records only `signed_out_everywhere: true`.
- **Outcomes:** `success`, or `denied` with a reason code. A refusal by the user service (401/403/404/409/429) writes a separate denied entry. Reasons:
  - `actor_not_signed_in_or_inactive`
  - `actor_not_admin`
  - `confirmation_failed`
  - `target_not_found`
  - `duplicate_email`
  - `self_change_not_allowed`
  - `last_active_admin`
  - `rate_limited`

  Submitted values are never recorded. If writing a denied entry fails, the original error is still returned.
- **No secrets:**
  - Top-level `changes` keys are limited to `email, roles, status, signed_out_everywhere, reason`.
  - Keys naming password, hash, token, secret, CSRF, session or cookie are refused at any depth.
  - Tests scan the log for `$2y$`, `password_hash`, the passwords, `csrf` and `AIS_SESSION`.
- **Not audited:** 422 validation errors, and requests the route guard refuses before the service runs (e.g. a student calling an admin route). The guard does not log.

## 6. Last-active-administrator guarantees

Users and audit share one file behind an exclusive lock held for the whole read-modify-write, so every admin operation in mock mode is serialized. Two independent protections sit inside that lock:
1. **Repository invariant at commit:** if at least one active admin existed when the transaction began, at least one must exist at commit, or it throws 409 and nothing is written. This is computed from the full row set under the lock, not a separate count. It covers deactivation and role removal by any caller, including code that skips the service.
2. **Service re-check of the actor under the lock:** a request whose actor was demoted, deactivated or reset while it waited cannot proceed.

There is no delete operation, so deleting the last admin isn't possible.

**Evidence:**
- **Races:** real multi-process tests with a start barrier and a shared lock:
  - The only two admins remove each other: 3 combinations (deactivate/demote), each exactly one win, one admin left, one success and one denied entry.
  - Three admins in a cycle: 3 rounds, always ≥ 1 admin, audit counts match.
- **Mutation checks:**
  - Removing only protection 2: the stale-actor test fails, but races stay safe (protection 1 holds).
  - Removing only protection 1: the invariant test fails, but races stay safe (protection 2 holds).
  - Removing both: **both race tests fail** (0 admins). So the tests catch an unsafe count-then-update.

**Limits:**
- These guarantees are for the file-backed mock repository on one host.
- **MariaDB (T-40, blocked on Team 6):** the MySQL transaction must give the same guarantee. Options are a transaction plus `SELECT … FOR UPDATE` on the admin role or users rows used for the decision, or one shared guard row locked first by every writer of users and roles. It must also re-check at commit. Until T-40 implements and tests this, MySQL mode stays fail-closed (bootstrap refuses `APP_REPOSITORY=mysql`).

## 7. Session behaviour after changes (owner Decision 2)

| Change | Effect on the target's existing sessions | Mechanism |
|---|---|---|
| Role promotion | New rights on the next request, no re-login | Account reloaded every request |
| Role demotion | Removed rights stop on the next request | Same; verified, so no forced sign-out added |
| Deactivation | Next request 401; session destroyed. **Reactivation does not revive it** | Status check, plus `auth_version` bump on deactivation (review fix) |
| Password reset | Next request 401 everywhere; old password rejected | Existing T-01 `auth_version` mechanism, preserved |
| Actor changed mid-request | Admin request already past the guard is refused | Re-check under the lock, including `auth_version` |

**Limits:**
- A request that has already committed is not undone.
- There is no "log out all my sessions" endpoint.
- With MySQL, `auth_version` needs a persistent column (Team 6, blocker B-09).

## 8. Tests

| Run | Result |
|---|---|
| `php tests/run.php` (full suite) | **82 passed, 0 failed**: 62 pre-existing (all preserved) + 20 T-03 |
| PHP syntax, all files | 59 files, 0 failures |
| `tests/Core/apache_check.php` | PASS, both document roots |
| Mutation checks | Listed in section 6. All reverted; suite back to 82/82 |

T-03 test coverage, against the owner's list E:
- creation
- duplicate email
- updates
- activation and deactivation
- role assignment, removal and multi-role
- password reset with re-confirmation and rate limit
- unauthorized (anonymous, student, faculty)
- CSRF rejection
- inactive and stale admins
- last-admin protection
- concurrency
- audit content, secrets and denied entries
- rollback (audit failure and real write failure)
- session effects over HTTP
- invalid requests (field, type, range, unknown field, bad id)

All tests use isolated temporary directories and fictional `@example.test` accounts. No database was used; `C:/ais-runtime` was not created.

## 9. Security review (read-only subagent) and findings

No Critical or High findings.

| Finding | Severity | Resolution |
|---|---|---|
| Failed or denied admin actions were never audited (`outcome` always success) | Medium | **Fixed:** separate `denied` entries with reason codes; tested |
| Actor re-check ignored `auth_version` (reset between guard and lock) | Low | **Fixed** and tested |
| Deactivation didn't bump `auth_version` (stolen cookie revived on reactivation) | Low | **Fixed** and tested |
| Re-auth limiter shared the login bucket | Low | **Fixed:** separate store. Successful re-confirmations still count (5 resets per admin+IP per 15 min), and the 429 text says "login attempts". Accepted for dev |
| Audit filter checked keys only | Info | **Hardened:** top-level key allowlist |
| An admin can reset another admin's password | Info | **Open, owner decision** (section 10) |
| Race tests never reach the commit-time invariant | Info | Acknowledged; the invariant is covered by its own test and by mutation check C |

## 10. Remaining blockers and decisions

1. **Owner decision:** should resetting another **admin's** password be blocked, or require a second admin? Today it's allowed, re-confirmed and audited.
2. **Owner decision:** should route-guard refusals (non-admins probing admin routes) be security-logged? T-03 audits only refusals inside the admin service.
3. **Deferred endpoints:** the admin dashboard and an audit-log read endpoint (in the original contract; left out per "no unnecessary endpoints").
4. **B-03 / B-09 / T-40:** a MySQL repository with equivalent transaction, locking and invariant behaviour, and a persistent `auth_version` column, both need Team 6's schema. Production stays blocked.

## 11. Git

- T-02 merge into `main`: `96052c2`
- T-03 implementation commit on `task/T-03-admin`: **`bf5a268`**
- Followed by a documentation-only commit adding this report and the board and review updates.

## 12. Readiness

**Ready for owner review.** Every A–F requirement is either implemented and tested, or explicitly listed in section 10. T-03 is **not merged** and T-04 is not started. Codex and Gemini should stay closed until the owner confirms.

---

## 13. Owner review decisions and merge (2026-11-17)

### Decision 2: admin-to-admin password resets blocked (commit `0253cc0`)
- `POST /api/admin/users/{id}/reset-password` now refuses any target holding `admin` (active or inactive, including multi-role) with **409** `{"success":false,"message":"Administrator passwords cannot be reset through this endpoint.","errors":{}}`. The check runs inside the locked transaction, so it reads current data.
- Preserved:
  - Admin password re-confirmation and its rate limit run first. Someone who hasn't confirmed their password only ever sees 403/429.
  - Admin → non-admin resets work as before.
  - Self-reset still returns its own 409.
- Audit: refusals are recorded as `user.password_reset` / `denied` / `target_is_admin`. The target's password and `auth_version` are unchanged, and its sessions stay valid.
- Regression tests (+2):
  - service: active, inactive and multi-role admin targets refused; non-admin reset works; audit sequence checked
  - HTTP: exact 409 body; target session still valid; attempted password rejected

  The existing `auth_version` re-check test now simulates the password change directly in storage, since the API path is blocked by design.
- Mutation check: removing the block makes both new tests fail; restored.
- Security verification (read-only subagent): no Critical, High or Medium issues. Bypass via input, roles, status or race: none.
- **Known gap (Low, documented in README):** demote → reset → re-promote still achieves the outcome. Every step is audited for the same `target_id`, so it's detectable but not prevented.
- **Open need (documented):** a secure administrator account-recovery process. No second-admin approval workflow was built, per the owner.

### Pre-merge checks (all passed)
1. Tests: 84/84 (82 reported + 2 new regression tests).
2. PHP syntax: 59 files, 0 failures.
3. Apache: PASS for both document roots.
4. Working tree: clean, no ignored files.
5. Scope: only `app/Auth/`, `tests/Auth/`, `routes/auth.php`, `app/bootstrap.php`, `README.md`, `ai-coordination/`. Branch based on `main` `96052c2`.
6. Security findings: all fixed except the Low multi-step gap, which is documented.
7. Sensitive data: no `.env`, sessions, stores, keys or hashes tracked (only the documented constant `DUMMY_HASH`). `C:/ais-runtime` was not created.

### Merge
- `task/T-03-admin` (`bf5a268`, `45f660f`, `0253cc0`) merged into `main` with `--no-ff` as **`8451cd4`**.
- On `main` after the merge: **84 passed, 0 failed**. Apache PASS.

### Other decisions recorded (not implemented)
- **Decision 3:** security-event logging for refused admin-route access → new task **T-04** (PROPOSED; needs your go-ahead).
- **Decision 4:** admin dashboard deferred, to be coordinated with Team 1.
- **Decision 5:** read-only audit endpoint → **T-05** (PROPOSED, planning only).
- **Database limitation (restated):** the last-admin concurrency guarantee is proven for the file-backed mock storage only. **It is not claimed for MariaDB.** T-40 must implement and verify equivalent transactional locking. `APP_REPOSITORY=mysql` remains refused at startup; no unsafe MySQL administration mode exists.
