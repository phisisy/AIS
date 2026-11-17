# AIS backend

T-00 supplies the PHP HTTP core and offline test runner. T-01 adds email login
and sessions (Team 2). PHP 8.1+ is required (verified locally with XAMPP PHP
8.2.12). There are no Composer dependencies. `GET /` returns:

```json
{"success":true,"message":"AIS API is running.","data":{}}
```

## Run locally

From the repository root in PowerShell:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 public/router.php
```

The router sends every request through the front controller, even when the PHP
server's document root is the repository. It does not serve static files.

PHP startup errors occur before application error handling. Disable display of
errors in the server PHP configuration. If XAMPP's configured temporary directory
is inaccessible to the process, select an existing writable private directory:

```powershell
& 'C:\xampp\php\php.exe' -d display_errors=0 -d display_startup_errors=0 -d "upload_tmp_dir=$env:TEMP" -S 127.0.0.1:8000 public/router.php
```

For XAMPP Apache, point a virtual host's `DocumentRoot` at this project's `public/`
directory, enable `mod_rewrite` and the PHP module, and permit `AllowOverride All`
on that directory. Mount the API at the host root, not a URL subdirectory. The
public `.htaccess` routes requests to `index.php` and disables error display.
The repository `.htaccess` also routes all requests through `public/index.php` if
the host points at the repository root, protecting private source and config.
Do not deploy either configuration with `.htaccess` processing disabled.

## Configuration and storage

Configuration is optional. Copy `.env.example` to `.env` to set local values;
`.env` and local variants are ignored by Git. Never place credentials in tracked
files. The dotenv loader supports `KEY=value`, whole-line `#` comments, and
matching single/double quotes. Values are literal: no interpolation, escape
processing, inline comments, or `export` syntax. Duplicate/malformed entries fail
closed. Process environment values take precedence, including empty values.

- `APP_REPOSITORY`: `mock` (default) or `mysql`; other values fail closed.
- `APP_ENV`: `development` (default) or `testing`. Anything else, including
  `production`, fails closed until a deployment is approved.
- `APP_HTTPS`: `true` marks the session cookie `Secure`. Direct HTTPS does this
  automatically; forwarded-proto headers are ignored.
- `APP_RUNTIME_PATH`: trusted private runtime directory holding `sessions/`,
  `auth/users.json` and `auth/login-attempts.json`. Default on Windows:
  **`C:/ais-runtime`**, outside the OneDrive-synced project so sessions and
  password hashes never sync to the cloud (other systems: `storage/runtime/`,
  ignored by Git). It is created on first use (mode 0700; on Windows access
  follows the parent folder's ACL, so keep it on a local, single-user drive).
  Every request validates it: it must be an absolute path to a writable
  directory and not inside `public/`. Otherwise the API returns a generic 500
  and writes nothing. Existing data elsewhere is never moved automatically.
  If you point it into OneDrive anyway, store writes retry briefly when sync
  holds a file open.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: lazy PDO
  configuration. Bootstrap opens no database connection in either mode. MySQL
  repositories and the Team 6 schema remain unimplemented; there is no fallback
  from MySQL to mock.

`JsonStore` is development-only. `read()` takes a shared lock;
`transaction(function (array &$data) { ... })` takes an exclusive lock for the
entire read, modification and atomic file replacement. Exceptions leave the
original JSON unchanged. Corrupt files fail closed. The stable `.lock` file must
not be removed while a store is in use. Transactions cover one JSON file only:
place all rows that must change together in that file. Nested operations on the
same file, including via another store instance, are unsupported. Store paths
must be server-owned values, never request input.

## Authentication (T-01)

Same-origin JSON API with native PHP session cookies (`AIS_SESSION`, HttpOnly,
SameSite=Lax). Idle timeout 30 minutes, absolute timeout 8 hours.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/auth/csrf` | Returns `data.csrf_token`; starts an anonymous session |
| POST | `/api/auth/login` | `{email,password}` + `X-CSRF-Token`. Rotates the session ID and CSRF token; returns `data.user` and the new `data.csrf_token` |
| GET | `/api/auth/me` | Current user `{id,email,roles,status}`, reloaded every request |
| POST | `/api/auth/logout` | Requires sign-in + `X-CSRF-Token` |

Every credential failure (unknown email, wrong password, inactive account, wrong
types) returns the same 401 `Invalid email or password.`. Login is limited to 5
attempts per IP+email and 30 per IP per 15 minutes (429 with `Retry-After`);
successful attempts count. A user who is deactivated, deleted, or whose
`auth_version` changes is signed out on their next request.

Create fictional development accounts (random passwords printed once; refuses to
overwrite an existing store):

```powershell
& 'C:\xampp\php\php.exe' database/seeders/dev_users.php
```

## Role-based access control (T-02)

Roles: `student`, `faculty`, `registrar`, `admin`. A user may hold several; access
is the union. There is no hierarchy: `admin` is not a superuser, so list every
role a route allows. Protect module routes with the guard from the container:

```php
$guard = $container['guard'];
$router->add('GET', '/api/students', $guard->roles(['registrar', 'admin'], [$controller, 'index']));
$router->add('PATCH', '/api/students/{id}', $guard->roles(['registrar', 'admin'], [$controller, 'update']));
$router->add('GET', '/api/students/me', $guard->roles(['student'], [$controller, 'me']));
// Handler signature: (Request $request, array $params, User $user): Response
```

The guard checks, in order: signed in and active (401 `Authentication required.`),
then role (403 `Access denied.`), then `X-CSRF-Token` for any method other than
GET/HEAD/OPTIONS (403 `Invalid CSRF token.`). The user is reloaded every request,
so role changes and deactivation apply immediately. Identity comes only from the
session; role or user fields sent by the client are never used. Allowlists are
validated when routes are registered: an empty list or unknown role (e.g.
`'Admin'`) throws. Unknown roles in stored users fail closed (generic 500).

Every route except `/` and `/api/auth/*` **must** be wrapped by the guard; a test
loads the real bootstrap and fails on any unwrapped route. GET/HEAD/OPTIONS skip
the CSRF check and the cookie is SameSite=Lax, so those handlers must never change
state.

Record ownership (horizontal access) belongs to each module's service. Load the
record first, then call
`$container['authorization']->requireOwnerOrRole($user, $record->userId, ['registrar', 'admin'])`.
It throws 404, so another user's record looks the same as a missing one. Pass the
stored record's **account** id (`student.user_id`), never the route `{id}`, which
is a profile id. Use `isOwnerOrHasRole()` (same rules, returns a bool) only to
filter lists.

## User administration and audit (T-03)

Admin-only (`admin` role, CSRF on writes). Bodies are JSON; unknown fields → 422.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/api/admin/users` | `?search=&page=&per_page=` (≤100) | Email substring search, ordered by id |
| POST | `/api/admin/users` | `{email,password,roles[],status?}` | 201; duplicate email (any case) → 409 |
| GET | `/api/admin/users/{id}` | | 404 if missing |
| PATCH | `/api/admin/users/{id}` | `{email?,roles?}` | `roles` is the full new set: assign or remove roles by sending it |
| PATCH | `/api/admin/users/{id}/status` | `{status}` | `active` or `inactive` |
| POST | `/api/admin/users/{id}/reset-password` | `{password,current_password}` | Non-admin targets only. Re-confirms the admin (rate-limited); signs the target out everywhere |

Rules: passwords 12–72 bytes; roles are a non-empty list of known roles.
Administrators cannot change their own roles, status or password here (409),
so nobody can lock themselves out or self-escalate. The last active
administrator can never be deactivated or lose `admin` (409). Responses never
contain password hashes.

Resetting the password of any account holding `admin` (active or inactive,
including multi-role accounts) is refused with 409 `Administrator passwords
cannot be reset through this endpoint.` and audited as `denied`/`target_is_admin`.
This prevents one administrator taking over another. **Open need:** a secure
account-recovery process for administrators who lose their password (e.g. an
offline, owner-run procedure or two-person approval). Until one is approved,
recovering an admin account requires direct development-time intervention on the
mock store.

**Known gap (Low):** an admin can still reach the same result in three steps:
demote another admin (allowed while another active admin remains), reset that
now non-admin password, then grant `admin` again. Each step needs the acting
admin's session, the reset needs their password, and all three are audited in
order for the same `target_id` (`user.updated` removing admin →
`user.password_reset` → `user.updated` adding admin), so it is detectable but not
prevented. The admin account-recovery design should close it.

The last-administrator guarantee above applies to the current file-backed mock
storage only. The MariaDB integration (T-40) must implement and test equivalent
transactional locking. Until then `APP_REPOSITORY=mysql` is refused at startup.

Each successful change and its audit entry are written in one exclusive
transaction on the same file, so both are saved or neither is. Audit entries
hold `id, at (UTC), actor_id, target_id, action, outcome, changes`. `changes`
keys are limited to `email, roles, status, signed_out_everywhere, reason`, and
keys naming passwords, hashes, tokens or sessions are refused. Refused admin
operations (401/403/404/409/429 from the user service, including a failed
password re-confirmation or a last-admin block) add a separate entry with
`outcome: denied` and a reason code, never the submitted values. Validation
errors (422) and requests the route guard rejects before the service runs (for
example a student calling an admin route) are not audited. There is no audit read
endpoint yet.

Session effects (verified by tests): role changes take effect on the target's
next request (users are reloaded every request). Deactivation and password resets
bump `auth_version`, ending all of the target's sessions; reactivating does not
revive them. An admin request already past the guard is re-checked under the
lock, including its `auth_version`. Password re-confirmation has its own rate
limit (5 per IP+admin, 30 per IP per 15 minutes; successful attempts count) in
`auth/reauth-attempts.json`, separate from login.

## Security events (T-04)

When the route guard refuses a request to an admin route, it appends one event to
`auth/security-events.json` under the runtime path. This file is separate from the
admin audit log, which records what admins did.

| Field | Value |
|---|---|
| `ts`, `at` / `last_ts`, `last_at` | First and latest server time (Unix and UTC ISO-8601) |
| `count` | Number of identical refusals merged into this entry |
| `type` | `unauthenticated` (401: no, expired or forged session) · `forbidden_role` (403) · `csrf_failed` (403) |
| `route` | The registered route pattern, e.g. `PATCH /api/admin/users/{id}/status` (never the raw URL) |
| `actor_id` | Signed-in user id, or `null` when anonymous |
| `outcome` | `refused` |

Nothing from the client is stored: no URLs, query strings, bodies, headers,
cookies, session IDs, tokens or passwords. Every field is built by the server from
fixed values, so log injection is impossible by construction. Invalid values are
dropped. Responses are byte-for-byte unchanged.

Bounds and flood resistance:
- Repeats of the same type, route and actor within 60 seconds increase `count`
  instead of adding entries.
- Anonymous entries (cap 500) and signed-in entries (cap 1,000) are capped
  separately, so anonymous traffic can't push out events that identify a user.
- Entries older than 30 days are removed.
- Writers never wait for the file lock: if another request holds it, that event
  is dropped rather than making the refused request queue.

Logging is best-effort. If the file can't be written, the request is still
refused with the same 401/403 and the handler never runs. A corrupt event file is
renamed to `security-events.json.corrupt-<time>`, a fixed message goes to the PHP
error log, and logging resumes with a new file.

Not security events:
- Errors raised inside an admin handler after the guard has allowed the request
  (the admin audit log records those as `denied`).
- Server errors (500) during the guard's checks, for example an unreadable user store.

Opt a route in with `$guard->roles([...], $handler, 'METHOD /path/{id}')`. The six
`/api/admin/users*` routes are opted in.

## Student records (Team 3, T-10)

Full contract: `ai-coordination/API_CONTRACT_T10_STUDENTS.md`. All routes use the
T-02 guard; writes need `X-CSRF-Token`.

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/api/students` | registrar, admin | `?search=&program_code=&academic_status=&page=&per_page=` |
| POST | `/api/students` | registrar, admin | Profile for an existing, active `student` account (no credentials are created). 409 on a duplicate number or account |
| GET | `/api/students/me` | student | Own profile; 404 if none |
| GET | `/api/students/{id}` | registrar, admin (full); own account (full); faculty (summary, see below) | Anything else is 404, identical to a missing record |
| PATCH | `/api/students/{id}` | registrar, admin | Name, program, year level, academic status, admission year. Student number and account link can't be changed |

- `academic_status`: `active`, `inactive`, `graduated`, `transferred`, `on_leave`. Separate from the account's active/inactive status.
- Student numbers are uppercased and validated against `STUDENT_NUMBER_PATTERN` (see `.env.example`), and are always 1–32 characters of `A-Z 0-9 - /`.
- `year_level` must fit the program's `max_year_level`. Programs come from a temporary Team 3 catalog (`students/programs.json`); Team 6 owns the final schema.

**Faculty (B-07):** faculty get only `{id, student_number, full_name, program_code,
year_level, sections}`, and only for students enrolled in a section officially
assigned to them. That information comes from `FacultyStudentVisibility`, which
**denies everyone** until T-31 wires a verified source from Teams 4 and 5. Errors or
malformed answers from that source also deny. Faculty can't list or modify profiles.

Development data (after `dev_users.php`): `php database/seeders/dev_students.php`
creates 3 fictional programs and a profile for `student@example.test`.

## Core interfaces

- `app/bootstrap.php` returns an explicit array container with `config`,
  `database_config`, and `router`; module route files return registration closures
  receiving `(Router $router, array $container)`. The container also holds
  `session`, `users`, `auth` and `auth_controller`.
- `Router::add($method, $path, $handler)` registers a handler receiving
  `(Request $request, array $params)` and returning `Response`. Full-segment
  `{name}` parameters accept canonical positive decimal integers within PHP's
  integer range. More literal routes win regardless of registration order.
  Unknown paths return 404; unsupported methods return 405 with `Allow`.
- `Request::fromGlobals()` reads at most 65,537 bytes and enforces a 65,536-byte
  cap. Nonempty bodies require `application/json`; malformed JSON returns 400,
  unsupported content type 415, excess size 413, and non-object JSON 422. Empty
  bodies are represented by an empty array. `json($allowedFields)` rejects
  unknown fields with 422; future controllers must supply their approved field
  allowlists. Nested JSON objects retain their object type for validation.
- `Response::success($data, $message, $status)` and
  `Response::error($message, $status, $errors, $headers)` create JSON envelopes
  with `Content-Type: application/json`, `Cache-Control: no-store`, and
  `X-Content-Type-Options: nosniff`.
- `HttpException($status, $message, $errors, $headers)` carries safe client
  errors. Do not include secrets in client-facing errors. `ErrorHandler::run()`
  catches application failures, discards accidental output, logs only the
  exception class, and returns a generic 500. Server error details are hidden.

## Verification

```powershell
& 'C:\xampp\php\php.exe' tests/run.php
$phpFiles = rg --files -g '*.php'
foreach ($file in $phpFiles) { & 'C:\xampp\php\php.exe' -l $file; if ($LASTEXITCODE -ne 0) { throw "Syntax check failed: $file" } }
```

The plain-PHP runner discovers `*Test.php` recursively and exits nonzero on any
failure. Assertions use explicit exceptions and work even with PHP assertions
disabled. Tests include loopback PHP servers, isolated temporary files, failure
paths, and four synchronized writer processes. Test servers disable startup
error display and use their private temporary directory for request buffering.
The suite needs `proc_open`, loopback sockets, and writable system temp storage.
It never connects to a database or changes installed server configuration.

Optional XAMPP Apache verification (a separate private server, not a running
XAMPP service):

```powershell
& 'C:\xampp\php\php.exe' tests/Core/apache_check.php
```

This check requires Apache to be permitted to access the workspace. See
`ai-coordination/CODEX_REPORT.md` for executed results and environment limitations.
