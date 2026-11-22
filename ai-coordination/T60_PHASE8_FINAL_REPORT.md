# T-60 Phase 8 — final report (data-integrity verification + JSON→MariaDB import tooling)

- **Status: COMPLETE.** Independent re-review verdict: **APPROVE** on the exact corrected commit.
- **Date:** 2026-11-22.
- **Scope:** tooling only, against `ais_test` only. The running application is unchanged and still uses the JSON repositories.

## 1. Branch and commits

| Commit | What |
|---|---|
| `6a6547c` | Phase 8 plan, test matrix and Codex handoff (Task A) |
| `66f5877` | Codex: `verify.php`, `import_json.php`, tools and tests (committed by Claude, unedited) |
| `0d8becf` | Merge into `task/T-60-database-architecture` |
| `7af10ad` | Independent review #1 (REQUEST CHANGES) recorded |
| **`5114facf2f65a0a7d9f26bbaff2677ea7674b4c4`** | Codex: review fixes M1, M2, L1–L4 (committed by Claude, unedited) — **re-reviewed and APPROVED** |
| `f025f7d14825fc018ef23788e596900453a8dd14` | Merge of the fixes into the T-60 branch |
| this commit | Review #2 record and this report |

- **Branch:** `task/T-60-database-architecture`.
- **Not merged** to `main`; **not pushed**.

## 2. Files created or modified (Phase 8)

- **Created:**
  - `database/verify.php`, `database/import_json.php` (CLIs)
  - `database/tools/IntegrityVerifier.php`, `database/tools/JsonImporter.php`
  - `tests/Database/VerifyMySQLTest.php`, `tests/Database/ImportMySQLTest.php`, `tests/Database/ImportOfflineTest.php`
- **Docs:** `ai-coordination/T60_IMPLEMENTATION_REPORT.md` (Codex append), `ai-coordination/AGENT_HANDOFF.md`, `ai-coordination/T60_PHASE8_REVIEW_REPORT.md`, this report.
- **No existing `app/` file**, interface, service, mock or adapter changed. No bootstrap, config or routes change.

## 3. `verify.php`

- **Read-only:** `SELECT` only, inside `START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT`, ending with ROLLBACK. It works with only `ais_test_app`'s grants.
- **Target:** `--target=ais_test` only. `DB_DATABASE=ais_test`, a loopback host, a numeric port and the account `ais_tester` or `ais_test_app` are required, then the live `DATABASE()`, `@@port` and `CURRENT_USER()` are checked.
- **Checks** (CONSTRAINTS_AND_MIGRATIONS §4):
  - per-student history seq 1..n, with replay equal to the current values;
  - assignment history 1..n, replay equal to `section_instructors`, and `assigned_seq` the latest entry;
  - at most 5 instructors per section, and no same-term overlap per instructor;
  - scheduled sections have at least one meeting and no self-overlap;
  - audit ids 1..n;
  - `last_seq` equals the stream maximum for every lock row;
  - every user has at least one role;
  - `year_level` ≤ the program maximum;
  - at most one current term;
  - every row passes its PHP model (User, Program, Student, Term, Section; faculty structural until T-30).
- **Output:** check name and PASS/FAIL, with **numeric ids only**.
- **Exit codes:** 0 clean, 1 violations, 2 error (sanitized).
- **Not a substitute** for the JSON mocks' per-request validation (S1). It runs before cutover, after imports and migrations, before backups, and whenever integrity is in doubt.

## 4. `import_json.php`

- **Usage:** `php database/import_json.php --module=auth|students|enrollment --runtime=<abs dir> --target=ais_test [--dry-run]`.
- **Command line only.** Uses the migration account (`ais_tester` in `ais_test`), never root.
- **Supported modules, in this order:**

| Module | Imports | Lock counter after import |
|---|---|---|
| auth | users (original ids, hashes), roles (as sorted **sets**), audit log (ids and JSON changes) | **I1:** `last_seq` = MAX(audit id) |
| students | programs, students (ids), per-student history (seq, typed from/to) | stays 0 (per-student MAX+1 design) |
| enrollment | terms, sections, meetings (`position`), assignment history (seq), current assignments with `assigned_seq` = latest | `last_seq` = MAX(history seq) |

- **Deferred:**
  - faculty (`--module=faculty` → exit 2 until T-30);
  - T-22 enrollments and grades;
  - rate limiters, security events and sessions, which stay file-based.
- **Single-use, not idempotent:** the target tables must be empty and `last_seq` 0, otherwise the import is refused (exit 2). It never merges, overwrites, deletes, repairs, drops or fabricates.

**Source safety:**
- Absolute local paths only; relative, URL and UNC/network paths are refused before any filesystem access.
- The **live runtime is refused**: `C:/ais-runtime`, `<project>/storage/runtime` and the `.env`-file `APP_RUNTIME_PATH`, compared by `realpath`. That covers case, slash, trailing-separator, dot-segment and junction spellings.
- Required `.json` and `.lock` files must already exist, so **nothing is created or written in the source**.
- The source is read **once**, under a shared lock on its existing `.lock` file.
- The mocks validate a **temporary snapshot copy**.

**Per module, all in ONE transaction:**
1. Check the live target.
2. Check preconditions under the lock (schema applied, tables empty, `last_seq` 0, dependencies imported).
3. Insert with the original ids and seqs.
4. Set the counter.
5. Before COMMIT, check:
   - row counts;
   - **mock vs MySQL interface equality** (strict, with roles as sets), including **password hashes** and every faculty id from assignments and history;
   - the full integrity verifier;
   - the **source re-hash** (abort on any change).
6. COMMIT only if every check passes; any failure → ROLLBACK, exit 1. `--dry-run` always rolls back.

**Output:** counts and fixed messages only.

## 5. Import and rollback guarantees (each proven by a test)

| Guarantee | Test |
|---|---|
| A valid import preserves ids, history and audit order; counts and equivalence hold; the verifier is clean | E1/E2/E3/E9/E10/E11/V1/V3 |
| A corrupt source aborts before any write | E4/E6 |
| A mid-insert foreign-key failure rolls back the whole module | E5/E7 |
| **An equivalence failure after all inserts rolls back everything:** auth tables empty, `last_seq` 0 (trigger-injected) | E8 (post-insert) |
| A repeat import is refused; users, roles, audit and lock row are unchanged; a dry run writes nothing | E4/E8/E12 |
| Dependency, schema-not-applied and dirty-target refusals | Phase 8 refusals |
| A missing source file or `.lock` → exit 2, **source directory unchanged** | offline M1 test |
| Live runtime refused (from the `.env` file, not the process env) | offline M2 test, plus the lead's Windows probe |
| UNC and network paths refused | offline L2 test |
| Non-test target, host or account mismatch, root refused; faculty deferred | offline E12 tests |

## 6. Integrity-verification results

- **V1:** a clean import verifies with every check PASS.
- **V2:** every injected corruption is detected by name, with ids only. That includes the three cases added in review: an invalid student model, a wrong meeting position and an invalid faculty model.
- **V3:** the verifier writes nothing and runs as `ais_test_app`.
- **V4:** a database failure gives a sanitized message.

## 7. Test results (final, at `f025f7d`)

| Run | Result |
|---|---|
| Offline (no DB env) | **220 passed, 0 failed** |
| Online, `ais_test` | **278 passed, 0 failed**: 17 Phase 8 tests, 18 Phase 7.2 concurrency tests, 28 MySQL contract cases, Phase 5 migration, constraint, privilege and guard tests, and the full JSON regression |
| `php -l` | **151 files, 0 failures** |

**Defects found during Phase 8 and fixed:**
- A Windows path-regex bug that refused every backslash path (found by the online run).
- Review #1's M1–L4.

No repository defects were found, and no existing app code changed.

## 8. Independent review

- **Review #1** (`0d8becf`): REQUEST CHANGES, with M1 blocking.
- **Review #2** (exact `5114fac`): **APPROVE**. Details: `T60_PHASE8_REVIEW_REPORT.md`.
- **Reviewer:** Claude Code `security-reviewer` subagent; read-only and independent of the implementer (Codex). It isn't independent of the coordinator.

## 9. Security findings and remaining limitations

- **Confirmed safe:**
  - targets limited to `ais_test` and loopback;
  - live DB, port and account checks before any access;
  - no destructive SQL in either tool;
  - the verifier is read-only;
  - one transaction per module, with every check before COMMIT;
  - all values bound;
  - output limited to check names, counts and numeric ids;
  - no secrets committed.
- **Open, non-blocking:**
  - **N1 (Low):** a few offline refusal tests are now vacuous; fix before any merge to main.
  - **N2 (Low):** a hash/read window.
  - **N3 (Low):** a sub-folder junction or `subst` alias.
  - **N4 (Info):** temp snapshot copies of hashes.
- **Limitations:**
  - MariaDB 10.4.32 is development-only.
  - Strict/UTC is untested on a non-strict server.
  - The faculty model check is structural until T-30.
  - `verify.php` hardcodes `ais_test`; a future restore drill needs an approved change.
  - "App stopped" during import is a documented precondition, not enforced.
- **Confirmed for this phase:**
  - No production database was created or modified.
  - No live JSON runtime was accessed (none exists at `C:\ais-runtime`; tests use temp runtimes).
  - No credentials were exposed (secrets only in `C:\ais-secrets`, loaded into the environment per command, never printed).

## 10. Readiness

Phase 8 tooling is **complete and approved**.

**Recommended before merging T-60 to main:**
- fix N1 (and optionally N2–N4) in a small Codex round with re-verification;
- complete the Phase 7.1 decision (see `T60_PHASE71_READINESS_PLAN.md`).

Production cutover remains a separate, owner-approved gate.
