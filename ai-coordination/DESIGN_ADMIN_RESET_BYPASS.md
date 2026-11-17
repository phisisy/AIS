# Design proposal — Closing the demote → reset → re-promote bypass

Status: **PROPOSED, awaiting owner approval. Not implemented.** Date: 2026-11-17.

## 1. The bypass today (inspected)

Code on `main` (`8451cd4`):
- `UserService::doUpdate` lets admin A remove `admin` from admin B. Only self-changes are blocked, and the last-admin invariant needs just one other active admin.
- `doResetPassword` checks only the target's **current** roles (`in_array('admin', $target->roles)`).
- `doUpdate` lets A grant `admin` back.

So A can take over B's account in three successful, audited steps:

```
user.updated         target=B  roles: [admin] → [faculty]
user.password_reset  target=B  (B's sessions end; A now knows B's password)
user.updated         target=B  roles: [faculty] → [admin]
```

The direct reset is blocked; the indirect one is not. Client input plays no part, since roles come from storage. The flaw is that the check looks only at the target's current state.

## 2. Options considered

| Option | Idea | Verdict |
|---|---|---|
| A. Cool-down | Refuse resets for N days after admin was removed | Weak: the attacker just waits. Rejected |
| B. Block re-promotion after an admin reset | No `admin` grant until the user changes their own password | Fails: the attacker knows the reset password and can do the change themselves. Rejected |
| C. Persistent "admin-protected" flag on the user row | Set when `admin` is ever granted; resets refused while set | Simple, but needs a new user field. In MySQL that's a **schema change** (not allowed now). Kept as the future MySQL option |
| **D. Historical admin status from the server audit log** | Reset refused if the target **holds `admin` now or has ever held it**, derived from server-written audit history | Recommended. No new user field or schema; cannot be influenced by clients |
| **E. Separate owner-run recovery workflow** | Protected accounts are recovered only through an offline CLI procedure, not the web API | Recommended together with D, to avoid permanent lockout |

## 3. Recommended design (D + E)

### 3.1 "Admin-protected" accounts (server-side, inside the locked transaction)

A target is **admin-protected** if any of these holds:
1. It holds `admin` now (today's rule).
2. A **successful** admin audit entry shows it ever held `admin`:
   - `user.created` with `admin` in `changes.roles`, or
   - `user.updated` with `admin` in `changes.roles.from` or `changes.roles.to`.
3. It is listed in the store's `admin_history` index. This is a small server-maintained list of account ids that ever held `admin`. It's written in the same transaction as any change that grants or removes `admin`, and seeded once from the current admins. It covers seeded admins that have no audit entry, and keeps the rule working if the audit log is ever pruned.

`doResetPassword` refuses protected targets with the existing 409 and a new audit reason, `target_is_admin_protected`. Non-admin accounts that never held `admin` reset exactly as now.

Trust: everything comes from rows the server wrote under the same exclusive lock. The request supplies only the target id.

### 3.2 Recovery workflow for protected accounts (no permanent lockout)

A new CLI script, `bin/recover-account.php` (CLI only, refuses web SAPI):
- Run by the owner with filesystem access to the runtime directory. Arguments: target email, plus the owner's name/reason for the record.
- Generates a random temporary password, printed once, and bumps `auth_version` (all sessions end).
- Writes an audit entry `user.password_recovered`, `actor_id: 0` (system), `outcome: success`, `changes.reason` = a fixed code. Free-text reasons go to stdout only, never the log.
- Refuses `APP_ENV` values other than development/testing (consistent with T-01) and the mock repository otherwise. A MySQL equivalent comes with T-40.

A second-admin approval workflow is **not** included, per the owner, but would slot in here later.

### 3.3 Tests to add (when approved)

- Demote → reset is refused (409, `target_is_admin_protected`). Re-promotion afterwards changes nothing.
- Seeded admin demoted by direct store edit (no audit entry) is still protected, via `admin_history`.
- A never-admin account still resets normally (regression).
- Multi-process race: demote and reset of the same target in parallel. The reset never succeeds.
- Recovery CLI: works for a protected account; ends sessions; writes the system audit entry with no secrets; refused under web SAPI and an invalid `APP_ENV`.
- Mutation checks: remove the history rule → the bypass test fails.

## 4. Trade-offs

| Benefit | Cost |
|---|---|
| Closes the takeover path completely in mock mode, with no schema change | An account that was ever an admin can **never** be reset through the web API again, even long after a legitimate demotion. Recovery requires the owner's CLI |
| Server-derived; clients can't influence it | Adds a small server-maintained `admin_history` list in the mock store |
| Fully audited, including recovery | The recovery CLI is a privileged tool. Its safety depends on who can run PHP on the host (acceptable for dev; production needs a real process) |
| No permanent lockout: recovery exists | MySQL (T-40) must implement the same rule. It can derive it from the audit table, or, with Team 6 approval, use a persistent flag (option C) |

## 5. Decision needed

Approve **D + E** as above, or choose a variant:
- D only (no recovery CLI yet): simpler, but ex-admins could be locked out until a recovery process exists.
- C instead of D: needs a schema change in MySQL later.
