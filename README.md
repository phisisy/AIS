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
| POST | `/api/admin/users/{id}/reset-password` | `{password,current_password}` | Re-confirms the admin (rate-limited); signs the target out everywhere |

Rules: passwords 12–72 bytes; roles are a non-empty list of known roles.
Administrators cannot change their own roles, status or password here (409),
so nobody can lock themselves out or self-escalate. The last active
administrator can never be deactivated or lose `admin` (409). Responses never
contain password hashes.

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
