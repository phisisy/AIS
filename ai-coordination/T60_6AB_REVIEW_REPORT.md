# T-60 Phases 6a/6b — independent review

- **Reviewer:** Claude Code `security-reviewer` subagent, read-only, static review. It did not implement 6a or 6b (Codex implemented them; Claude coordinated). Recorded by Claude from the reviewer's report, without changes to findings or verdict.
- **Independence note:** the reviewer runs in the same Claude Code session family that coordinated the work. It is independent of the implementer (Codex), not of the coordinator.
- **Reviewed:** branch `task/T-60-database-architecture` at `5886888`. Code: `app/Auth/Repositories/MySQLUser{Repository,Transaction}.php`, `app/Students/Repositories/MySQL{StudentRepository,ProgramCatalog}.php`, and the user and student contract tests.
- **Evidence supplied by the lead:** online 224/224 on `ais_test`; offline 205/205; `php -l` 134/134; `ais_test_app` grants exact; forbidden statements denied with 1142.
- **Date:** 2026-11-21.

## VERDICT: APPROVE

No Critical or High findings; nothing blocks 6c. The Medium and Low items should be fixed before or alongside 6c, because 6c copies these patterns.

## Findings

| ID | Severity | Finding | Fix (owner) | Blocks 6c |
|---|---|---|---|---|
| M1 | Medium | **The MySQL contract suites never run the adapters as the app account.** Reset, migrations and fixtures need the owner account (`ais_tester`), so the 224 passing tests prove the adapters work with owner privileges. Nothing proves every adapter statement succeeds as `ais_test_app`. A later out-of-grant statement would pass the suite and fail in production with 1142 (`MySQLTestSupport.php:37,85-86`; `UserRepositoryContractMySQLTest.php:13-24`; `StudentRepositoryContractMySQLTest.php:14-26`) | Keep the owner connection for reset, migrate and fixtures; build the repository on a second, guarded connection as `ais_test_app`. Same for 6c (Codex) | No (before 7.2) |
| M2 | Medium | **The wildcard tests can't tell escaped from unescaped `%`/`_`.** They search for `'%_!'`; unescaped, that means "any character then `!`", and only the fixture row contains `!`, so the test passes either way (`UserRepositoryContract.php:39`; `StudentRepositoryContract.php:78`). The adapter code is correct | Add searches for `'%'` alone and `'_'` alone, each expecting exactly the one fixture row. Same for 6c (Codex) | No |
| L1 | Low | `SECURITY.md:121` says adapters let `PDOException` propagate unchanged. They actually wrap it in a generic `RuntimeException('… unavailable.')`. Safe, but the log loses the DB-outage vs corrupt-data distinction | Fix the doc to describe the wrapping (Claude) | No |
| L2 | Low | `ARCHITECTURE.md:129` says multi-statement reads use `START TRANSACTION READ ONLY`; the code uses a read-write `beginTransaction()`. Same consistency under REPEATABLE READ | Fix the doc to match the code (Claude) | No |
| L3 | Low | **Untested error paths:** a `PDOException` inside `transaction()` (sanitized, everything rolled back); a student write that succeeds before a failing history insert; `appendAudit` with a non-`…Z` timestamp (a documented MySQL deviation); fail-closed validation of corrupt history rows (`MySQLStudentRepository.php:83-104`) | Add MySQL-only cases using owner-connection fault injection; assert `RuntimeException`, no partial state, and `last_seq` unmoved (Codex) | No |
| L4 | Low | A callback that swallows a `PDOException` (for example after a 1213 deadlock, which InnoDB has already rolled back) would carry on outside the transaction and lock. No current caller does this | Add a rule to `ARCHITECTURE.md` §6: never catch `PDOException`/`Throwable` inside a repository transaction callback. Phase 9 checks it (Claude) | No |
| L5 | Low | Cross-module lock order isn't documented. With separate PDOs per module, nesting a students write inside an auth callback would wait on itself until `innodb_lock_wait_timeout` | Document that **one shared PDO per request is required for correctness**, and the lock order auth → students → enrollment → faculty (Claude). 6c wiring keeps a single PDO | No |
| I1 | Info | If `repository_locks('auth').last_seq` < `MAX(audit_logs.id)` after an import, every audited write collides on the key and returns 500 | The Phase 8 importer and `verify.php` set and check `last_seq = MAX(id)` (Codex, Phase 8) | — |
| I2 | Info | The order of the two duplicate-error keys can differ from the mock (same keys and messages) | Cosmetic | — |
| I3 | Info | An invalid history value gives a different message than the mock (both are a `RuntimeException` and a 500) | Accepted | — |
| I4 | Info | Existing time-of-check gap: `StudentService::create` checks the account outside the students lock (same in the mock; the foreign key now guarantees the account exists) | Not a regression | — |
| I5 | Info | Collation and case behaviour match PHP for emails; `LOWER()` vs `mb_strtolower` on rare characters is already documented | — | — |

## Verified as correct (reviewer's answers to the 11 review points)

1. **Interface compatibility:** signatures, exception classes and messages, ordering and totals match the mocks. The documented deviations are all recorded in `BLOCKERS.md`.
2. **SQL injection:** every value is bound. The only concatenations are fixed constants. `LIMIT`/`OFFSET` are bound as `PARAM_INT` with native prepares. `LIKE` escapes `!` first and has an explicit `ESCAPE`.
3. **Transactions:** every write is atomic. Exceptions from callbacks, from `$check` and the last-admin 409 all roll back. Nesting is refused, and rollback failures are sanitized.
4. **Locking:** the lock row is taken first on every write path, and decision reads come after it. `MAX(seq)` and `COUNT` take no gap locks. There is no lock cycle between students and auth.
5. **Sequences:** the audit id is `last_seq+1`, updated in the same transaction, so rollback restores it (a test proves it). The per-student `MAX(seq)+1` runs under the lock, backed by the primary key.
6. **Last admin:** both counts happen inside the locked transaction, so a second concurrent demotion waits and then sees the first commit. Phase 7.2 must still confirm this.
7. **Foreign keys:** violations come out as a generic `RuntimeException`, a 500 with no driver text (the services check first).
8. **Grants:** every adapter statement fits `app_grants.sql` (static read; M1 asks for runtime proof).
9. **Sanitization:** only fixed `HttpException` messages reach responses.
10. **Parity:** the deviations are documented. Timestamps and JSON key order round-trip.
11. **Contract tests:** the same bodies run against both backends. The gaps are M1, M2 and L3.

## Not verifiable statically

- Snapshot timing and deadlock freedom under real concurrency (Phase 7.2).
- That every statement actually runs as `ais_test_app` (M1).
- `LOWER()` vs `mb_strtolower` on MariaDB 10.4's case tables.
- The eventual production MariaDB version (S7).

## Resolution plan (Claude, 2026-11-21)

- **L1, L2, L4, L5:** Claude fixes the docs now.
- **M1, M2, L3:** assigned to Codex as part A of the next round, before or alongside 6c (part B). 6c applies the same corrected patterns from the start.
- **I1:** carried into Phase 8.
