# Claude Reviews

## T-00 — Core HTTP skeleton + test runner — **VERIFIED** (2026-11-17)

Branch `task/T-00-core`, based on `main` at `a04d194`. Reviewed the uncommitted working tree as handed over by Codex.

### What I inspected
- `CODEX_REPORT.md` T-00 section and the `TASK_BOARD.md` diff (status change only).
- Every changed file (33 new, 2 modified). All are inside T-00's "Files allowed to change". `app/Core/ErrorHandler.php` falls under `app/Core/*.php` and `tests/Core/fixtures/*` under `tests/Core/*`. No file outside the list was touched.
- Full source of `app/`, `config/`, `public/`, `routes/`, `.htaccess` files, `.env.example`, `.gitignore`, `tests/run.php`, `tests/Support.php`, `tests/Core/apache_check.php`.

### Independent checks (run by Claude, not taken from the report)

| Check | Result |
|---|---|
| `php tests/run.php` | **30 passed, 0 failed**, exit 0 |
| `php -l` on all 28 new PHP files | **0 failures** |
| Bare `php -S 127.0.0.1:8765 public/router.php` (no test-only ini settings) | `/` 200 + JSON + `no-store` + `nosniff`; `/nope` 404; POST `/` 405 + `Allow: GET`; text/plain 415; `{bad` 400; 70 KB body 413; form POST 415; `/.env.example`, `/app/bootstrap.php`, `/config/app.php`, `/public/index.php`, `/../.git/config` all 404 JSON |
| `php tests/Core/apache_check.php` (private XAMPP httpd, loopback, run as the owner's account) | **PASS for both `public/` and repository document roots**, exit 0. No leftover runtime directory |
| Secrets scan of `app/ config/ public/ routes/` | None. `.env.example` has placeholders only |
| Ignored/leftover files | None |

### Acceptance criteria

| # | Criterion | Verdict |
|---|---|---|
| 1 | Root envelope, 404, 405 + Allow | Met (tests + manual probe + Apache) |
| 2 | 415 / 400 / 413 | Met (tests + bare php -S + Apache) |
| 3 | Generic 500, nothing leaked | Met (ErrorHandler logs a sanitized class name only and discards buffered output; tests cover it) |
| 4 | Positive-integer params, literal routes win | Met |
| 5 | Unknown `APP_REPOSITORY` fails closed, `.env` git-ignored | Met |
| 6 | XAMPP Apache + `php -S` | Met. Codex's Apache failure (`DocumentRoot must be a directory`) came from its sandboxed identity. The same script passes when run as the owner. Not a code defect |

### Findings (none block T-00)

| Severity | Finding | Action |
|---|---|---|
| Medium | `JsonStore` replaces the file with `rename()`. The workspace is inside **OneDrive**, whose sync client (or antivirus) can briefly hold the file open on Windows. The write then fails with a 500. Data isn't lost, since the original file is kept. | Carried into T-01: set `APP_RUNTIME_PATH` to a folder outside OneDrive in local `.env`, or add a short bounded retry around `rename()`. |
| Low | `public/router.php` sends every request to `index.php`, so `php -S` cannot serve static frontend files from `public/`. This is deliberate (it keeps the repo private when the docroot is the repo root). | Revisit when Team 1 integrates (B-10). |
| Low | Duplicate-route detection compares the literal path string. `/a/{id}` and `/a/{x}` can both be registered, and the first one silently wins. | Optional hardening. Low risk while route files are reviewed. |
| Info | Non-object JSON (e.g. `[1]`) returns **422**, not 400. This is reasonable, but undocumented. | Documented in `ARCHITECTURE.md` §5. |
| Info | The error handler turns every PHP warning, notice and deprecation into a 500. This is strict on purpose. | Developers should expect deprecations to fail loudly in tests. |
| Info | `APP_ENV` accepts any value. | T-01 must decide whether to refuse unknown or production values before sessions exist. |

### Security notes
PDO: exception mode, native prepares, utf8mb4, multi-statements off, and a credential-free error message. No connection is made at bootstrap. Response headers are validated against CR/LF injection, and the mandatory security headers can't be overridden. Request path rejects control characters and `#`, and is not URL-decoded before routing. Request body is capped at 64 KiB, including chunked bodies. Test cleanup refuses to delete directories it didn't create.

### Outcome
**VERIFIED.** Committed on `task/T-00-core` by Claude per the Git workflow. Merge to `main` is the owner's decision.

## T-01 — Authentication & sessions — **VERIFIED** (2026-11-17)

Implemented by Claude under the owner-approved takeover. The independent check is a read-only `security-reviewer` subagent plus Claude's own test, lint, Apache and live-HTTP runs (see CODEX_REPORT T-01).

| # | Criterion | Verdict | Evidence |
|---|---|---|---|
| 1 | csrf / login / logout / me per contract | Met | AuthHttpTest flow test; live probe |
| 2 | Same 401 for every credential failure; dummy hash | Met | 9 service cases + 7 HTTP cases, identical bodies |
| 3 | Session ID regenerated; HttpOnly/SameSite/strict; 30 min / 8 h timeouts | Met | HTTP test (cookie changes, flags); `session_clock` worker test |
| 4 | Reload per request; inactive/deleted/changed `auth_version` rejected | Met | Service and HTTP revocation tests |
| 5 | 5 per IP+email, 30 per IP per 15 min, 429 + Retry-After | Met | HTTP pair test (ignores X-Forwarded-For); unit IP test |
| 6 | No hash, session ID or auth_version in responses | Met | HTTP test scans bodies |
| 7 | `APP_ENV` validated; OneDrive-safe store writes | Met | Config test; 2 retry tests plus a mutation check |

Security review: no Critical, High or Medium findings.
- **L1 fixed:** the dummy hash could diverge from `PASSWORD_DEFAULT` on PHP 8.4+ (timing enumeration). Bcrypt cost 10 is now pinned in `AuthService::HASH_*`, the seeder uses `hashPassword()`, and a parity test guards it.
- **L2 fixed:** a `tempnam()` fallback to the system temp dir could stage store data outside the store directory. Now refused.
- **L3 open (owner decision):** the default `storage/runtime` is inside OneDrive, so session files and password hashes sync to the cloud. Documented in README/.env.example with a recommended `APP_RUNTIME_PATH=C:/ais-runtime`.
- **Info, for deployment (production still blocked):** per-IP limit by full IPv6 address and behind proxies; `__Host-` cookie prefix once TLS is mandatory.

Caveat: the implementer and the main reviewer are the same model. The subagent review and executable tests are the independent evidence.

### T-01 addendum — L3 resolved (owner chose option B, 2026-11-17)
- Default `APP_RUNTIME_PATH` on Windows is now `C:/ais-runtime`, outside the OneDrive project; still configurable. Other systems use `storage/runtime` (git-ignored).
- New `App\Core\RuntimeDirectory::prepare()`, called by bootstrap on every request and by the seeder. Fails closed for relative, stream or NUL paths, a non-directory, an unwritable directory, or any path resolving into `public/` (including `..` tricks and case variants on Windows). Creates the directory with mode 0700 if missing. On failure the API returns a generic 500 with no path in the body.
- No existing runtime data existed (`storage/runtime` was never created), so nothing needed moving.
- Tests: 3 new (default/override, 11 refusal cases, HTTP 500). Suite 49/49. Apache check PASS. Live probe with no `APP_RUNTIME_PATH` wrote only `C:/ais-runtime/sessions/…`, and nothing inside the project. The probe directory was removed afterward.

## T-02 — Role-based access control — **VERIFIED** (2026-11-17)

| # | Owner requirement | Verdict | Evidence |
|---|---|---|---|
| 1–4 | Student / faculty / registrar / admin permissions | Met | Unit matrix (4 roles × 6 allowlists); HTTP matrix (5 users × 6 routes, exact 403 body) |
| 5 | Multi-role | Met | faculty+registrar user gets the union; no admin hierarchy (mutation-checked) |
| 6 | Protected endpoints, deny by default | Met | Guard on every route; registration throws on empty or unknown allowlists; a test fails if any app route except `/` and `/api/auth/*` is unguarded |
| 7 | Unauthorized rejection | Met | 401 anonymous, 403 role, 403 CSRF, checked in that order (mutation-checked) |
| 8 | Inactive users | Met | Cannot sign in; `requireRole` gives 401 on a direct call; deactivation destroys the session, reactivation doesn't revive it |
| 9 | Role changes vs. sessions | Met | Promotion and demotion apply on the next request in the same session |
| 10 | No privilege escalation | Met | X-Role / query / body roles ignored; route params never identity; unknown stored roles → generic 500; `requireOwnerOrRole` gives 404 |
| — | No sensitive info in errors | Met | Fixed messages only; tests assert no role names in bodies |

Security review (read-only subagent): no Critical or High findings.
- **Medium fixed:** an unwrapped route would be open. Guard output is now a `GuardedHandler`, and a bootstrap-level test enforces it.
- **Low fixed:** the ownership helper was easy to misuse. Added a 404-throwing `requireOwnerOrRole`, staff roles are validated, the parameter is renamed to `$recordOwnerUserId`, and the README says "load the record, never pass the route id".
- **Low documented:** safe methods skip CSRF under SameSite=Lax, so GET/HEAD/OPTIONS handlers must not change state.
- **Info accepted:** 405 `Allow` reveals which routes exist to anonymous callers.

Design note for T-03: the T-01 auth routes stay unguarded by design (they check identity and CSRF themselves). Role promotion takes effect without re-login. T-03 should decide whether role changes bump `auth_version`, which would force re-login.

## T-03 — User administration & audit logging — **VERIFIED** (2026-11-17)

Full evidence is in `ai-coordination/T03_IMPLEMENTATION_REPORT.md`.
- Implementation commit `bf5a268` on `task/T-03-admin`. Suite 82/82, lint 59/59, Apache PASS.
- Read-only security review: no Critical or High findings. The Medium and all Low findings are fixed and tested. One Info item (admin resetting another admin's password) is an owner decision.
- Last-admin protection: two independent protections under one exclusive lock. Multi-process races pass, and mutation checks show the race tests fail when both protections are removed.
- Not merged; awaiting owner review.

## T-04 — Security-event logging — **VERIFIED** (2026-11-17), not merged

| # | Owner requirement | Evidence |
|---|---|---|
| 1 | Log refused admin-route access | All 6 `/api/admin/users*` routes opted in; HTTP test: anonymous, forged session, student, missing CSRF |
| 2 | Timestamp, route id, type, outcome, safe actor | `ts/at/last_ts/last_at`, route pattern constant, enum type, `refused`, user id or null |
| 3 | No passwords, tokens, cookies, session ids, bodies | HTTP test scans the raw file for body password, forged cookie, CSRF tokens, `AIS_SESSION`, `password`, email, field values |
| 4 | Log injection | Only server-built values; route validated by an anchored regex at registration; invalid input dropped (newline, query, case, unknown type) |
| 5 | Bounded storage/retention | 500 anonymous + 1,000 signed-in entries, 30 days, 60-second merging (count) |
| 6 | 401/403 unchanged | Exact response bodies asserted; all 84 earlier tests still pass |
| 7 | Logging failure never bypasses | `record()` catches Throwable; broken store (directory), busy lock and corrupt file all tested; handler never runs on refusal |
| 8 | No duplicates | One record per refusal; handler errors are outside the guard's try; admin audit stays empty for guard refusals |
| 9 | Tests | 7 new tests (unit + HTTP) |
| 10 | Separate from admin audit | Own file and lock (`auth/security-events.json`) |

Results: **91/91** tests, 62/62 lint, Apache PASS. Mutation checks (each reverted):
- logging errors propagate → 3 fail
- handler inside the try → 1 fails
- logging removed → 2 fail
- waiting for the lock → suite deadlocks (killed by a timeout; its temp dir was cleaned up)
- merging off → 1 fails

Security review: no Critical or High.
- **Medium fixed:** an anonymous flood could wipe the log and queue workers. Fixed with merging, separate caps, and a non-blocking lock that drops the event when busy.
- **Low fixed:** a corrupt event file silenced all logging. Now quarantined and reset, with a test.
- **Info:** 500s during guard checks are not logged. Documented in the README.

## T-10 — Student records — **VERIFIED** (2026-11-17), not merged

Full evidence is in `ai-coordination/T10_IMPLEMENTATION_REPORT.md`.
- Implementation commit `1ca73e6` on `task/T-10-student-records`. Suite 111/111 (91 preserved + 20 new), lint 79/79, Apache PASS.
- Read-only security review: no Critical, High or Medium findings. The 3 Lows and 1 Info are fixed and tested; the remaining Info items are documented.
- Faculty visibility denies all until T-31 provides a verified source; hidden and missing records are both 404.
