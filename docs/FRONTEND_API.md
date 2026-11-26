# AIS Frontend Integration Contract

> **STATUS: UNVERIFIED — derived from unexecuted source on 2026-11-25; confirm against a running server on a trusted machine.**
> Source of truth: the code on branch `task/INT-src` at HEAD `40182f3` (release candidate) (`routes/*.php`, controllers, services, repositories, `app/Core`, `app/Auth`, `config/app.php`).
> Where docs disagree with the code, this file follows the code. See the "Doc/code mismatches" section near the end.

## 1. Basics

### 1.1 Transport

| Topic | Rule |
|---|---|
| Base URL | Same origin as the UI. Entry point is `public/index.php`: `php -S` with `public/router.php`, or Apache with `public/.htaccess` (every path rewritten to `index.php`). No CORS, no preflight: `OPTIONS`/`HEAD` are not routed (405 or 404). |
| Health | `GET /` returns 200 `{"success":true,"message":"AIS API is running.","data":{}}`. No auth. |
| Request bodies | JSON objects only. A non-empty body needs `Content-Type: application/json` (parameters such as `; charset=utf-8` are allowed), otherwise **415** `Content-Type must be application/json.` |
| Empty body | Allowed with any or no Content-Type. It is treated as `{}`. |
| Size limit | 65,536 bytes. Larger bodies (or a larger `Content-Length`) get **413** `Request body is too large.` |
| Malformed JSON | **400** `Malformed JSON body.` |
| Non-object JSON (array, string, number) | **422** `The JSON body must be an object.` |
| Unknown body fields | Every write endpoint has an allowlist. Each extra field gives **422** with `errors.<field> = "Unknown field."`. Endpoints that take no body (drop, submit, finalize, instructor DELETE) refuse any field. |
| Bad path | A path with control chars, spaces or `#` gives **400** `Invalid request path.` |
| Path ids | `{id}` segments match only canonical positive integers (`[1-9][0-9]*`). `0`, `abc`, `01` or `-1` give **404** `Not found.` |
| Unknown path | **404** `Not found.` |
| Wrong method | **405** `Method not allowed.` with an `Allow` header. |
| Response headers | `Content-Type: application/json`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. |
| Timestamps | UTC, ISO 8601 to the second with `Z`: `2026-01-31T08:00:00Z` (`created_at`, `updated_at`, `requested_at`, `decided_at`, `dropped_at`, history `at`, audit `at`). Term dates are `YYYY-MM-DD`. Meeting times are `HH:MM` (24 h). |

### 1.2 Envelopes

Success:
```json
{ "success": true, "message": "OK", "data": { } }
```
`data` is always a JSON object (it is `{}` when there is nothing to return, e.g. logout). `message` is a human-readable string. Show it as plain text, or ignore it.

Error:
```json
{ "success": false, "message": "Validation failed.", "errors": { "field": "Reason." } }
```
- `errors` is always an object. It may be `{}`.
- Keys are body or query field names, `body` (an empty PATCH), dotted item paths (`meetings.0.start`), or rule keys (`grade_scale`, `stale_grade`, `grades_missing`, `student_id`, `instructors`).
- Values are usually strings. **Exception:** `errors.grades_missing` is an **array of student ids** (integers).
- **500**: always `{"success":false,"message":"An internal error occurred.","errors":{}}`. No detail is given. Show a generic error and allow a retry.

Pagination (list endpoints marked "paged"):

| Query | Default | Range | Error |
|---|---|---|---|
| `page` | 1 | 1 to 1,000,000 | 422 `errors.page` |
| `per_page` | 20 (academic-record history: 50) | 1 to 100 | 422 `errors.per_page` |

Response: `data.<items>` plus `data.pagination = { page, per_page, total }`. `total` is the full match count. Asking for a page past the end returns an empty list, not an error.

### 1.3 Session, CSRF, auth

| Topic | Rule |
|---|---|
| Session cookie | `AIS_SESSION`. HttpOnly, `SameSite=Lax`, `Path=/`, session-lifetime (no expiry). `Secure` when `APP_HTTPS=true` or the request is HTTPS. The browser handles it; use `fetch(..., {credentials: 'same-origin'})` (the default). |
| Timeouts | Idle 30 min, absolute 8 h. After either, the next guarded call returns **401** and the session is cleared, **including its CSRF token**. |
| CSRF token | `GET /api/auth/csrf` gives `data.csrf_token` (64 hex chars). It starts an anonymous session if there is none. |
| CSRF header | `X-CSRF-Token: <token>` |
| Which requests need it | Every `POST`, `PUT`, `PATCH`, `DELETE`, including `POST /api/auth/login` and `/api/auth/logout`. `GET` never needs it. |
| CSRF failure | **403** `Invalid CSRF token.` (missing, malformed or stale). Recovery: `GET /api/auth/csrf`, then retry once. |
| Token rotation | Login rotates the session ID **and** the token. Replace the stored token with the `data.csrf_token` in the login response. Logout destroys the session, so fetch a new token before the next login. |
| Guard order (all guarded routes) | 1. signed in and active, else **401** `Authentication required.` 2. role allowed, else **403** `Access denied.` 3. CSRF on writes, else **403** `Invalid CSRF token.` 4. handler. |
| Account changes | A deactivated user, or one whose password was reset (the auth version changes), gets 401 on their next request. Role changes take effect on the next request. |

Rate limiting (login): each attempt counts, successful ones included. The limits are 5 per IP+email and 30 per IP in a sliding 15-minute window. Over the limit: **429** `Too many login attempts.` with a `Retry-After: <seconds>` header. Show a wait message and do not retry automatically. The admin password-reset re-confirmation has the same limits in a separate bucket (keyed by IP and the acting admin's email).

## 2. Roles

Roles: `student`, `faculty`, `registrar`, `admin`. A user may hold several, and access is the union. `admin` is **not** a superuser: it is listed explicitly where allowed. Role gates come first (403). Record ownership is then checked in the services (usually 404, so the response does not reveal whether a record exists).

Legend: Y = allowed, own = only the caller's own record (resolved from the session), A = assigned sections only, - = 403 from the guard.

| Endpoint | student | faculty | registrar | admin |
|---|---|---|---|---|
| GET /api/auth/csrf, POST /api/auth/login | public | public | public | public |
| GET /api/auth/me, POST /api/auth/logout | Y | Y | Y | Y |
| /api/admin/users*, GET /api/admin/audit-logs | - | - | - | Y |
| GET /api/students | - | - | Y | Y |
| POST /api/students, PATCH /api/students/{id} | - | - | Y | Y |
| GET /api/students/me | own | - | - | - |
| GET /api/students/{id} | own (else 404) | read-only summary for students on a current roster, else 404 (see 3.3) | Y | Y |
| GET /api/students/me/academic-record | own | - | - | - |
| GET /api/students/{id}/academic-record | own (else 404) | - | Y | Y |
| GET /api/programs, GET /api/terms, GET /api/sections, GET /api/sections/{id} | Y | Y | Y | Y |
| POST/PATCH /api/registrar/terms*, /api/registrar/sections* (incl. instructors) | - | - | Y | Y |
| POST /api/enrollments, GET /api/enrollments/me, POST /api/enrollments/{id}/drop, GET /api/schedules/me | own | - | - | - |
| GET /api/registrar/enrollments, PATCH /api/registrar/enrollments/{id} | - | - | Y | Y |
| /api/registrar/faculty* | - | - | Y | Y |
| GET /api/faculty/me, GET /api/faculty/classes | - | own | - | - |
| GET /api/faculty/classes/{id}/students, GET /api/faculty/classes/{id}/grades, PUT .../grades/{studentId}, POST .../grades/submit | - | A | - | - |
| GET /api/registrar/sections/{id}/grades, POST .../grades/finalize, POST .../grades/return, POST /api/registrar/grades/{id}/corrections | - | - | Y | Y |
| GET /api/grades/me | own | - | - | - |

Separation of duties (grades): a registrar/admin who is assigned to a section, or who entered, updated or submitted grades in it, gets 403 on finalize and corrections. An account that also has a student profile gets 403 `Access denied.` when acting on its own student record: grade enter (the `{studentId}` is the caller's own student id), finalize of a section whose approved roster or grade rows contain it, correction of a grade that belongs to it, and enrollment approve/reject (`PATCH /api/registrar/enrollments/{id}`) of its own enrollment.

## 3. Endpoints

Common errors on every guarded endpoint (not repeated below): 401 not signed in or session expired, 403 role, 403 CSRF (writes), 400/413/415/422 body transport errors (section 1.1), 500.

### 3.1 Auth (4)

| Method & path | Roles | CSRF | Request | Success | Errors |
|---|---|---|---|---|---|
| GET /api/auth/csrf | public | no | - | 200 `{csrf_token}` | 500 |
| POST /api/auth/login | public | **yes** | `email` string (required, at most 254, trimmed and lowercased), `password` string (required, at most 72 bytes) | 200 "Signed in successfully." `{user, csrf_token}` | 403 CSRF (checked first), 422 unknown field, 429 throttled, 401 `Invalid email or password.` for **every** credential failure (unknown, wrong, inactive, wrong type) |
| POST /api/auth/logout | signed in | yes | - | 200 "Signed out successfully." `{}` | 401 (checked before CSRF), 403 CSRF |
| GET /api/auth/me | signed in | no | - | 200 `{user}` | 401 |

`user` = `{ id:int (account id), email:string, roles:string[], status:"active"|"inactive" }`. The account id is **not** a student id or faculty id.

### 3.2 Admin users and audit logs (7), admin only

| Method & path | CSRF | Request | Success | Errors |
|---|---|---|---|---|
| GET /api/admin/users | no | query `search` (at most 254, email substring), paged | 200 `{users:[user], pagination}` ordered by id | 422 `search`/`page`/`per_page` |
| POST /api/admin/users | yes | `email` (required, valid, at most 254), `password` (required, 12-72 bytes, not blank), `roles` (required, non-empty array of role names), `status` (`active`\|`inactive`, default `active`) | 201 "Account created." `{user}` | 422 field errors; 409 `errors.email` duplicate |
| GET /api/admin/users/{id} | no | - | 200 `{user}` | 404 `User not found.` |
| PATCH /api/admin/users/{id} | yes | `email`?, `roles`? (the **full new set**), at least one | 200 "Account updated." `{user}` (no-op if unchanged) | 422 (`body` if empty); 404; 409 `errors.email` duplicate; 409 `Administrators cannot change their own roles.`; 409 last active admin would lose `admin` |
| PATCH /api/admin/users/{id}/status | yes | `status` (required, `active`\|`inactive`) | 200 "Account status updated." `{user}` | 422; 404; 409 own status; 409 last active admin |
| POST /api/admin/users/{id}/reset-password | yes | `password` (required, 12-72 bytes), `current_password` (required, the acting admin's password) | 200 `{user}` (target signed out everywhere) | 422; 429; 403 `Current password confirmation failed.`; 404; 409 own account; 409 target is an admin |
| GET /api/admin/audit-logs | no | query: paged; `action` (`[a-z0-9_.]{1,64}`), `outcome` (`success`\|`denied`), `actor_id`, `target_id` (0 to 2147483647), `from`, `to` (exact `YYYY-MM-DDTHH:MM:SSZ`, inclusive, `from` not after `to`). Array-valued params are refused. Any other query key (including `search`) gives 422 `errors.<key> = "Unknown field."`, reported together with the other param errors. | 200 `{entries:[{id, at, actor_id, target_id, action, outcome, changes}], pagination}`, newest first | 422 per param: `page` "Use a whole number from 1 to 1000000.", `per_page` "Use a whole number from 1 to 100.", `action` "Use 1 to 64 characters: a-z, 0-9, underscore, dot.", `outcome` "Use success or denied.", `actor_id`/`target_id` "Use a whole number from 0 to 2147483647.", `from`/`to` "Use an exact UTC time like 2026-01-31T08:00:00Z.", `from` "from must not be after to.", unknown key "Unknown field." |

`changes` is an object whose shape depends on `action`: `user.created` `{email, roles, status}`; `user.updated` `{field:{from,to}}`; `user.activated`/`user.deactivated` `{status:{from,to}}`; `user.password_reset` `{signed_out_everywhere:true}`; denied entries `{reason}`. `target_id` 0 means "account not created".

### 3.3 Students, programs, academic records (8)

`student` (full) = `{ id, user_id, student_number, first_name, middle_name|null, last_name, program_code, year_level, academic_status, admission_year|null, created_at, updated_at }`.
`academic_status` is one of `active`, `inactive`, `graduated`, `transferred`, `on_leave`.

| Method & path | Roles | CSRF | Request | Success | Errors |
|---|---|---|---|---|---|
| GET /api/students | registrar, admin | no | query `search` (at most 100; matches number, first or last name), `program_code`, `academic_status`, paged | 200 `{students:[student], pagination}` by id | 422 per param |
| POST /api/students | registrar, admin | yes | required: `user_id` int (active account with `student` role), `student_number` (institutional pattern; trimmed, uppercased, 1-32 of `A-Z0-9-/`), `first_name`, `last_name` (1-100, no control chars), `program_code` (known), `year_level` int 1..program max. optional: `middle_name` (string or null), `academic_status` (default `active`), `admission_year` (1900..next year, or null) | 201 "Student profile created." `{student}` | 422 field errors; 409 `errors.user_id` (already has a profile) / `errors.student_number` (in use) |
| GET /api/students/me | student | no | - | 200 `{student}` | 404 `Student not found.` (no profile) |
| GET /api/students/{id} | registrar, admin, student, faculty | no | - | 200 `{student}`. Staff and the owner get the full shape. Faculty (without a staff role, not the owner) get the read-only summary `{id, student_number, full_name, program_code, year_level, sections:[{section_id, section_code, enrollment_status}]}`, only while the student is **currently approved** in a section the caller is **currently assigned** to (faculty profile active, account active with the `faculty` role; read live on every call). `sections` lists those shared sections only; `section_code` is `COURSE-SEC` (e.g. `CS101-A`) and `enrollment_status` is always `"approved"` | 404 for anything the caller may not see. Faculty: 404 when not on a current roster, after the student is dropped or the assignment removed, with an inactive profile, or if the visibility lookup fails (fails closed). Faculty stay 403 on `GET /api/students`, `POST`, `PATCH` and both academic-record routes |
| PATCH /api/students/{id} | registrar, admin | yes | any of `first_name`, `middle_name`, `last_name`, `program_code`, `year_level`, `academic_status`, `admission_year`. `user_id` and `student_number` are not accepted (422 unknown field) | 200 "Student profile updated." `{student}` | 422 (`body` if empty); 404; 409 `errors.year_level` (concurrent change made the pair invalid; reload) |
| GET /api/students/me/academic-record | student | no | paged (history; default per_page 50) | 200 `{academic_record}` | 404 no profile; 422 paging |
| GET /api/students/{id}/academic-record | registrar, admin, student | no | paged | 200 `{academic_record}` | 404 not yours or missing; 422 |
| GET /api/programs | any signed-in | no | - | 200 `{programs:[{code, name, max_year_level}]}` by code | - |

`academic_record` = `{ student:{id, student_number, full_name ("Last, First Middle"), program_code, program_name|null, year_level, academic_status, admission_year}, history:{entries:[{seq, at, field ("program_code"|"year_level"|"academic_status"), from, to, actor_id (staff only)}], pagination}, enrollment_summary, grades_summary }`.
`enrollment_summary` = `{available:true, counts:{pending, approved, rejected, dropped}}` or `{available:false}`.
`grades_summary` = `{available:true, counts:{finalized, finalized_<enrollment status>...}}` or `{available:false}` (also shown when there are no finalized grades). Show "unavailable" for `available:false`. Never show 0 in that case.

### 3.4 Terms, sections, instructor assignments (9)

`term` = `{ id, code, name, start_date, end_date, is_current:bool, enrollment_open:bool, created_at, updated_at }`.
`section` = `{ id, term_id, course_code, course_title, units, section_code, capacity, approved_count, remaining, delivery_mode ("scheduled"|"tba"|"online_async"), meetings:[{day ("MON".."SUN"), start, end, room}], instructors:[{faculty_id, name|null}], created_at, updated_at }`. `name` is null when the name lookup is unavailable.

| Method & path | Roles | CSRF | Request | Success | Errors |
|---|---|---|---|---|---|
| GET /api/terms | any signed-in | no | - | 200 `{terms:[term]}` (not paged) | - |
| POST /api/registrar/terms | registrar, admin | yes | required `code` (2-20, `A-Z0-9-`, uppercased), `name` (1-100), `start_date`, `end_date` (real `YYYY-MM-DD`, end after start); optional `is_current`, `enrollment_open` (bool, default false) | 201 "Term created." `{term}` | 422; 409 `errors.code` duplicate |
| PATCH /api/registrar/terms/{id} | registrar, admin | yes | any of `name`, `start_date`, `end_date`, `is_current`, `enrollment_open` (`code` is refused) | 200 "Term updated." `{term}` | 422 (`body`, `end_date` checked against the merged row); 404 `Term not found.` |
| GET /api/sections | any signed-in | no | query `search` (at most 100; course code, title, section code), `term_id` int, `course` (course code), paged | 200 `{sections:[section], pagination}` | 422 per param |
| GET /api/sections/{id} | any signed-in | no | - | 200 `{section}` | 404 `Section not found.` |
| POST /api/registrar/sections | registrar, admin | yes | required `term_id` int, `course_code` (2-20), `course_title` (1-150), `units` int 1-12, `section_code` (1-10, `A-Z0-9-`), `capacity` int 1-1000, `meetings` (array, at most 14, each exactly `{day, start, end, room (1-30)}`, start before end, no overlaps between them); optional `delivery_mode` (default `scheduled`). `scheduled` needs 1 or more meetings; `tba`/`online_async` may have 0 | 201 "Section created." `{section}` | 422 (incl. `meetings.N`, `meetings.N.day/start/end/room`, `term_id` "Unknown term."); 409 `errors.section_code` duplicate |
| PATCH /api/registrar/sections/{id} | registrar, admin | yes | any of `course_title`, `units`, `capacity`, `delivery_mode`, `meetings` (`term_id`, `course_code`, `section_code` refused) | 200 "Section updated." `{section}` | 422; 404; 409 `errors.capacity` (below approved count); 409 `errors.meetings` (instructor or approved student would get a clash) |
| POST /api/registrar/sections/{id}/instructors | registrar, admin | yes | `faculty_id` int (required) | 201 "Instructor assigned." `{section}` | 404 section; 422 `errors.faculty_id` (missing, bad, unknown or inactive faculty); 409 `errors.faculty_id` (already assigned, or teaching clash), 409 `errors.instructors` (max 5) |
| DELETE /api/registrar/sections/{id}/instructors/{facultyId} | registrar, admin | yes | no body fields | 200 "Instructor unassigned." `{section}` | 404 section or `Instructor assignment not found.` |

### 3.5 Enrollments and schedule (6)

`enrollment` = `{ id, student_id, section_id, term_id, status ("pending"|"approved"|"rejected"|"dropped"), requested_at, decided_at|null, decided_by|null (staff responses only), dropped_at|null, reason|null }`.

| Method & path | Roles | CSRF | Request | Success | Errors |
|---|---|---|---|---|---|
| POST /api/enrollments | student | yes | `section_id` int (required, only field) | 201 "Enrollment requested." `{enrollment}` (pending) | 422 `errors.section_id` (missing, bad, `Unknown section.`); 403 `A student profile is required.`; 409 rule refusals (section 4) |
| GET /api/enrollments/me | student | no | query `status`, `term_id`, paged | 200 `{enrollments, pagination}` | 403 no profile; 422 |
| POST /api/enrollments/{id}/drop | student | yes | no body fields | 200 "Enrollment dropped." `{enrollment}` | 403 no profile; 404 `Enrollment not found.` (missing or not yours); 409 `errors.status` (wrong state / enrollment closed) |
| GET /api/schedules/me | student | no | query `term_id` (default: the current term) | 200 `{term_id|null, items:[{enrollment_id, section_id, course_code, course_title, section_code, units, delivery_mode, meetings, instructors}]}`, approved only, sorted by course then section. No current term gives `{term_id:null, items:[]}` | 403 no profile; 422 `term_id`; 404 `Term not found.` |
| GET /api/registrar/enrollments | registrar, admin | no | query `status`, `term_id`, `section_id`, `student_id`, paged | 200 `{enrollments, pagination}` (with `decided_by`) | 422 |
| PATCH /api/registrar/enrollments/{id} | registrar, admin | yes | `status` (`approved`\|`rejected`, required), `reason` (1-255, no control chars; **required for rejected**, optional for approved) | 200 "Enrollment decided." `{enrollment}` | In check order: 422 `Unknown field.` for any key other than `status`/`reason`; 404 `Enrollment not found.`; 403 `Access denied.` when the enrollment belongs to the caller's own student profile; 422 `errors.status` `Use "approved" or "rejected".` / `errors.reason` (`A reason is required when rejecting.` or `Use 1 to 255 characters without control characters.`); 409 `errors.status` (`The student is not eligible to enroll.`, not pending, section full, schedule clash) |

### 3.6 Faculty profiles, classes, rosters (7)

`faculty` = `{ id (faculty_id), user_id, employee_number, first_name, last_name, department, status ("active"|"inactive"), created_at, updated_at }`.

| Method & path | Roles | CSRF | Request | Success | Errors |
|---|---|---|---|---|---|
| GET /api/registrar/faculty | registrar, admin | no | query `search` (at most 100), `status`, paged | 200 `{faculty:[faculty], pagination}` | 422 |
| POST /api/registrar/faculty | registrar, admin | yes | required `user_id` int (active account with `faculty` role), `employee_number` (configured pattern; 1-32 of `A-Z0-9-/`), `first_name`, `last_name` (1-70), `department` (1-100); optional `status` (default `active`) | 201 "Faculty profile created." `{faculty}` | 422; 409 `errors.user_id` / `errors.employee_number` duplicates |
| GET /api/registrar/faculty/{id} | registrar, admin | no | - | 200 `{faculty}` | 404 `Faculty profile not found.` |
| PATCH /api/registrar/faculty/{id} | registrar, admin | yes | any of `first_name`, `last_name`, `department`, `status` | 200 "Faculty profile updated." `{faculty}` | 422 (`body` if empty); 404 |
| GET /api/faculty/me | faculty | no | - | 200 `{faculty}` (any status) | 404 no profile |
| GET /api/faculty/classes | faculty | no | query `term_id` | 200 `{classes:[{section_id, term_id, course_code, section_code}]}` by section_id (not paged) | 422; 404 no profile |
| GET /api/faculty/classes/{id}/students | faculty | no | query `search` (name or number, at most 100), paged | 200 `{students:[{student_id, student_number, full_name, program_code, year_level}], pagination}` (approved students only, sorted by name) | 422; 404 no profile; 403 inactive profile, not assigned, or unknown section |

### 3.7 Grades (8)

**Grade scale (env `APP_GRADE_SCALE`).** `off` (the default) means grade entry and corrections answer 409 `errors.grade_scale`. `demo` activates the **demo-only** scale `1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00, 5.00, INC, DRP` (1.00-3.00 passing, 5.00 failing, INC Incomplete, DRP Dropped; the meanings are documentation only). It is **not the official university policy**, which is still pending. Any other value refuses startup. Codes are exact, case-sensitive strings (`3.25` and `inc` are 422). Do not hard-code the list in a production UI; there is no scale endpoint.
`{id}` under `/api/faculty/classes/` and `/api/registrar/sections/` is the **section id**. `{studentId}` is a student id. In `/api/registrar/grades/{id}` it is the grade id.
Staff `grade` = `{ id, section_id, student_id, value, remarks|null, status ("draft"|"submitted"|"finalized"), updated_at, seq }`. Staff grade view = `{ grades:[grade], missing:[student_id] }` (`missing` = approved students without a grade row).
Grade history (`seq, at, action, grade_id, section_id, student_id, old_value, new_value, old_remarks, new_remarks, reason, actor_id`; remarks are staff-only) is stored server-side only. **No endpoint returns it**; the API exposes only the latest `seq` on each `grade` (used as `expected_seq` for corrections).

| Method & path | Roles | CSRF | Request | Success | Errors (in check order) |
|---|---|---|---|---|---|
| GET /api/faculty/classes/{id}/grades | faculty | no | - | 200 staff grade view | 403 no or inactive profile, not assigned, unknown section |
| PUT /api/faculty/classes/{id}/grades/{studentId} | faculty | yes | `value` string (required, must be on the scale), `remarks` string or null (at most 255, no control chars; `""` is stored as null) | 201 "Grade entered." / 200 "Grade updated." `{grade}` | 403 `Access denied.` own student record; 403 `Access denied.` no or inactive profile, not assigned, unknown section; **409 `errors.grade_scale`** (only when `APP_GRADE_SCALE` is `off`, the default); 422 unknown field, `value` (`This field is required.` / `Use a value from the grading scale.`, e.g. `3.25` or `inc` under the demo scale), `remarks` (`Use at most 255 characters without control characters.`); 404 `Student not found in this class.` (student not on the approved roster; checked in the service and again under the enrollment lock, so a student dropped mid-request also gets 404 and nothing is written); 409 `errors.status` `Submitted or finalized grades cannot be edited.` |
| POST /api/faculty/classes/{id}/grades/submit | faculty | yes | no body fields | 200 "Grades submitted." staff grade view | 403 not assigned; 409 `errors.grades_missing` (int[]); 409 `errors.status` (no drafts) |
| GET /api/registrar/sections/{id}/grades | registrar, admin | no | - | 200 staff grade view | 404 `Section not found.` |
| POST /api/registrar/sections/{id}/grades/finalize | registrar, admin | yes | no body fields | 200 "Grades finalized." staff grade view | 403 own student record in the section; 404; 403 assigned to the section, or entered/updated/submitted in it; 409 `errors.grades_missing` (int[], roster students without a submitted grade); 409 `errors.status` (nothing submitted) |
| POST /api/registrar/sections/{id}/grades/return | registrar, admin | yes | `reason` (required, 1-255, trimmed, no control chars) | 200 "Grades returned to draft." staff grade view | 422 `reason`; 404; 409 `errors.status` (nothing submitted) |
| POST /api/registrar/grades/{id}/corrections | registrar, admin | yes | `value` string (required, on the scale), `reason` (required, 1-255), `expected_seq` int of at least 1 (required; the `seq` you loaded) | 200 "Grade corrected." `{grade}` | **409 `errors.grade_scale`** (only when `APP_GRADE_SCALE` is `off`, the default); 422 field errors (including a value off the scale); 404 `Grade not found.`; 403 own student record / assigned / entrant; 409 `errors.status` (not finalized); **409 `errors.stale_grade`**; 409 `errors.value` (same as current) |
| GET /api/grades/me | student | no | query `term_id` only (any other key gives 422 `Unknown field.`) | 200 `{grades:[{section_id, term_id, course_code, section_code, value, grade_status, enrollment_status}]}`, finalized only, never remarks. No profile gives `{grades:[]}` | 422 |

## 4. Behaviours the UI must handle

| Situation | Response | UI action |
|---|---|---|
| Grading scale off (B-05, default `APP_GRADE_SCALE=off`) | 409, `errors.grade_scale = "Grade entry is not available yet."` on **every** grade entry (PUT) and correction until a scale is enabled (`demo` now; the official university scale is pending). Reads, submit, return and finalize still work. | Show "grade entry not available yet". Do not offer a value picker. |
| Value off the demo scale (`APP_GRADE_SCALE=demo`) | 422 `errors.value = "Use a value from the grading scale."` (for example `3.25`, `inc`). | Show the field error. The 12 demo codes are in §3.7; they are demo-only. |
| `remarks` | Present only in staff shapes (faculty and registrar grade views). Never in `/api/grades/me`. | Show only on staff screens. Never copy them into student views. |
| Correction concurrency | 409 `errors.stale_grade` when `expected_seq` is not the grade's latest `seq`. | Refetch `GET /api/registrar/sections/{section_id}/grades`, show the current value, and let the user retry with the new `seq`. |
| Missing grades | 409 `errors.grades_missing` = array of student ids (submit: no grade row; finalize: no submitted grade). | Highlight those students (match on `student_id` in the roster or grade view). |
| Enrollment closed | 409 `errors.section_id = "Enrollment is closed for this term."` on request. "Closed" means the term's `enrollment_open` is false, regardless of dates. | Disable the request button when `enrollment_open` is false and still handle the 409. |
| Duplicate request | 409 `errors.section_id` "already have a pending or approved request". | Show the message. |
| Section full | 409 `errors.section_id = "This section is full."` (request); 409 `errors.status` (approve). | Show `remaining` and handle the race. |
| Schedule clash | 409 `errors.section_id` "This section overlaps COURSE-SEC (section N) in your schedule." (pending and approved count); on approve, 409 `errors.status` "...overlapping time: ...". | Show the message. |
| Not eligible | 409 `errors.student_id` (request) / 409 `errors.status` (approve). Eligible means `academic_status = active` and the account is active with the `student` role. | Show the message. |
| Drop after enrollment closes | 409 `errors.status = "Enrollment is closed for this term."`; 409 `errors.status` for a rejected or already dropped row. | Hide "Drop" unless status is pending/approved and the term's `enrollment_open` is true. |
| Decision after close | Registrar approve/reject is **not** blocked by a closed term. | No client block. |
| Any 401 | Session expired or revoked. | Clear UI state, fetch a new CSRF token, go to login. |
| 403 `Invalid CSRF token.` | Token stale. | Refetch `/api/auth/csrf` and retry once. |
| 403 `Access denied.` | Role, assignment or separation-of-duties refusal. | Show "not permitted". Do not retry. |
| 404 on another user's record | Same response as a missing record by design. | Show "not found". |
| 429 | `Retry-After` seconds. | Show a countdown. Do not retry automatically. |
| Rendering | All server strings (`message`, `errors.*`, names, titles, rooms, reasons, remarks, department, audit `changes`) are untrusted text. | Render with `textContent` or framework text binding. **Never** use `innerHTML` or `v-html` with them. |

## 5. Endpoint count

| Module | Endpoints |
|---|---|
| Auth | 4 |
| Admin users + audit logs | 7 |
| Students / programs / academic records | 8 |
| Terms / sections / instructor assignments | 9 |
| Enrollments / schedule | 6 |
| Faculty profiles / classes / rosters | 7 |
| Grades | 8 |
| **Total** | **49** (plus unguarded `GET /` health) |

## 6. Doc/code mismatches

None known at `40182f3`. The ten mismatches listed at `7b5c94b` are resolved: faculty student visibility is now wired in code (`RosterFacultyVisibility`), and `README.md` and `ai-coordination/API_CONTRACTS.md` were synchronized to the code (dashboard row removed, faculty 404 on student profiles, registrar faculty rows, real instructor identity, `grade_scale` only on entry and corrections, eligibility, Student shape C-03, `enrollment_open` flag, roster 404 without a profile, `/me` 403 after losing the `student` role). If a doc and the code disagree again, the code wins; record the item here.

Not frontend-visible: `config/app.php` refuses to start on these repository combinations (no JSON fallback): any module on `mysql` while auth is `mock`; `APP_REPOSITORY_ENROLLMENT=mysql` without students **and** faculty on `mysql`; `APP_REPOSITORY_GRADES=mysql` without students **and** enrollment on `mysql`. MySQL is test-only (bootstrap gate: `APP_ENV=testing`, `ais_test`, `ais_test_app`, loopback host). A misconfigured server fails at startup, so the UI just sees the generic 500 or no server.

## 7. Frontend integration checklist

1. **CSRF bootstrap.** On app load call `GET /api/auth/csrf` and keep `data.csrf_token` in memory (not localStorage). Send it as `X-CSRF-Token` on every POST/PUT/PATCH/DELETE, login and logout included. Replace it with the `csrf_token` from the login response. After logout or any 401, fetch a new one. On 403 `Invalid CSRF token.` refetch and retry once only.
2. **Same origin and cookies.** Serve the UI from the same origin as the API (no CORS support). Use `fetch` with `credentials: 'same-origin'` (the default). Never read or set `AIS_SESSION` from JS (HttpOnly). Send `Content-Type: application/json` on every request with a body.
3. **Render errors as text.** `message`, every `errors` value and all record strings go through `textContent` or framework text binding. Never `innerHTML`/`v-html`. On 500 show a generic message; there is no detail to show.
4. **`errors` values are not always strings.** Treat each value as `string | number[]`. Today only `errors.grades_missing` is an array (student ids). Map keys to form fields; show keys with no matching field (`body`, `grade_scale`, `stale_grade`, `student_id`, `instructors`, unknown query keys) as a form-level message.
5. **409 `grade_scale`.** Expected on every grade entry (PUT) and correction while `APP_GRADE_SCALE` is `off` (default). Show "grade entry not available yet" and do not offer value input. With `demo`, expect 422 `value` for codes off the demo scale. Keep the grade views, submit, return and finalize working.
6. **409 `stale_grade`.** Refetch `GET /api/registrar/sections/{section_id}/grades`, show the current value, and let the user retry with the new `seq` as `expected_seq`.
7. **409 `grades_missing`.** Highlight the listed student ids in the roster/grade view (submit: no grade row; finalize: no submitted grade).
8. **Pagination.** Paged lists return `data.pagination = {page, per_page, total}`. Compute pages as `ceil(total / per_page)`. Keep `per_page` at most 100. A page past the end is an empty list, not an error, so step back a page after deletes or filter changes. Send only documented query keys: the audit log and `/api/grades/me` reject unknown keys with 422.
