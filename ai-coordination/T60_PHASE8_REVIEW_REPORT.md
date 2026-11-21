# T-60 Phase 8 — independent review (verify.php / import_json.php)

- **Reviewer:** Claude Code `security-reviewer` subagent; read-only, static. It did not implement Phase 8 (Codex did; Claude coordinated). Recorded by Claude without changing findings or verdict.
- **Reviewed:** `task/T-60-database-architecture` at `0d8becf` (Phase 8 commit `66f5877`).
- **Evidence supplied:** 273/273 online on `ais_test`; 217/217 offline; 151/151 `php -l`.
- **Date:** 2026-11-21.

## Review #1 VERDICT: REQUEST CHANGES

One blocking finding (M1). No Critical or High findings.

| ID | Sev | Finding | Fix (owner) | Blocks |
|---|---|---|---|---|
| M1 | Medium | **A missing source file makes the importer write into the source folder and report an empty import as success.** `JsonStore::read()` creates a missing directory (`mkdir`) and a missing `.lock` file (`fopen('c+b')`), and returns `[]` for a missing `.json`. A wrong or incomplete `--runtime` gets written to, then the import commits zero rows with exit 0 (`JsonImporter.php:169-191`; `JsonStore.php:54-59, 78-80`) | Refuse with exit 2 ("Runtime refused.") **before** constructing any `JsonStore`, unless the module's files exist with `is_file`: auth `auth/users.json`; students `students/students.json` and `students/programs.json`; enrollment `enrollment/enrollment.json`, plus their `.lock` files. Add an offline test showing the directory listing is unchanged. Reword the report's "read only" claim (Codex) | **Yes** |
| M2 | Medium | **Nothing stops an import from the live runtime.** The owner's authorization forbids importing it, but the tool accepts any absolute directory, including `C:/ais-runtime` or the `.env` `APP_RUNTIME_PATH`. Real names, emails and hashes would be copied into `ais_test` | Refuse with exit 2 if the canonical runtime equals `realpath('C:/ais-runtime')` or the **`.env`-file** `APP_RUNTIME_PATH` (not the process env, because the tests set it), until a cutover is authorized. Add an offline test (Codex) | Not blocking (enforces the owner rule) |
| L1 | Low | The source is read several times, so the data inserted isn't the data validated (`JsonImporter.php:81-82, 181, 346-383`) | Read once and pass the same raw data to validation and to the inserts. Hash the module's files before validation and again before COMMIT; abort with exit 1 on any difference (Codex) | No |
| L2 | Low | UNC and network paths are accepted (`\\host\share`, `//host/share`), which could send NTLM credentials to a remote host (`JsonImporter.php:54`) | Refuse paths starting with two separators; add an offline test (Codex) | No |
| L3 | Low | **Test gaps:** (a) no rollback test after all inserts succeed but the count, interface or verifier check fails (moving `commit()` earlier would go undetected); (b) refusals untested: students before auth, schema not fully applied, a dirty target, the `ais_test_app` migration account; (c) `verify.php` refusals for a non-loopback host, root or unexpected `DB_USERNAME`, or a bad port; (d) V2 is missing `student_model`, a wrong meeting `position`, and `faculty_model`; (e) E8 compares only `users` columns | Add the tests. For (a), use a guarded `BEFORE INSERT` trigger on `audit_logs` through the owner connection that changes `action`; assert exit 1, all auth tables empty and `last_seq` 0 (Codex) | No |
| L4 | Low | The equivalence check is slightly narrower than C&M §5.3: `sectionIdsForFaculty` only covers current-assignment faculty ids, and `password_hash` fidelity is never compared (`JsonImporter.php:381, 386-392`) | Take faculty ids from both assignments and history; compare `id, password_hash` with the raw source inside the transaction, never printed (Codex) | No |
| I-a | Info | V2 uses guarded test DDL (`DROP INDEX`) on `ais_test` only; the next reset repairs it | — | — |
| I-b | Info | `import()` itself doesn't check the host; only the CLI does | Fine while the CLI is the only caller | — |
| I-c | Info | `faculty_model` is a structural check only, until T-30's `Faculty::fromArray` exists | Track as a T-30 follow-up | — |
| I-d | Info | Module order is only partly enforced (auth first). Enrollment before students is harmless | — | — |
| I-e | Info | `verify.php` hardcodes `ais_test`; a future restore drill on `ais_restore_check` will need an owner-approved change | Later | — |
| I-f | Info | With `display_errors` on, a PHP notice could print a path; unlikely, because sources are validated first | — | — |

## Verified correct (static)

1. **Target safety:** both CLIs check the arguments, env, loopback host, port and account, then connect with the literal `ais_test` and check the live `DATABASE()`, `@@port` and `CURRENT_USER()` before any read or write. Root is refused; both are CLI-only.
2. **No destructive statements:** the tools contain no DROP, TRUNCATE, DELETE, ALTER, CREATE or GRANT. The verifier runs SELECT only, in a `READ ONLY, WITH CONSISTENT SNAPSHOT` transaction that is rolled back, and works with only `ais_test_app`'s grants.
3. **Transactions:** one per module. Preconditions are checked under the lock. Inserts, counter, counts, equivalence and verifier all run before COMMIT, and any failure rolls back. `--dry-run` rolls back. There's no implicit DDL commit.
4. **Import correctness:** ids and seqs preserved; roles sorted and compared as sets; audit JSON fidelity proven; strict timestamp conversion; meeting positions; latest `assigned_seq`. **I1:** the auth counter is set to the max audit id, and the enrollment counter to the max history seq. The students counter stays 0, consistent with the adapters. Faculty is deferred, and an orphan `faculty_id` aborts. The mocks' full-snapshot validation runs, and raw checks cover what the mocks don't.
5. **Equivalence:** strict `===`, with roles as sets (apart from L4).
6. **Verifier:** every C&M §4 check is implemented; no false-pass or false-fail paths found; V2 covers every named check.
7. **Disclosure:** output is check names, PASS/FAIL and numeric ids only; STDERR messages are fixed strings.
8. **SQL injection:** every value bound; table names come only from constants.
9. **Secrets:** none committed; tests use env only.
10. **Tests:** E1–E12 and V1–V4 largely assert what they claim (gaps in L3). Single-use behaviour is documented and tested.

## Resolution plan (Claude)

Codex fixes M1, M2 and L1–L4 in one round, then an independent re-review of the corrected commit.
