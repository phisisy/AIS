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

---

# v2 — Finalized security design (owner decision 2, 2026-11-17)

Status: **design finalized for approval; implementation NOT approved.** Proposed task id: **T-06**. Where v2 differs from sections 1–5, v2 wins.

## A. Requirement → mechanism

| # | Owner requirement | Mechanism | Verified by (planned test) |
|---|---|---|---|
| 1 | Protected status is persistent | `admin_registry.ids` in `auth/users.json`, under the runtime path (`C:/ais-runtime` by default, outside the repo and OneDrive) | Survives restart and re-read |
| 2 | Admin grants update the registry atomically | `MockUserTransaction::insert/update` adds the id in the **same** transaction that writes the grant and its audit entry | Grant + injected write failure leaves user, registry and audit all unchanged |
| 3 | Demotion doesn't clear it | No method removes ids; `update()` never touches existing ids | Demote, then the id is still present |
| 4 | Deactivation doesn't clear it | Same | Deactivate, then still present |
| 5 | Ordinary APIs can't modify it | No route, controller or service method writes it; field allowlists unchanged; the commit-time check (C) rejects any removal | A changed registry (removal) is refused at commit (500); a test greps that routes never reference it |
| 6 | Corruption fails closed for protected operations | Malformed registry gives a generic 500 for every admin mutation, password reset and recovery. Login, `me` and reads keep working | Corrupt registry: mutations 500, store byte-identical, login still OK |
| 7 | Keys securely generated | `random_bytes(32)`, shown once as 64 hex characters | Length, charset, uniqueness across runs |
| 8 | Keys never in the repository or OneDrive | Only a **bcrypt hash** of the key is stored, at `<runtime>/auth/owner-recovery.hash`. Setup **refuses** if the resolved runtime path is inside the project directory or inside any OneDrive root (`OneDrive`, `OneDriveConsumer`, `OneDriveCommercial` environment variables). The plaintext key is printed once and never written to disk | Setup refused for in-project and OneDrive paths; the hash file contains no plaintext; `git ls-files` has no key or hash |
| 9 | Explicit local authorization | CLI only (`PHP_SAPI === 'cli'`); hidden interactive key entry (never argv or env); the target email must be retyped; `APP_ENV` must be development or testing | Web SAPI refused; key via argv refused; wrong confirmation aborts with no write |
| 10 | Recovery is audited | `user.password_recovered` (and `user.reactivated_by_recovery`) with `actor_id: 0`, `outcome: success`, in the same transaction as the change; refusals get best-effort `denied` entries with reason codes. No secrets (existing allowlist plus filter) | Audit content; secret scan |
| 11 | Recovery ends existing sessions | Password change bumps `auth_version` (existing T-01 mechanism) | The target's open session gets 401 afterwards |
| 12 | Last-admin safeguard stays enforced | Recovery never removes roles or deactivates. `--reactivate` can only **add** an active admin. The commit-time invariant still runs | Invariant test passes; recovery cannot be used to deactivate |
| 13 | No public recovery endpoint | Nothing in `routes/`; the scripts live in `bin/` outside `public/`; both `.htaccess` files route only to `public/index.php`; the guard-coverage test lists every route | Route list unchanged; HTTP probes of `/bin/...` return 404 |

## B. Registry details (supersedes 1.1)

- Shape: `"admin_registry": {"version": 1, "ids": [..]}`, sorted, unique, positive ints.
- **Initialization:** when the key is missing on first use, it's built from the current admins plus audit history inside the first transaction. This happens **once**, and is itself audited (`registry.initialized`, `actor_id: 0`).
- **Commit-time check** (in `MockUserRepository::transaction`, beside the last-admin invariant):
  1. The registry after commit must contain the registry from before commit (nothing removed).
  2. Every row holding `admin` after commit must be in the registry.

  A violation throws and nothing is written.
- **Reset rule:** `doResetPassword` refuses any target in the registry, or currently holding admin (409, `target_is_admin_protected`), read under the lock.

## C. Recovery key lifecycle

1. **Setup** (`bin/setup-owner-recovery-key.php`): refuses if a hash already exists, or if the runtime path fails rule A8. Prints the key once, with instructions to store it offline (password manager or paper), not in OneDrive. Writes only the hash, with mode 0600 (Windows: inherits the runtime folder's ACL; documented).
2. **Use** (`bin/recover-account.php`): 3 key attempts per run; each failure is audited as `denied`/`bad_recovery_key`; there's a 1-second delay between attempts.
3. **Rotation:** the owner deletes the hash file deliberately (documented manual step) and re-runs setup. That deletion is the "I am the owner" act: it needs the same local access.

## D. Threat model (stated honestly)

- **Protects against:**
  - an administrator taking over another administrator's account through the API (direct or demote-reset-promote)
  - recovery being reachable over the network
  - a stolen web session being used for recovery
  - accidental lockout of the last administrator
- **Does not protect against:** an attacker with local shell access as the owner's OS user. Such an attacker can already edit the mock JSON store directly. The key adds a deliberate, audited step, but isn't a boundary against a fully compromised host. Production needs OS-level separation and a real recovery procedure (out of scope; production remains blocked).

## E. Failure scenarios added in v2

| Scenario | Behaviour |
|---|---|
| Runtime path inside the repo or OneDrive at setup | Setup refuses; nothing written |
| Hash file present but unreadable or malformed | Recovery refuses (fail closed); the owner must rotate deliberately |
| Registry initialization fails mid-way | One transaction: nothing written; retried on the next operation |
| Clock or audit failure during recovery | Transaction aborts; password, `auth_version` and audit unchanged |

## F. Scope when approved (T-06)

`app/Auth/Repositories/{MockUserTransaction,MockUserRepository,UserTransactionInterface}.php`, `app/Auth/Services/UserService.php`, `bin/setup-owner-recovery-key.php`, `bin/recover-account.php`, `database/seeders/dev_users.php`, `tests/Auth/*` (including CLI tests through `proc_open` with piped input), `README.md`. No Team 6 schema change; MySQL stays fail-closed.
