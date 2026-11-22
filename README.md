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
- Student numbers are uppercased and validated against `STUDENT_NUMBER_PATTERN` (see `.env.example`), and are always 1–32 characters of `A-Z 0-9 - /`. The default `[A-Z0-9][A-Z0-9-]{2,19}` is a **generic development placeholder, not the school's numbering policy**. Set the real format when the institution supplies it; no code change is needed.
- Profiles are kept when the linked account is deactivated or loses `student`. The account can then no longer read its own profile (401 or 404), but registrar and admin still can, and academic status is never changed automatically.
- `year_level` must fit the program's `max_year_level`. Programs come from a temporary Team 3 catalog (`students/programs.json`); Team 6 owns the final schema.

**Faculty (B-07):** faculty get only `{id, student_number, full_name, program_code,
year_level, sections}`, and only for students enrolled in a section officially
assigned to them. That information comes from `FacultyStudentVisibility`, which
**denies everyone** until T-31 wires a verified source from Teams 4 and 5. Errors or
malformed answers from that source also deny. Faculty can't list or modify profiles.

Development data (after `dev_users.php`): `php database/seeders/dev_students.php`
creates 3 fictional programs and a profile for `student@example.test`.

## Academic records (Team 3, T-11a)

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/api/students/{id}/academic-record` | registrar, admin (any); student (own, while holding `student`) | Anything else is 404, the same as a missing record. Faculty are refused (403) |
| GET | `/api/students/me/academic-record` | student | Own record; 404 if no profile |
| GET | `/api/programs` | any signed-in user | Read-only program catalog, sorted by code |

Record query: `?page=` (default 1) and `?per_page=` (default 50, max 100) for history.
Response `data.academic_record`:
- `student`: id, student number, full name, program code and name, year level, academic status, admission year.
- `history`: entries `{seq, at, field, from, to}` plus pagination. Registrar and admin also see `actor_id` (who made the change); students don't.
- `enrollment_summary` / `grades_summary`: `{"available": false}` until Teams 4 and 5 provide verified count sources (T-11b). Counts are never invented.

History records changes to `program_code`, `year_level` and `academic_status`
(including the initial values at creation). Each entry is written in the same
locked write as the profile change, it is never truncated, and it is kept when the
account is deactivated or loses `student`. Name and admission-year corrections are
not academic history.

History has no size cap (owner decision D4): every valid entry is kept and an
update is never refused because of how many entries exist. Only responses are
paginated. Storage limitation: the file-backed mock store reads and validates
the whole `students.json` (all profiles and all history) on every request, so
reads and writes get slower as history grows. That is acceptable for development.
Production storage waits for Team 6's approved schema (T-40).

For Teams 4 and 5 there is an in-process `StudentDirectory` (`$container['student_directory']`),
with no HTTP route. It offers `findById`, `findByUserId` (summary fields only) and
`isEnrollmentEligible()`. That returns Team 3's part of eligibility: academic
status `active`, and an active account holding `student`. Team 4 enforces every
other enrollment requirement.

## Enrollment: terms and sections (Team 4, T-20)

All reads require an active signed-in user. Writes require registrar or admin,
plus `X-CSRF-Token`; write services also enforce the staff role.

| Method | Path | Result / query |
|---|---|---|
| GET | `/api/terms` | `data.terms`, ordered by start date then id; no pagination |
| POST | `/api/registrar/terms` | 201 `data.term`, "Term created." |
| PATCH | `/api/registrar/terms/{id}` | 200 `data.term`, "Term updated." |
| GET | `/api/sections` | `data.sections` and `data.pagination`; `?term_id=&course=&search=&page=&per_page=` |
| GET | `/api/sections/{id}` | `data.section`; 404 if missing |
| POST | `/api/registrar/sections` | 201 `data.section`, "Section created." |
| PATCH | `/api/registrar/sections/{id}` | 200 `data.section`, "Section updated." |

Terms require `code`, `name`, `start_date`, `end_date`; `is_current` and
`enrollment_open` are strict booleans defaulting to false. Codes are trimmed,
uppercased, unique (409), and immutable. Real dates use `YYYY-MM-DD`, with the
end after the start, including after partial updates. Terms may overlap.
Setting a term current clears the others atomically; zero current terms is
allowed. Enrollment being open is independent of a term being current.

Sections require `term_id`, `course_code`, `course_title`, `units`,
`section_code`, `capacity`, and `meetings`. The term must exist (422).
Course and section codes are trimmed and uppercased; the term/course/section
triple is unique (409), and those three fields are immutable. Units are integers
1–12 and capacity is an integer 1–1000. `approved_count` starts at zero and is
never client-writable; reducing capacity below it returns 409. `remaining` is
computed as capacity minus approved count, never stored.

`delivery_mode` is optional: `scheduled` (default), `tba` or `online_async`,
and PATCH may change it. A `scheduled` section needs at least one meeting.
Only a section explicitly set to `tba` or `online_async` may have no meetings.
An empty meeting list is never taken to mean TBA (422). The mode/meeting pairing
is re-checked on the merged row during PATCH.

Meetings are up to 14 objects with exactly `day`, `start`, `end`, `room`. Days are
`MON` through `SUN`; times are zero-padded `HH:MM` with start before end.
Rooms are trimmed, 1–30 characters, without control or format characters.
Same-day overlaps are rejected (422); back-to-back meetings are allowed.
Meetings are sorted by weekday then start time; PATCH replaces the whole list.
Names and titles also reject control and format characters. Unknown or immutable
body fields return 422 `Unknown field.`; empty PATCH bodies return 422.

There is no hard delete for terms or sections. Archival is a future task: see
the plan's "Future archival requirements".

Section lists default to all terms, ordered by term id, course code, section
code, then id. `term_id` and normalized `course` are exact filters; `search`
is a case-insensitive substring of course code, title or section code (up to
100 characters). Pagination defaults to page 1, 20 per page; page is at most
1,000,000 and per-page at most 100. Unknown query parameters are ignored.

Mock storage is one file, `$runtime/enrollment/enrollment.json`, holding
`terms` and `sections`. Every read and write validates the full snapshot;
corruption fails closed with a generic 500. Each write uses one transaction
for row-dependent checks and changes. MySQL remains unavailable until T-40.
Student enrollment is deferred to T-22.

### Instructor assignments (Team 4, T-21)

| Method | Path | Result |
|---|---|---|
| POST | `/api/registrar/sections/{id}/instructors` | `{faculty_id}` → 201 `data.section`, "Instructor assigned." |
| DELETE | `/api/registrar/sections/{id}/instructors/{facultyId}` | 200 `data.section`, "Instructor unassigned."; 404 if not assigned |

- **Access:** registrar or admin, with `X-CSRF-Token`; the service repeats the role check. Section reads now include `instructors: [{faculty_id, name}]`, in assignment order.
- **Production refuses every assignment until T-30.** Faculty identity comes from Team 5 through `FacultyIdentityLookup`. Until T-30 binds a real lookup, `UnavailableFacultyIdentity` verifies nobody, so every assign gets 422 "Unknown or inactive faculty member." and nothing is written. Test doubles exist only under `tests/`.
- **Refusals:**
  - Unknown, inactive or unverifiable faculty → 422.
  - Duplicate → 409.
  - A 6th instructor → 409.
  - The same instructor teaching an overlapping meeting in the same term → 409. Every actual meeting counts, including those of `tba` and `online_async` sections; back-to-back meetings are fine.
  - A section PATCH that would create such a conflict for an assigned instructor → 409, with nothing written.
- **Removal** never depends on identity verification or name lookup. Remaining names are resolved afterwards for the response; `null` when unavailable.
- **Storage:** only `{section_id, faculty_id}` pairs are stored, plus an append-only `assignment_history` (`seq`, `at`, `action`, `section_id`, `faculty_id`, `actor_id`), both written in the same transaction as the change. Corrupt or inconsistent assignment data fails closed (generic 500). Stores from before T-21 load unchanged.
- **`SectionDirectory`** (`$container['section_directory']`, in-process only) gives Teams 3 and 5 `findSection`, `approvedStudentIds` (`[]` until T-22), `isAssigned`, `sectionIdsForFaculty` and `assignedFacultyIds`. The two access methods count an assignment only for a confirmed active faculty identity, so they deny everything until T-30.

**D1 / T-11b:** course code, title and units currently receive format validation
only. A future course catalog must supply a provider for code validation and
reconcile existing section data if title and units become derived fields.

Development seeding belongs to phase 2b: the planned command is
`php database/seeders/dev_sections.php` (not supplied by this backend phase).
It will create fictional terms and sections only in an empty mock catalog.

## Database (Team 6, T-60) — in development, not active

- **Design:** MariaDB/InnoDB, `utf8mb4_nopad_bin`, strict `sql_mode` and UTC on every connection. See `docs/plans/T-60-database-architecture.md` and `docs/database/`. Conditionally approved 2026-11-21.
- **The application still uses the JSON repositories by default.** `APP_REPOSITORY` and `APP_REPOSITORY_AUTH|STUDENTS|ENROLLMENT|FACULTY` accept `mock|mysql`, and a module on `mysql` needs auth on `mysql`.
- **Phase 7.1: MySQL wiring is test-only.** Bootstrap selects the MySQL repository for auth, students (with programs) and enrollment (the T-20/T-21 catalog) only when `APP_ENV=testing`, `DB_DATABASE=ais_test`, `DB_USERNAME=ais_test_app` and a loopback `DB_HOST`. Anything else stops startup with "MySQL repositories are not available yet.".
- **Faculty stays on mock until T-30.**
- **One shared connection per request,** opened at startup.
- **No fallback to JSON:** a database outage is a generic 500 on every request.
- **File-based, whatever the backend:** sessions, login and re-authentication throttling, and security events.
- **Production use needs the separate G4 cutover approval.**
- **Seeders** (`database/seeders/*.php`) only check the global `APP_REPOSITORY`. With per-module `mysql` overrides they still write JSON files the app then doesn't read. Use `database/import_json.php` for MySQL test data.
- **Test database:** only `ais_test` is used, by tests and CLI tools only, never by the running app. The owner sets up local MariaDB and the accounts (`docs/plans/T-60-database-architecture.md` §9, `docs/database/SECURITY.md` §1, §8).
- **MariaDB version:** XAMPP's 10.4.32 is for development only. Production needs a supported release.
- **`verify.php`** (Phase 8) checks cross-row integrity on demand. It runs before cutover, after imports and migrations, before backups, and whenever integrity is in doubt. It is not the same as the JSON mocks' check on every request.

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
