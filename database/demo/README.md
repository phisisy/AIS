# Demo data generator (DB-31)

**UNVERIFIED.** Written from source only; never run. Run it on a trusted machine and check each step against the live responses. The spec is `docs/database/DEMO_DATA_SPEC.md`.

## Usage

```
php database/demo/generate_demo_data.php --runtime=<absolute new/empty dir> --confirm-ais-test
```

Required environment: supply `DB_MIGRATION_PASSWORD` and `AIS_MYSQL_TEST_APP_PASSWORD` through the process environment for the demo run, NOT in `.env`. Every child reloads `.env`, and an absent process value falls back to it, so a password kept there would reach the server and seeders; the script refuses (exit 2) if `.env` defines `DB_MIGRATION_PASSWORD` or `AIS_MYSQL_TEST_APP_PASSWORD`. It sets (to empty, which wins over `.env`) `DB_MIGRATION_*`, `AIS_MYSQL_TEST_APP_PASSWORD` and, for seeders, `DB_PASSWORD` in the server, seeder and verify processes (imports keep `DB_MIGRATION_*` only; their `DB_PASSWORD` and app password are blanked). Variables: `APP_ENV=testing`, `DB_DATABASE=ais_test`, `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_MIGRATION_USERNAME=ais_tester` with `DB_MIGRATION_PASSWORD` (import), and `AIS_MYSQL_TEST_APP_PASSWORD` (the `ais_test_app` account that runs the API server and verify). `ais_test` must be fresh and migrated, and the app must not be running. Unset `STUDENT_NUMBER_PATTERN` and `FACULTY_EMPLOYEE_NUMBER_PATTERN` (the script strips them from child processes, but not from `.env`).

## Guards (exit 2, generic message, before any process or write)

CLI only; env values above; `--runtime` absolute, no `..`, no URL, not OneDrive-looking, not at or under the repository root (resolved through the parent's real path; this includes `public/`), and new or empty (created 0700 with umask 0077; the password file is created exclusively); `--confirm-ais-test` present; no other arguments. Secrets are never printed.

## Steps

1. Seeders `dev_users`, `dev_students`, `dev_sections` into the runtime (mock). Passwords go only to `<runtime>/demo-passwords.txt` (0600 intent; Windows ignores mode bits). API-created account passwords are appended there.
2. `import_json.php` for `auth`, `students`, `enrollment` (dry run, then commit). Faculty is not imported here: the importer does support a `faculty` module, but the seeders write no faculty store, so faculty profiles are created over the API as the spec describes.
3. `verify.php`, then a `php -S 127.0.0.1:<free port>` server on MySQL with the app account (sessions in `<runtime>/http-sessions`).
4. Spec steps B1 to B13 over HTTP (CSRF and session cookie), stopping at the first unexpected status. The server runs with `APP_GRADE_SCALE=demo` (demo-only scale, not the official university policy). Refusals: clash, full, closed term, inactive login. B13 is a small fictional grading set on CS101-A: invalid code `3.25` refused (422), F1 enters 1.75 and INC, submits, faculty cannot finalize (403), the registrar finalizes, the student sees the grade. Result: 2 `grades` rows, 6 `grade_history` rows.
5. Server stopped, `verify.php` run (expect exit 0), row counts printed against the spec, and the `mariadb-dump` command printed (not run) for `database/demo_data.sql`. The dump contains password HASHES of fictional demo-only accounts (random passwords kept separately in `demo-passwords.txt`, never committed). Demo use only; never load outside `ais_test`.

Exit codes: 0 success, 1 failure or count difference (a finding), 2 guard refusal.

## Notes

- Response shapes (`data.id`, `data.user.id`, list under `data`) are inferred; adjust `demoId`/`demoRows` if a real response differs.
- Row counts are read with the app account; if it lacks SELECT the check reports so and exits 1.
- `tests/Database/DemoGeneratorOfflineTest.php` covers the guard path only.
