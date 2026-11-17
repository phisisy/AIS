# Implementation proposal — Protected administrator registry & owner-only recovery

Status: **PROPOSED for separate approval. Planning only; not implemented, and not part of T-10.**
Date: 2026-11-17. Supersedes the recommendation in `DESIGN_ADMIN_RESET_BYPASS.md` with the owner's modifications (persistent registry; not audit-derived only).

## 1. Protected administrator registry

### 1.1 Storage and consistency
- A new top-level key in the **same** mock user store file as users and audit (`auth/users.json`):
  ```json
  "admin_registry": {"version": 1, "ids": [1, 2, 5]}
  ```
  Being in the same file as users and audit, the registry is read and written under the same exclusive `JsonStore` lock as every user mutation. That gives one atomic unit for "grant admin + add to registry + audit entry". No second file, so the two can never diverge.
- **Write rules (repository-enforced, not service-enforced):**
  - `MockUserTransaction::insert()` and `update()` add the id whenever the resulting row holds `admin`, inside the same transaction.
  - There is **no method** that removes an id. Deactivation, demotion and email changes never touch the registry.
  - `MockUserRepository::transaction()` re-checks at commit that every currently-admin row is in the registry. If not (code bypassed `update`), it throws and nothing is written.
- **Bootstrap / migration of existing stores:**
  - A store without `admin_registry` is **not** silently treated as empty.
  - The first transaction initializes the registry from every row currently holding `admin`, plus every target of a successful audit entry that granted or created `admin` (so the T-03 history is honoured).
  - The seeder writes the registry explicitly.
- **Fail closed:** if `admin_registry` exists but is malformed (wrong type, non-int ids, wrong version), every admin mutation and every password reset throws a generic 500, and the store is not modified. Login, `me` and reads still work, so no lockout.

### 1.2 Enforcement
- `doResetPassword` refuses (409, audit reason `target_is_admin_protected`) when the target id is in the registry **or** currently holds `admin`. This check runs inside the locked transaction.
- The registry is never exposed in any response, and no client field can set or clear it (field allowlists are unchanged).
- **Concurrency:** demote and reset of the same target are serialized by the store lock. Because demotion never clears the registry, the reset fails whatever the order.

### 1.3 MySQL (T-40)
The equivalent needs either a persistent column (`users.admin_protected` / `protected_since`) or a separate table. Either is a **Team 6 schema decision**. Until it exists, MySQL mode stays fail-closed, as it is today. No schema change is proposed here.

## 2. Owner-only recovery CLI: `bin/recover-account.php`

### 2.1 Authorization (explicit, local)
- Refuses anything but `PHP_SAPI === 'cli'`. The `bin/` folder sits outside `public/`, and both `.htaccess` files send everything to `public/index.php`.
- Refuses unless the runtime path validates (`RuntimeDirectory`) and `APP_ENV` is development or testing. Production is blocked as everywhere else.
- **Owner recovery key:**
  - A one-time setup step writes `owner-recovery.key` (32 random bytes, hex) to the runtime directory, outside the project and OneDrive.
  - The CLI requires this key typed interactively (hidden input, never a command-line argument, so it never lands in shell history).
  - The key is checked with `hash_equals` against a stored `password_hash` of it. The key itself is never stored in plaintext after setup.
  - So recovery needs both local shell access and the owner's key.
- Interactive confirmation: the owner retypes the target email before anything changes.

### 2.2 Credential handling
- The new password is either:
  - (a) generated: 20 random bytes, base64url, shown once on the terminal; or
  - (b) typed twice with hidden input.

  It's hashed with `AuthService::hashPassword()` and **never logged, echoed back in a log, or written anywhere except as the hash**.
- `auth_version` is bumped, so all of the target's sessions end.

### 2.3 Audit
- The audit entry is written in the **same transaction** as the password change: `action: user.password_recovered`, `actor_id: 0` (system/owner), `outcome: success`, `changes: {reason: "owner_recovery", signed_out_everywhere: true}`. The audit key allowlist is extended by exactly these values.
- Refused attempts (wrong key, unknown email, mismatched confirmation) append a `denied` entry with a reason code, best-effort, as in T-03. Free text goes to stdout only.

### 2.4 Single-administrator safety
- Recovery never changes roles or status, so the last-admin invariant can't be broken by recovery.
- If the only admin is also **inactive** (e.g. deactivated during an incident), the CLI offers a second, explicit, audited option, `--reactivate`, guarded by the same key and confirmation. It's the only way back in, so no permanent lockout.

## 3. Failure scenarios

| Scenario | Behaviour |
|---|---|
| Store lock busy (another request writing) | CLI waits (blocking lock, ~normal request time), then proceeds; never partially writes |
| Store write fails (disk, OneDrive hold beyond retry) | Transaction aborts. Password, `auth_version`, registry and audit unchanged. CLI exits non-zero with a fixed message |
| Corrupt user store | CLI refuses (fail closed). No auto-repair of user data |
| Malformed or missing registry | Missing: initialized as in 1.1. Malformed: CLI and admin mutations refuse until fixed (documented manual step) |
| Key file missing or unreadable | CLI refuses. Re-running setup requires deleting the old key deliberately, which is documented |
| Wrong key | Refused, `denied` audit, 3 attempts per run, then exit |
| Target not found / not protected | Refused. Non-protected accounts use the normal admin API |
| Terminal closed mid-run | Nothing written before final confirmation; the write is one transaction |

## 4. Tests (planned)
- Demote → reset refused; registry survives deactivation and demotion.
- Parallel demote and reset (multi-process): reset never succeeds.
- Missing registry initialized from current admins and audit history; malformed registry fails closed without changing the store.
- Repository commit check: an admin row missing from the registry aborts the transaction.
- CLI: non-CLI SAPI refused; wrong key refused and audited; success bumps `auth_version` and audits with no secrets; store write failure leaves everything unchanged; last-inactive-admin `--reactivate` path; no password appears in any log or file except as a bcrypt hash.
- Mutation checks for the registry rule and the commit check.

## 5. Scope and files (when approved)
`app/Auth/Repositories/MockUserTransaction.php`, `MockUserRepository.php`, `UserTransactionInterface.php`, `app/Auth/Services/UserService.php`, new `bin/recover-account.php` and `bin/setup-owner-recovery-key.php`, `database/seeders/dev_users.php`, `tests/Auth/*`, `README.md`. No Team 6 schema change.
