# Architecture — AIS Backend (PROPOSED, awaiting owner approval)

Status: **PROPOSED**, except §1 (approved by owner 2026-11-17). Decisions marked ⚠ need owner approval (tracked in `INTEGRATION_BLOCKERS.md`).

## 1. Shape: one application, four modules (APPROVED, B-02)

One PHP application in `AIS_SYSTEM/` with one front controller, one session, and one database
connection. Teams 2–5 are **modules** inside it, not separate apps.

Why: separate apps would each have their own PHP session store, so a Team 2 login would not be
visible to Teams 3–5 without building cross-app session sharing or tokens. One app avoids that
entire integration problem and matches the "single shared database" plan.

## 2. Directory layout

```
AIS_SYSTEM/
├── app/
│   ├── Core/              Shared: Request, Response, Router, HttpException, Environment,
│   │                      Database (PDO factory), Session, JsonStore (dev mock storage)
│   ├── Auth/              Team 2: Controllers/ Services/ Repositories/ Models/ Middleware/
│   ├── Students/          Team 3: same sub-folders
│   ├── Enrollment/        Team 4: same sub-folders
│   ├── Faculty/           Team 5: same sub-folders (faculty, assignments, rosters, grades)
│   ├── autoload.php       PSR-4 style: App\ → app/
│   └── bootstrap.php      Builds the container and wires module services
├── config/                app.php, database.php (values from .env, no secrets committed)
├── routes/                auth.php, students.php, enrollment.php, faculty.php
├── public/                index.php (front controller), router.php (php -S), .htaccess
├── database/              migrations/ seeders/  (Team 6 approval required for shared tables)
├── storage/runtime/       sessions, mock JSON stores (git-ignored, outside web root)
├── tests/                 run.php + Core/ Auth/ Students/ Enrollment/ Faculty/ Integration/
├── .env.example
└── ai-coordination/
```

Namespaces: `App\Core`, `App\Auth`, `App\Students`, `App\Enrollment`, `App\Faculty`.

## 3. Layering (every module)

```
Route → Middleware (auth, role, CSRF) → Controller → Service → RepositoryInterface → Mock | MySQL repository
```

| Layer | Does | Does not |
|---|---|---|
| Controller | Parse request, call one service method, wrap in `Response::success` | Business rules, SQL, authorization decisions |
| Service | Validation, business rules, **authorization + ownership checks**, transactions | Read `$_SESSION`/`$_GET` directly, build SQL |
| Repository interface | Persistence contract | — |
| Mock repository | JSON-file storage for development before Team 6 finalizes the schema | Production use |
| MySQL repository | PDO prepared statements only | String-built SQL, schema creation |

Services re-check authorization even when middleware already did (defense in depth; services may be called from other modules).

## 4. Cross-module rule

A module **never** reads another module's tables or repositories directly. It depends on a small
read-only interface owned by the providing module (defined in `API_CONTRACTS.md` §3), injected in
`bootstrap.php`. This keeps each team's storage private and lets mock and MySQL implementations swap.

Dependency direction (no cycles in services):

```
Auth  ←  Students  ←  Enrollment  ←  Faculty
  ↑__________|____________|____________|      (everyone depends on Auth)
```

Enrollment needs instructor names for display: it gets them through `InstructorLookup`, an
interface **defined by Enrollment** and **implemented by Faculty**, wired in bootstrap. This keeps the
service graph acyclic. ⚠ (B-04). Superseded by §4.1.

### 4.1 B-04 decision: instructor-to-section assignment (owner, 2026-11-17)

- **Team 4 (Enrollment) owns the authoritative assignment.** It holds the storage (`{section_id, faculty_id}` in the Enrollment store), the rules and the endpoint `POST`/`DELETE /api/registrar/sections/{id}/instructors`. This replaces Team 5's `POST /api/registrar/teaching-assignments`.
- **Team 5 (Faculty) owns faculty profiles and grades.** It reads assignments only through `SectionDirectory::isAssigned` and `sectionIdsForFaculty` (Team 4). It must not keep a conflicting assignment table.
- **Faculty ids must be valid faculty identities.** They are checked through `FacultyIdentityLookup`, a port in `app/Enrollment/Contracts/` implemented by Team 5. It replaces `InstructorLookup`, and keeps the graph acyclic. It fails closed: an unknown id, an inactive faculty member or a lookup error means the assignment is refused. No faculty data is copied; names are resolved at read time.
- **Authorization is server-side:** guard plus service, registrar and admin only. Every successful change appends one audit entry in the same store transaction (`assignment_history`). There are no Team 6 schema changes.
- **Implementation:** T-21, expanded. T-30 implements the lookup and consumes the directory. Details are in `docs/plans/T-20-terms-sections.md` §9.

## 5. Core conventions

- **Responses:** `{"success":true,"message":"...","data":{...}}` or `{"success":false,"message":"...","errors":{...}}`. `Content-Type: application/json`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.
- **Status codes:** 200, 201 created, 400 malformed JSON, 422 JSON body that is not an object (T-00 behavior), 401 not signed in, 403 forbidden / bad CSRF, 404, 405 (with `Allow`), 409 conflict (duplicate, capacity, schedule clash, state change not allowed), 413, 415, 422 validation (`errors` keyed by field), 429 (with `Retry-After`), 500 generic message only.
- **Errors:** throw `HttpException(status, message, errors)`. The front controller catches everything else, logs the exception class only, and returns a generic 500. No stack traces, SQL, or input echoed.
- **Router:** path params `{id}`, `{sectionId}` etc. as positive integers. Literal routes like `/api/students/me` are registered before `{id}` routes.
- **Input:** JSON bodies only (`application/json`), 64 KB cap, unknown fields rejected with 422.
- **Sessions:** native PHP sessions, HttpOnly, SameSite=Lax, strict mode, ID regenerated at login, idle + absolute timeout. Session files under `storage/runtime/`.
- **CSRF:** token from `GET /api/auth/csrf`, sent as `X-CSRF-Token` on every POST/PATCH/PUT/DELETE.
- **Database:** PDO with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `utf8mb4`, multi-statements off. Connection errors never include credentials.
- **Repository selection:** `APP_REPOSITORY=mock|mysql` in `.env`. Default `mock`. Unknown value → fail closed. MySQL never falls back to mock.
- **Transactions:** a service operation that writes more than one row (or writes + audits) runs inside `repository->transaction(callable)`. Mock repositories serialize with a file lock.

## 6. Security baseline (review checklist for every task)

1. Every non-public route has `AuthMiddleware`; role-restricted routes also have `RoleMiddleware`; every state-changing route has `CsrfMiddleware`.
2. Ownership is checked in the service, from the session user, never from a client-supplied ID ("student can't read another student by changing the URL").
3. Client-supplied `user_id`, `role`, `status`, `student_id` (for "me" routes), `faculty_id` are ignored or rejected.
4. Passwords: `password_hash(PASSWORD_DEFAULT)`, 12–72 bytes on create/reset; login rate-limited.
5. Draft grades are never visible to students; only finalized grades are.
6. Audit log entries never contain passwords, hashes, tokens, or session IDs.
7. No secrets in the repo; `.env` is git-ignored; `.env.example` has placeholders only.

## 7. Testing

- `php tests/run.php` runs all offline tests (mock repositories, no DB), exits non-zero on failure. Plain PHP assertions, no PHPUnit (no Composer).
- HTTP-level tests start `php -S 127.0.0.1:<port> public/router.php` against a private runtime dir.
- MySQL tests (later, after Team 6) run only against a local database named `ais_test` and refuse any other database name.
- Syntax: `php -l` on every PHP file.
