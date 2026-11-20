# AGY review reports

This file is written by AGY, or by the owner pasting AGY's output. Claude doesn't write findings or verdicts here.

Add the newest report at the top, using this template:

```
## <Task ID> — <date>
- Reviewed branch:
- Reviewed commit(s):
- Tests executed (commands and results):
- Findings by severity (Critical / High / Medium / Low / Info), each with evidence and file:line:
- Recommended fixes:
- Verdict: APPROVE | CHANGES_REQUESTED | BLOCKED
```

## T-20 — 2026-11-20
- Task ID: T-20 (Terms & sections catalog, Team 4)
- Reviewed branch: `task/T-20-terms-sections`
- Reviewed commit(s): `81097d5a82963269e45965e4bc9352010eea2e30` (HEAD; docs commit), covering `d79d72fb1471e23f1acaacec98a31ac22690ea6f`, `41e8a56c7fcd271fa22ec0de2765a6d8cf061638`, implementation commit `0624eb78366f3caa42ef1562946c42c5cffc7b13` (policy alignment: delivery_mode, TBA/online_async), and `b04fec46cd00cd64add0322baf5285e99f279dca` (base implementation).
- Reviewed diff range: `592d912..81097d5a82963269e45965e4bc9352010eea2e30` (diff base `main` `592d9126d3193f5a5a757f0876b24f0335c1fed9`).
- Review worktree: `..\AIS_REVIEW_T20` (detached HEAD at `81097d5a82963269e45965e4bc9352010eea2e30`) with isolated runtime directory.
- Tests executed and results:
  - `php tests/run.php`: PASS (163 passed, 0 failed, 163 total), covering all 7 catalog routes (HTTP role matrix, real session authentication, CSRF, delivery modes, corrupt store 500 handling), 5 named concurrency cases (C1–C5) + term PATCH concurrency, and regression suite for T-00 through T-11a.
  - `php -l` on all PHP files (`Get-ChildItem -Recurse -Filter *.php`): PASS (104 files checked, 0 syntax errors).
  - `php tests/Core/apache_check.php`: PASS (both public document root and repository document root verified under Apache XAMPP).
  - `php database/seeders/dev_sections.php` (idempotence probe on fresh temporary runtime): PASS (first run created 2 terms and 4 sections; second run detected existing records and exited 0 with byte-identical store SHA-256 `7E91F3D0BC98B012E5B7BF40375949E34F3DCD8893E260354631FA95BA5D5B1A`).
  - `git diff --check 592d912..81097d5`: PASS (0 whitespace errors).
- Security findings:
  - RBAC & Authorization: All 7 routes guarded in `routes/enrollment.php`; writes restricted to `registrar` and `admin`. Service layer repeats role check (`requireRole($actor, self::STAFF)` in `TermService` and `SectionService`).
  - CSRF: Enforced on all state-changing endpoints (`POST`, `PATCH`) after authentication and role verification.
  - Mass assignment: Strict allowlists enforced across Controller, Service, and Repository layers (`TermController`, `SectionController`, `TermService`, `SectionService`, `MockCatalogRepository`). Stored system fields (`id`, `created_at`, `updated_at`, `approved_count`) and computed fields (`remaining`) are immutable and rejected if submitted. Natural keys (`code`, `term_id`, `course_code`, `section_code`) are immutable on update.
  - Fail-closed storage: Any store corruption or invariant violation throws `RuntimeException('Invalid enrollment store.')`, leaving the store untouched on disk and returning a generic 500 error envelope without leaking paths or internals.
- Correctness findings:
  - Term uniqueness: Code uniqueness checked under store write lock; normalization trims and uppercases.
  - Current-term concurrency: Atomic single `is_current` term maintained via `clearCurrent` inside store write lock.
  - Section uniqueness: Unique `(term_id, course_code, section_code)` verified inside store write lock.
  - Section capacity: Capacity must be 1–1000 and cannot drop below `approved_count` on update. `remaining` computed dynamically as `capacity - approved_count`.
  - Meeting validation: Strict format checks (day, 24-hour HH:MM `start < end`, room length), max 14 meetings, and intra-day meeting overlap detection.
  - TBA & Online Asynchronous sections: `delivery_mode` must be explicitly set to `tba` or `online_async` to permit 0 meetings. Sections defaulting to or set to `scheduled` strictly require at least 1 meeting. An empty meeting list without explicit `tba`/`online_async` designation is rejected with 422. Mode/meetings pairing is checked on create and re-checked on PATCH merged rows inside store lock.
- Integration findings:
  - Matches `ai-coordination/API_CONTRACTS.md` Team 4 Section contracts and shapes.
  - B-04 alignment: Team 4 owns the section catalog and section definitions; no premature instructor endpoints in T-20; prepares clean integration path for T-21 (`SectionDirectory`, faculty lookup) and T-22 (enrollment requests).
  - T-00 through T-11a regression suite passes completely.
- Remaining limitations:
  - L-3: Client-supplied unknown field names in JSON requests are echoed back as keys in 422 error maps (e.g. `['unknown_key' => 'Unknown field.']`). Pre-existing Core behavior in `App\Core\Request` (predates T-20). Frontend must treat error keys strictly as text labels. Not exploitable in JSON. Non-blocking for T-20.
  - I-1: `app/Enrollment/Models/Term.php` line 36 & `app/Enrollment/Models/Section.php` line 43 reject Unicode control (`\p{Cc}`) and format (`\p{Cf}`) characters, permitting normal international characters.
  - I-2: `MockCatalogRepository` reads the entire enrollment catalog file on each request; appropriate for file-based mock repository ahead of MySQL (T-40).
  - I-3: `MockCatalogRepository::snapshot()` enforces that only `terms` and `sections` top-level keys exist in `enrollment.json`. T-21 and T-22 must expand this key allowlist when adding assignment and enrollment data.
  - I-4: Capacity reduction check in `SectionService` line 111 reports current `approved_count` in error message when capacity is reduced below it (staff only; value already exposed as `remaining`).
- Merge blockers:
  - None.
- Independent evaluation of Codex report:
  - Codex previously reported APPROVE on `60ac236` with disclosed lack of complete independence (having written phase 1 backend `b04fec4`). AGY independently verified all code, invariants, and tests from clean state and confirms Codex's technical conclusions are correct.
- Final verdict: APPROVE

## History (before this file existed)

- **T-11a:** APPROVE on `0b91668` and `6ae3ac3`. The report was pasted into the Claude session by the owner on 2026-11-17, and its summary is in `T11A_IMPLEMENTATION_REPORT.md` §13.
- **T-20:** no AGY report yet.
