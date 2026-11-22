# T-60 Phase 7.1 — readiness assessment and plan (application wiring of the MySQL repositories)

- **Status: PROPOSED — not approved.** This is a read-only assessment. No code, config, test, database or git state was changed. Only this file was written.
- **Baseline:** `task/T-60-database-architecture` at `f025f7d` (Phases 5, 6a–6c, 7.2 and 8 + review fixes done).
- **Author:** Claude (architect subagent), 2026-11-22.
- **Owner restrictions still in force:** no `APP_REPOSITORY=mysql` for the running app, no live wiring, no production database or migration, no cutover, no live JSON import. This plan keeps every one of them, including in code (§2.1 gate W-G).

---

## 1. What can be wired now and what stays deferred

| Module (config key) | Adapters | Wire in 7.1? | Reason |
|---|---|---|---|
| **auth** (`auth`) | `MySQLUserRepository` (+ `MySQLUserTransaction`) | **Yes** | 6a approved, contract V7 + concurrency A1–A4 proven as `ais_test_app`. Every other module depends on it (config rule already enforced in `config/app.php:19`) |
| **students** (`students`) | `MySQLStudentRepository`, `MySQLProgramCatalog` | **Yes** | 6b approved, V7 + S1–S4 proven. `programs` and `students` switch **together** under the `students` key (FK `students.program_code → programs`) |
| **catalog** (`enrollment`) | `MySQLCatalogRepository` (T-20 terms/sections, T-21 assignments) | **Yes, test-only** | 6c approved, V7 + C1–C6 proven. Production lookup is `UnavailableFacultyIdentity`, so no assignment can be written; the FK `section_instructors.faculty_id → faculty.id` is never exercised by the app. On any non-test database V0005/V0006 may not be applied before T-30 merges (S5), so enrollment on MySQL is test-only by construction |
| **faculty** (`faculty`) | none | **No → T-30, then 6d** | No `FacultyRepositoryInterface` or adapter exists. `faculty=mysql` must keep failing closed. Consequence: plain `APP_REPOSITORY=mysql` (which sets faculty to mysql too) stays refused; only per-module overrides, or `APP_REPOSITORY=mysql` + `APP_REPOSITORY_FACULTY=mock`, are usable |
| **T-22 enrollments / grades** | none | **No** | Not authorized; no tables (OQ-11) |

**Combination rules after 7.1** (existing ones in `config/app.php` stay unchanged):

| Rule | Where | Status |
|---|---|---|
| Unknown value → `Invalid repository configuration.` | `config/app.php` | exists |
| students / enrollment / faculty on mysql ⇒ auth on mysql | `config/app.php` | exists |
| faculty on mysql ⇒ refused (no adapter) | `bootstrap.php` (new) | 7.1 |
| any module on mysql ⇒ test-only target (W-G, §2.1) | `bootstrap.php` (new) | 7.1, removed only at G4 |
| **T-30 rule:** enrollment on mysql **and** a real faculty lookup bound ⇒ faculty on mysql | `bootstrap.php` (new) | 7.1 writes it so it activates by itself the moment T-30 replaces `UnavailableFacultyIdentity` (no coordination needed with T-30 phase 3) |

**Allowed combinations in 7.1** (faculty always mock): (C1) auth; (C2) auth+students; (C3) auth+enrollment; (C4) auth+students+enrollment. C4 also via `APP_REPOSITORY=mysql` + `APP_REPOSITORY_FACULTY=mock`.

---

## 2. Proposed changes, by file

### 2.1 `app/bootstrap.php` (Claude, single owner; the only `app/` file changed)

Replace the blanket throw at lines 48–51 with the following, and change only the three construction sites that pick a backend. Everything else (session, limiters, security events, guard, controllers, faculty identity, routes) is untouched.

```php
// T-60 7.1: one shared PDO, opened only if a module is on MySQL. Never falls back to mock.
$repositories = $container['config']['repositories'];
$pdo = null;
if (in_array('mysql', $repositories, true)) {
    $db = $container['database_config'];
    // shortcut: test-only gate (owner: no live activation); replaced by the G4 cutover runbook.
    if ($repositories['faculty'] === 'mysql' || $container['config']['environment'] !== 'testing'
        || $db['database'] !== 'ais_test' || $db['username'] !== 'ais_test_app'
        || !in_array($db['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('MySQL repositories are not available yet.');
    }
    $pdo = Database::connect($db);
}
```

Then the three selection sites:

- `$container['users'] = $repositories['auth'] === 'mysql' ? new MySQLUserRepository($pdo) : new MockUserRepository(...)` (existing mock line kept verbatim as the else branch).
- `programs` and `students`: both on `$repositories['students']` → `MySQLProgramCatalog($pdo)` / `MySQLStudentRepository($pdo)`, else the existing JSON lines.
- `enrollment_catalog`: `$repositories['enrollment']` → `MySQLCatalogRepository($pdo)`, else the existing line.
- Directly after the `faculty_identity` binding (line 102):

```php
// T-30 rule: a real lookup makes faculty ids FK-checked, so enrollment on MySQL needs faculty on MySQL.
if ($repositories['enrollment'] === 'mysql' && $repositories['faculty'] !== 'mysql'
    && !$container['faculty_identity'] instanceof UnavailableFacultyIdentity) {
    throw new RuntimeException('Invalid repository configuration.');
}
```

Design points:

| Topic | Decision |
|---|---|
| **Placement** | The gate and `Database::connect` run **before** `RuntimeDirectory::prepare`, so a refused or failed MySQL start writes nothing to the runtime (keeps the property the current ConfigTest asserts) |
| **One PDO per request** | `$pdo` is created once and passed to every MySQL adapter (ARCH §6.3: required for the nested-transaction refusal to work, not just for speed). No `PDO::ATTR_PERSISTENT` (transaction and session state would leak across requests) |
| **Lazy?** | "Lazy" here means: opened only when ≥1 module is on mysql. It is **not** deferred to the first query, because the adapters take a `PDO` in their constructor and must not change (S10). Consequence on outage: see §4 R5 and OQ-A |
| **No fallback** | Each site is a strict ternary on the config value; there is no `try/catch` around construction or connect. A MySQL failure is a 500, never a mock read |
| **Account** | `DB_USERNAME`/`DB_PASSWORD` via `config/database.php` only. `DB_MIGRATION_*` is never read by bootstrap (source-scan test, §5). In 7.1 the gate pins the account to `ais_test_app`; at G4 it becomes `ais_app` |
| **Sanitization** | Unchanged path: `Database::connect` → `'Database connection failed.'` (no chained exception); adapters → `'<Module> repository unavailable.'`; bootstrap runs inside `ErrorHandler::run` (`public/index.php:11-14`), which logs the class only and returns the generic 500 |
| **Startup schema check** | **None per request.** `schema_migrations` is readable by the app account (`app_grants.sql:21`), but a per-request query adds cost and proves little: a missing lock row already fails closed (`'Auth repository lock missing.'`), a missing table is a sanitized 500. Schema readiness is a deployment gate: `migrate.php status` + `verify.php` before enablement (S1). OQ-B |
| **`verify.php`** | Never run per request (S1). Run after each test-DB setup in G2 runs and before any cutover |
| **File-based, unchanged** | `Session` (`$runtime/sessions`), both `LoginRateLimiter` stores, `SecurityEventLog` (D-09, ARCH §9). They keep working with every combination |

### 2.2 Services and transactions on the shared PDO (no service change)

- Every adapter write opens its own transaction and throws `Nested store operations are not supported.` if one is already open; reads join an open transaction. Inspected flows:
  - `StudentService::create`: `users->findById` (auth read, own short snapshot, committed) **before** `students->create` (students lock). Not nested.
  - `StudentService::update`: `$check` calls `programs->find` **inside** the students lock on the same PDO. `MySQLProgramCatalog` opens no transaction, so this is an allowed read-in-transaction.
  - `UserService::audited`: the failed write's transaction is already rolled back by `MySQLUserRepository::transaction` before the `HttpException` reaches `audited`, so the follow-up `denied` audit transaction is not nested. If a rollback itself failed, the nested refusal is swallowed by `audited`'s `catch (Throwable)` and the original error still reaches the caller (existing, intended).
  - `AuthService::currentUser`: one auth read per guarded request (unchanged semantics; `auth_version` compared live).
  - `RepositoryStudentDirectory`, `AcademicRecordService`: reads only, possibly across students and auth.
  - `SectionService::assign`: identity lookup (Unavailable today; after T-30 reads faculty + users) **before** the enrollment lock (A4 window, unchanged).
- **No current flow takes two store lock rows**, so no adapter-only lock cycle exists (7.2 T3, argued not proven). Any future cross-store write must follow auth → students → enrollment → faculty on the one PDO (ARCH §6.3); on today's code it would instead fail loudly with the nested refusal.

### 2.3 Other files

| File | Change | Owner |
|---|---|---|
| `tests/Core/ConfigTest.php` | Replace the test "Any module on MySQL keeps the application failing closed until T-60 wiring exists" (it asserts the blanket throw and **will fail** after 7.1) with the offline wiring cases W1–W6 (§5) | Claude |
| `.env.example` | Line 8: replace "Any module on mysql currently fails closed at startup" with the test-only rule and "faculty stays mock until T-30" | Claude |
| `README.md` (Database section) | Wiring rules, allowed combinations, test-only gate, rollback | Claude (after tests pass) |
| `tests/Database/WiringMySQLTest.php` | create (V11 online). Plan §7 names this path; `BLOCKERS.md` V11 names `tests/Integration/MySQLWiringMySQLTest.php` — use the plan §7 path and fix the BLOCKERS row | Codex |
| `tests/Database/FailClosedMySQLTest.php` | create (V9; listed in plan §7 row 7.2 but **never created**, because V9's HTTP half needs wiring) | Codex |
| `tests/Auth/HttpSupport.php` | opt-in backend switch for re-running the existing HTTP suites on MySQL (OQ-C) | Codex |
| `tests/Core/apache_check.php` | opt-in MySQL probe (OQ-D) | Claude |
| `app/Core/Database.php` | only if OQ-E is approved (session `innodb_lock_wait_timeout`) | Claude |
| `docs/database/BLOCKERS.md`, `SECURITY.md` §5 | V11 path; outage wording (R5) | Claude |
| `ai-coordination/T60_IMPLEMENTATION_REPORT.md` | append | Codex |
| `ai-coordination/T60_PHASE71_REVIEW_REPORT.md` | create | Reviewer |

**Not touched:** every interface, model, service, controller, mock, MySQL adapter, `JsonStore`, `Session`, `LoginRateLimiter`, `SecurityEventLog`, `config/app.php`, `config/database.php`, routes, migrations, grants, seeders.

---

## 3. Implementation order and responsibilities

| Step | Owner | Worktree / branch | Files | Depends on |
|---|---|---|---|---|
| 0 | Owner | — | **G1** approval of this plan + OQ answers | — |
| 1 | Claude | lead tree, `task/T-60-database-architecture` | `app/bootstrap.php`, `tests/Core/ConfigTest.php`, `.env.example` | G1 |
| 1' | Codex | `..\AIS_CODEX_T60`, `task/T-60-impl` | Phase 8 residual **N1** fix (and N2–N4 if assigned) in `database/tools/*`, `tests/Database/Import*`/`Verify*` | owner assignment; **parallel with step 1 allowed** (disjoint files) |
| 2 | Claude | — | offline suite + `php -l`; commit; fast-forward `task/T-60-impl` | 1 (and 1' merged first if it ran) |
| 3 | Codex | `..\AIS_CODEX_T60` | `WiringMySQLTest.php`, `FailClosedMySQLTest.php`, `HttpSupport.php` (if OQ-C), report append | 2 |
| 4 | Claude | lead tree | merge Codex's work unedited (scope-checked), offline run | 3 |
| 5 | Owner | — | **G2** per module (auth, then students, then enrollment) | 4 |
| 6 | Claude | lead tree, one agent on `ais_test` at a time | online runs of §5 for the approved modules; Apache check; record results | each G2 |
| 7 | security-reviewer | read-only worktree | `T60_PHASE71_REVIEW_REPORT.md` | 6 |
| 8 | file owners | as above | fixes; QA re-run | 7 |
| 9 | Owner | — | **G3** merge to main (with Phase 9/10) | 8, N1 fixed |

- **Sequential:** 1 → 2 → 3 → 4 → 6 → 7 → 8. `app/bootstrap.php` is also edited by T-30 phase 3: never in parallel; whoever is second rebases (plan §8). If T-30 merges first, its `RepositoryFacultyIdentity` swap makes the T-30 rule live and C3/C4 then require faculty on mysql (6d).
- **Parallel:** only step 1' with step 1, and Claude's doc edits with Codex's step 3.
- **Never parallel:** two agents running MySQL tests against `ais_test` (tests reset tables).
- Codex can't reach MariaDB: it writes the online tests and reports them NOT RUN; Claude runs them.

---

## 4. Integration risks

| # | Risk | Assessment | Mitigation |
|---|---|---|---|
| R1 | **Lock-order deadlock across stores in one request** | No current flow takes two lock rows; adapters refuse nesting on the shared PDO, so an accidental cross-store write fails immediately instead of deadlocking | Shared PDO (mandatory); WiringMySQLTest asserts one PDO instance is shared by every MySQL adapter in the container; order rule documented for the future |
| R2 | **Two repositories in one flow** (`StudentService`, `RepositoryStudentDirectory`, `SectionService`) | Reads before writes, never nested (§2.2) | Mixed-backend combinations C2/C3 in §5 exercise these paths across backends |
| R3 | **`UserService::audited`** | Sequential transactions; denied audit after rollback | HTTP re-run includes the denied-audit tests; FailClosed asserts a denied audit row with gap-free id after a 409 |
| R4 | **Mixed backends** (auth mysql, students mock) | JSON student rows reference MySQL user ids; correct only if ids match (import preserves ids, E2) | Test harness seeds both from the same fixture ids; cutover order auth → students → enrollment |
| R5 | **DB outage** | Connect is in bootstrap, so **every** request returns the generic 500, including `GET /` and anonymous refusals. Security events for anonymous refusals on admin routes are **not** recorded during an outage, which contradicts `SECURITY.md` §5 and the V9 text ("security event still recorded") | Accept and amend the docs (default), or OQ-A (deferred connect). Throttling still works for any request that reaches the limiter (none during an outage) |
| R6 | **Hanging connects** | `Database::connect` sets no `ATTR_TIMEOUT`; a non-loopback unreachable host could block up to `default_socket_timeout`. Loopback-only today (refused = immediate) | Gate pins loopback; revisit at G4 |
| R7 | **Lock-wait 50 s vs `max_execution_time`** | On Windows `max_execution_time` is wall-clock; under Apache (30 s) a 50 s InnoDB wait could end in a fatal timeout (non-JSON 500) instead of the sanitized 1205 path | OQ-E: add `innodb_lock_wait_timeout=10` to `SESSION_INIT` |
| R8 | **Session / `auth_version`** | Sessions stay files and carry `user_id` + `auth_version`. Pointing an existing runtime at a different user store lets a session resolve to **another person's** id if versions match (both default 1) | Every MySQL-enabled run uses a fresh `APP_RUNTIME_PATH`; at cutover: ids preserved by import, plus the owner-approved forced `auth_version` bump or session purge (already a listed destructive op) |
| R9 | **Per-request connection cost** | One TCP connect + `SET SESSION` per request (no pooling under mod_php or `php -S`) | Acceptable at current load; measure in the HTTP re-run (timings recorded, not asserted) |
| R10 | **Apache vs built-in server** | `apache_check.php` forces `APP_REPOSITORY=mock` and never loads `pdo_mysql` under mod_php. Env propagation and `pdo_mysql` in the Apache PHP (`php8apache2_4.dll`, same `PHPINIDir`) are unverified | OQ-D opt-in Apache MySQL probe (login + one read + one write on `ais_test`) |
| R11 | **Grants** | Every adapter statement is proven only as `ais_test_app` on `ais_test`. `ais_app` on `ais` doesn't exist | Gate pins `ais_test_app`; privilege test (V6) re-run against the real account is a G4 precondition |
| R12 | **Timestamps / timezone** | Adapters require exact `Y-m-d\TH:i:s\Z` round trips; `SESSION_INIT` forces UTC; behaviour on a non-strict, non-UTC server is untested | Blocker list §7; G4 precondition |
| R13 | **Collation differences (recorded)** | `LOWER()` vs `mb_strtolower` for rare mappings; `programs` order (caller sorts); roles returned sorted | Accepted (BLOCKERS Phase 6 findings); HTTP re-run on MySQL confirms no visible change |
| R14 | **Dev seeders** | `dev_users/students/sections.php` check only the global `APP_REPOSITORY`. With `APP_REPOSITORY=mock` + `APP_REPOSITORY_AUTH=mysql` they write JSON that the app no longer reads (no harm, but misleading) | Low; note in README; optional later fix (seeders are out of T-60 scope) |
| R15 | **Accidental live activation** | After 7.1, a `.env` change alone could otherwise switch the dev app to MySQL | Gate W-G pins `APP_ENV=testing`, `ais_test`, `ais_test_app`, loopback. The developer `.env` stays `mock`; the gate limits the blast radius to `ais_test` |

---

## 5. Required tests before enabling any MySQL-backed workflow

None of these sets `APP_REPOSITORY*=mysql` for the running app. Every MySQL run uses `ais_test` as `ais_test_app` (tester only for reset, fixtures and assertions), a fresh temp `APP_RUNTIME_PATH`, process env only (never the shared `.env`), and one agent at a time.

| # | Test | Where | Offline/online | Pass criterion |
|---|---|---|---|---|
| W1 | MySQL module with `APP_ENV=development` → `MySQL repositories are not available yet.`, no connect, runtime dir empty | `ConfigTest` | offline | exact message |
| W2 | `APP_REPOSITORY=mysql` (faculty mysql) → refused; `+ APP_REPOSITORY_FACULTY=mock` passes the gate | `ConfigTest` | offline | |
| W3 | `DB_DATABASE=ais`, `DB_USERNAME=ais_app`/`ais_tester`/`root`, non-loopback host → refused before connect | `ConfigTest` | offline | |
| W4 | Gate satisfied, closed loopback port → `Database connection failed.`, `getPrevious()` null, runtime empty; over HTTP (`CoreHttpServer`) a generic 500 whose body contains no host, port, db or user | `ConfigTest` | offline | **DB outage → generic 500** |
| W5 | Default config → every binding is the mock class, no PDO created (**mock regression**); source scan: bootstrap never mentions `DB_MIGRATION_`, `ATTR_PERSISTENT`, or a `catch` around adapter construction | `ConfigTest` | offline | |
| W6 | Config combination rejection (existing T-60 config test) still green | `ConfigTest` | offline | unchanged |
| V11 | **`WiringMySQLTest`**, per combination C1–C4, real `public/router.php` via `php -S`: login; guarded read; one audited write per MySQL module lands in `ais_test` (checked as tester); **the module's JSON file is never created** (no fallback); mock modules still write their JSON; `sessions/`, `login-attempts.json`, `security-events.json` still written; one PDO instance shared by all MySQL adapters; deactivation bumps `auth_version` and the other session gets 401; assign → 422 with nothing written (Unavailable lookup) | `tests/Database/WiringMySQLTest.php` | online | all green, 3 rounds |
| V9 | **`FailClosedMySQLTest`**: tester-injected model-invalid row (bad hash algo, non-round-trip timestamp, bad meeting) → generic 500, no SQL in body; wrong app password and stopped/closed port → generic 500; outage behaviour for anonymous refusals asserted as documented (R5 / OQ-A) | `tests/Database/FailClosedMySQLTest.php` | online | |
| H | **Full existing HTTP suites** (`tests/**/*HttpTest.php`) re-run per combination C1–C4 via the opt-in harness switch (OQ-C): fixtures seeded as today, imported into a freshly reset+migrated `ais_test` with `JsonImporter` (tester), server started with the combination's env | existing files, unchanged | online | every failure is on a **pre-approved, named mock-only list** (tests that read or corrupt raw JSON stores, or seed after server start), each with its MySQL-equivalent coverage named; any other failure blocks G2 |
| 7.2 | Phase 7.2 concurrency + transaction integrity re-run | existing | online | 2 full runs green |
| P8 | Phase 5–8 online suite (migrations, constraints, privileges, contracts, import, verify) | existing | online | green |
| A | `apache_check.php` (mock, unchanged) on both roots; plus the opt-in MySQL probe (OQ-D) | Claude | needs XAMPP | PASS |
| R | Offline suite with **no** DB env, default config | `php tests/run.php` | offline | baseline count + W1–W5, 0 failed; `php -l` clean |
| S | `verify.php --target=ais_test` after each combination's run | CLI | online | exit 0 |

Mutation checks (Codex, scratch copy): WM1 bind `MockUserRepository` when auth=mysql → V11 fails (JSON created); WM2 a second `Database::connect` per adapter → V11 shared-PDO assertion fails; WM3 drop the faculty clause from the gate → W2 fails; WM4 catch the connect failure and fall back → W4/V9 fail; WM5 connect before the gate → W3 fails (connection attempted).

---

## 6. Rollback

- **Config:** unset every `APP_REPOSITORY_*` override (or set it to `mock`) in the process env of the test command. The next request uses the JSON stores; no restart is needed for `php -S`/mod_php. The default (`mock`) never opens a connection (W5).
- **Code:** revert the 7.1 commit; bootstrap returns to the blanket throw. No other `app/` file depends on it.
- **Data:** 7.1 migrates no data. The JSON runtime is never read by the importer outside temp test dirs and never written by MySQL-mode code.
- **`ais_test` writes made by tests:** synthetic, disposable, and removed by the next guarded reset (OQ-5 standing approval, `ais_test` only). Nothing is copied back to JSON. No backup or restore is involved.
- Production rollback after go-live (writes lost, C&M §5.4) belongs to the G4 runbook, not to 7.1.

---

## 7. Dependencies and blockers

| Item | Effect on 7.1 |
|---|---|
| **T-30** (plan approved, implementation not authorized) | Faculty stays mock and refused on mysql. When T-30 swaps in `RepositoryFacultyIdentity`, the T-30 rule (§2.1) activates: C3/C4 then need faculty on mysql, i.e. Phase 6d. Bootstrap edits are serialized with T-30 phase 3 |
| **T-22** (not authorized) | No enrollment-request or grade tables; nothing to wire |
| **Phase 8 residuals N1–N4** | Recorded in `T60_PHASE8_REVIEW_REPORT.md` (review #2, APPROVE on `5114fac`; committed in `04c6696`, after this assessment was drafted). **N1 must be fixed before any merge to main (G3)**; it does not block G1/G2 unless it affects the importer used by harness H, in which case it blocks H |
| **Phase 9 independent review** | Not done; required before G3 |
| **MariaDB 10.4.32** | Development only (S7). Re-run 7.2 + V11 + H on the production release before G4 |
| **Strict/UTC on a non-strict server** | Untested (BLOCKERS Phase 5 limit 1). G4 precondition |
| **Production `ais` database and `ais_app`/`ais_migrator`/`ais_backup` accounts** | Don't exist. 7.1 cannot target them (gate W-G) |
| **Cutover** | Separate gate G4 with its own runbook (C&M §5.5); also blocked by `APP_ENV` production failing closed (TLS, persistent sessions) |
| **V9/V11 not done in 7.2** | Plan §6's Phase 7 exit criteria are not yet met; they close with this phase |

---

## 8. Approval gates

| Gate | What the owner approves | Evidence presented |
|---|---|---|
| **G1** | The 7.1 implementation: §2 changes, file list §2.3, order §3, OQ answers | this plan |
| **G2** | Enablement of each module **in a test-only configuration** (`ais_test`, `ais_test_app`, temp runtime), one at a time: auth, then students, then enrollment | offline green; W1–W6; for the module: V11, V9, H (with its mock-only list), 7.2, `verify.php` results |
| **G3** | Merge of `task/T-60-database-architecture` into `main` | G2 results for all three modules, Phase 9 review APPROVE, N1 fixed, Apache PASS, clean tree. Merging does **not** activate MySQL: default stays mock and the gate stays in place |
| **G4** | Cutover (separate runbook): remove/replace gate W-G, `ais` + accounts + grants, migration, JSON import, session handling (R8), supported MariaDB, go/no-go | runbook; not part of 7.1 |

---

## 9. Open questions for the owner (proposed defaults)

| # | Question | Proposed default |
|---|---|---|
| OQ-A | Connect in bootstrap (every request 500s during an outage; anonymous refusals not logged), or defer the connect to the first query with a small `PDO` subclass in `app/Core` (keeps `SECURITY.md` §5 exactly; more code)? | **Connect in bootstrap** and amend `SECURITY.md` §5 / V9. With auth on mysql almost every route needs the DB anyway |
| OQ-B | Per-request schema-version check? | **No.** Deployment gate (`migrate.php status` + `verify.php`) instead |
| OQ-C | Allow an opt-in, env-driven backend switch in the shared HTTP harness (`tests/Auth/HttpSupport.php`, Codex) so the existing HTTP suites re-run on MySQL without editing their assertions, with a named mock-only list? | **Yes** |
| OQ-D | Add an opt-in MySQL probe to `tests/Core/apache_check.php` (committed), or keep a one-off uncommitted probe as in T-21? | **Committed opt-in probe** (Claude), skipped unless the MySQL test env is set |
| OQ-E | Add `innodb_lock_wait_timeout=10` to `Database::SESSION_INIT` (R7)? | **Yes** (Claude, one-line change; 7.2 workers already set their own value) |
| OQ-F | Pin the 7.1 gate to `APP_ENV=testing` + `ais_test` + `ais_test_app` + loopback until G4? | **Yes** |
| OQ-G | Implement the T-30 rule in 7.1 as a self-activating check (§2.1), rather than leaving it to T-30 phase 3? | **Yes** |
| OQ-H | Who fixes N1 (N2–N4 optional)? | Recorded already (`04c6696`). **Codex** fixes N1 in step 1' |
| OQ-I | Fix the seeders to check per-module selection (R14)? | **Not now** (out of T-60 scope); README note only |
