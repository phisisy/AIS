# AIS backend — v0.9.0-preview

**Development preview; acceptance is conditional.** This is a source-only study snapshot from verified baseline `8e964c82046506cd71f44fbc026c101d8b7c7972`. It is not the final backend release or a production deployment. The Git history here is new and contains only this preview; it does not carry the acceptance branch's older commits.

## Architecture

The project is plain PHP (8.1 or newer), with no Composer dependencies. `public/index.php` and `public/router.php` receive HTTP requests; `routes/` maps them to controllers. `app/` contains the Core, Auth, Students, Enrollment and Faculty modules. Each module has services and repository interfaces, with file-backed mock implementations and MariaDB implementations. `config/app.php` selects repositories by module. Sessions, login throttling and security-event files remain in a private runtime directory.

`APP_REPOSITORY=mock` is the default for local study. MariaDB is behind a **testing-only** gate and is not a production setting. `database/database.sql` and `database/migrations/V0001`–`V0010` describe the 19-table MariaDB schema; `docs/database/ERD.md`, `DATA_DICTIONARY.md` and `ARCHITECTURE.md` explain its relationships and constraints. The database setup templates contain placeholders only. Do not run schema, migration, import or backup commands against a shared or accepted database.

## Local installation

1. Install PHP 8.1+ with the standard extensions used by the code. MariaDB access additionally needs PDO MySQL; the default mock mode does not need a database or Composer.
2. Choose a writable **private, absolute** runtime directory outside this checkout. Set `APP_RUNTIME_PATH` to it in your local shell. Keep its files, passwords and sessions out of Git and synced folders.
3. Set `APP_ENV=development` and `APP_REPOSITORY=mock` if your shell has inherited other settings. Leave `APP_GRADE_SCALE=off` unless you are deliberately exploring the fictional academic demonstration scale.
4. From the repository root, run `php -S 127.0.0.1:8000 public/router.php`. `GET http://127.0.0.1:8000/` should return the API health JSON.

For local fictional accounts, run `php database/seeders/dev_users.php`, then `php database/seeders/dev_students.php` and `php database/seeders/dev_sections.php` in the same environment. The first seeder prints random passwords once; store them privately and never commit or share them. A fresh runtime directory is best for repeated demos.

Apache users should point the document root at `public/`, enable `mod_rewrite` and `AllowOverride All`, and use `public/.htaccess`, which routes requests to `index.php` and disables error display. The root `.htaccess` protects source files if the repository root is exposed. `tests/Core/apache_check.php` is the optional manual Apache check.

## API study and tests

Start with [the API contract](docs/FRONTEND_API.md). Fetch `GET /api/auth/csrf` before a write, send the token as `X-CSRF-Token`, and keep the `AIS_SESSION` cookie. `POST /api/auth/login` accepts the fictional account credentials created locally. The [Postman collection](docs/demo/AIS_SYSTEM.postman_collection.json) has empty password variables; fill current values locally and do not export them.

Run the offline suite with `php tests/run.php`. MySQL integration tests require an explicitly provisioned, disposable `ais_test` instance and the test-only gate; they are not part of the quick start. The API contract and collection include historical source-status notes, so verify behavior against this exact preview when integrating a client.

## Preview limits

`database/demo_data.sql`, private evidence, dumps, runtime data, credentials, local `.env` files and recovery packages are deliberately absent. The demo generator source is included for architecture study; it must not be run against a shared database. Grade values under `APP_GRADE_SCALE=demo` are an academic demonstration policy, not an official university policy. L1/L2 security closure, independent cross-review, G2/G3 decisions and final release acceptance remain separate. The planned `v1.0.0-backend` release is gated on those decisions.
