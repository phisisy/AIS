# T-60 Phase 7.2 — MariaDB concurrency and transaction-integrity test report

- **Date:** 2026-11-21.
- **Branch:** `task/T-60-database-architecture`. Test commit `f4317ec` (Codex, committed by Claude), merged as `fed8e52`.
- **Database:** MariaDB 10.4.32 (development only, S7), **`ais_test` only**, guarded setup.
  - Repository operations run as **`ais_test_app`**, through the real MySQL adapters in separate worker processes.
  - `ais_tester` is used only for reset, fixtures, controlled lock holding, the deliberate-deadlock injection and verification.
- **Scope:** tests only. No `app/` file changed, no wiring, no `APP_REPOSITORY=mysql`, no `ais` database or import.

## Method

- **Workers:** separate PHP processes. Each connects as `ais_test_app`, runs one adapter operation, and prints one JSON result: `ok`, `status`, a whitelisted `message`, `class` and the driver code only.
- **Deterministic synchronization:**
  - **File start barriers:** workers signal "ready", then wait for a start file, with a bounded 10 s poll.
  - **Controlled contention:** where ordering matters, the parent (as `ais_tester`) holds `repository_locks … FOR UPDATE` in an open transaction, releases the workers, then commits.
  - No sleep-only timing.
- **Bounds:**
  - Session `innodb_lock_wait_timeout` of 1–10 s in the workers, validated as an integer.
  - A hard process timeout: kill and fail.
  - Rollback and close in every `finally`.
- **Repetition:** every race runs **3 rounds**, and the whole suite was run **twice**. The workers' completion order is recorded per round.

## Concurrency matrix

| ID | Invariant | Result | Status |
|---|---|---|---|
| A1 | Concurrent updates of different fields on one user are both kept (no lost update) | PASS ×2 runs | **Proven** |
| A2 | Competing role changes: final roles equal one complete write's set (never mixed); `auth_version` matches | PASS ×2 | **Proven** |
| A3 | **Two active admins, two simultaneous demotions: exactly one succeeds, the other gets 409 `LAST_ADMIN_MESSAGE`; active admins never reach 0** | PASS ×2 | **Proven** |
| A4 | Audit ids gap-free and equal to committed writes; no orphan audit rows; `last_seq = MAX(audit_logs.id)` | PASS ×2 | **Proven** |
| S1 | Concurrent updates of different fields on one student are both kept | PASS ×2 | **Proven** |
| S2 | Per-student history `seq` is gap-free, one entry per committed tracked change | PASS ×2 | **Proven** |
| S3 | Duplicate student-number and duplicate-account create races: exactly one winner, the losers get 409 | PASS ×2 | **Proven** |
| S4 | A failing update (thrown `$check`) racing successful ones leaves no partial row or history | PASS ×2 | **Proven** |
| C1 | Concurrent current-term switches leave exactly one current term | PASS ×2 | **Proven** |
| C2 | Concurrent section patches of different fields are all kept | PASS ×2 | **Proven** |
| C3 | **Same faculty assigned concurrently to two overlapping sections: exactly one wins (409 for the other)** | PASS ×2 | **Proven** |
| C4 | Concurrent 5th-instructor assignments: exactly one succeeds, the rest get 409 at the cap | PASS ×2 | **Proven** |
| C5 | Assignment history gap-free, equal to committed assigns and unassigns, and replays to current rows (order via `assigned_seq`) | PASS ×2 | **Proven** |
| C6 | **A meeting patch racing a conflicting assignment never leaves an instructor schedule conflict** | PASS ×2 | **Proven** |
| T1 | Injected mid-operation failures roll back every affected table (no partial rows) | PASS ×2 | **Proven** |
| T2 | Locks are released after commit and after rollback; a follow-up write proceeds | PASS ×2 | **Proven** |
| T3 | A deliberate cross-store deadlock: exactly one 1213 victim, which fails with a sanitized `RuntimeException` and rolls back fully; the survivor commits | PASS ×2 | **Proven** (documented failure behaviour) |
| T4 | A lock-wait timeout (1205) from a held lock gives a sanitized failure with nothing written | PASS ×2 | **Proven** |

**Deadlocks through the adapters alone: argued, not proven.** Each adapter operation takes exactly one store lock row first, and the services never combine stores in one transaction (ARCHITECTURE §6.3). So the adapters can't form a lock cycle on their own. T3 had to inject the cycle with tester-level locks. No adapter-only race in either run produced a 1213, but a test can't prove that no deadlock can ever happen.

## Lock and transaction behaviour observed

- **Serialization:** writers serialize on their store's `repository_locks` row. A waiting writer's first consistent read comes after the lock, so it sees the previous commit. That is what makes A3, C3 and C6 safe.
- **Ordering:** worker completion orders varied across rounds and runs. Auth showed `0,1` and `1,0`; students showed `0,1,2`-style permutations, including `1,0,2`, `2,1,0` and `2,0,1`; enrollment showed `0,1`, `1,0` and `1,2,0`. Each invariant held under every observed order.
- **Deadlock:** the deliberate deadlock (T3) resolved with one victim per round; the victim was the same each time (completion order `1,0`).
- **Timeouts:** T4 hit 1205 only where a lock was held on purpose. There were no unexpected timeouts.
- **Non-determinism:** no flaky results. The full suite passed **261/261 twice**.

## Defects found

- **Repository defects: none.** No adapter, service or interface needed changing.
- **Test defect (fixed):** the first online run failed A1, A2, A4, T2 and T3, because the fixture created users 3–12 with no roles.
  - The MySQL adapters correctly failed closed ("Invalid stored user."). Claude confirmed this by running the update directly as `ais_test_app`.
  - Codex fixed the fixture (one line; users 1–2 admin, 3–8 faculty, 9–12 student) without changing any assertion.

## Regression and syntax results (final)

| Run | Result |
|---|---|
| Online, `ais_test` (offline + Phase 5 + contracts + Phase 7.2), run 1 | **261 passed, 0 failed** |
| Online, run 2 | **261 passed, 0 failed** |
| Offline (no DB env) | **211 passed, 0 failed** |
| `php -l` | **144 files, 0 failures** |

## Security and transaction-integrity findings

- **Grants and accounts:**
  - All operations ran within the approved `ais_test_app` grants, and no privilege escalation was needed.
  - The tester account was used only for setup, deliberate fault injection and checks.
  - No database other than `ais_test` was touched, and the guard was re-checked before every reset.
- **Errors:** they stayed sanitized under deadlock and timeout. Workers saw only `"<Module> repository unavailable."` plus driver codes, and never SQL text.
- **No automatic retries were added.** The documented behaviour stands: a deadlock or timeout reaches the caller as a generic 500, with everything rolled back.

## Remaining blockers and limits

- **Faculty repository:** waits for T-30. T-22 is not authorized.
- **MariaDB 10.4.32 is development-only.** Phase 7 must be re-run on the production MariaDB release (S7).
- **Strict mode and UTC** are not yet tested against a non-strict server.
- **Not started:** wiring (Phase 7.1), `verify.php` and import (Phase 8), the final independent review (Phase 9), any production database or cutover.

## Recommendation

**Ready to proceed** to the next phase the owner chooses:
- (a) Phase 8 tooling: `verify.php` plus `import_json.php`, tested in `ais_test` only (dry runs from a copied development runtime), including the I-1 audit-counter alignment; or
- (b) a Phase 9 independent review of everything built so far (6a–6c plus 7.2).

Wiring (7.1) and cutover remain separate owner decisions.
