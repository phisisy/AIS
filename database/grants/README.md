# database/grants

**UNVERIFIED: never executed.** Both SQL files are reviewed text only. Scope is the isolated `ais_test` environment; production accounts and cutover are out of scope (owner decision DB-14, 2026-11-26).

## Files

| File | What it is |
|---|---|
| `ais_test_setup.sql.template` | Owner-run, as root: sets the root password, provides lock lines (shipped commented, fail-safe) for the passwordless root accounts that the owner uncomments selectively, creates `ais_tester` and `ais_test_app` (`@'127.0.0.1'` only), grants `ALL` on `ais_test.*` to the tester, then runs read-only post-checks. Contains `{{ROOT_PASSWORD}}`, `{{TESTER_PASSWORD}}`, `{{APP_PASSWORD}}`, `{{HOSTNAME}}`. Not idempotent on purpose. Drops nothing. |
| `app_grants.sql` | Template of 19 per-table GRANT statements (46 privilege rows) for the app account. Placeholders `{{SCHEMA}}=ais_test`, `{{APP_USER}}=ais_test_app`. Never creates users or databases. |

## Order of operations

1. Start the server (`docs/database/INSTALL.md` section 2.1).
2. Fill a **copy** of `ais_test_setup.sql.template` outside the repo. Run its section 0 pre-check (same connection method as step 3) to get the host name and `CURRENT_USER()`. The lock lines ship commented (fail-safe): uncomment only those for listed root hosts that are not `CURRENT_USER()`.
3. Run it as root: `mariadb -h 127.0.0.1 -P 3307 -u root -p < filled-copy.sql`. Delete the filled copy.
4. Create the schema: method A (migrations V0001-V0010; the optional `CREATE DATABASE` block in the setup file must be uncommented first) or method B (`database/database.sql`, which creates `ais_test`; leave that block commented).
5. Apply `app_grants.sql` as root, with the two placeholders substituted. Use the same working root login as in step 3.
6. Re-run the setup file's section 5 checks (46 rows for the app account, no GRANT OPTION, no global privileges) and the INSTALL.md section 2.4 verification.

## Rules

- **Filled copies containing passwords never enter the repo** (no commit, no paste into docs, tickets, chat or test output). Passwords are random per machine and never copied from another host.
- Locking is reversible; **dropping** the extra root, anonymous accounts or the `test` database needs separate owner approval.
- Nothing here is run by an agent.
