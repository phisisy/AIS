# AIS backend

T-00 supplies the PHP HTTP core and offline test runner. PHP 8.1+ is required
(verified locally with XAMPP PHP 8.2.12). There are no Composer dependencies.
Module endpoints, authentication, sessions, and repositories are future tasks.
The only registered endpoint is `GET /`, returning:

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
- `APP_ENV`: descriptive environment name, default `development`.
- `APP_RUNTIME_PATH`: trusted private runtime directory, default
  `storage/runtime/`, outside the public directory and ignored by Git.
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

## Core interfaces

- `app/bootstrap.php` returns an explicit array container with `config`,
  `database_config`, and `router`; module route files return registration closures
  receiving `(Router $router, array $container)` and currently register nothing.
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
