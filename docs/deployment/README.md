# Production mount: /sena_reflection

This deployment shares `krumost.com` with other applications. Do not change the
domain document root. Only deploy into `httpdocs/sena_reflection`.

Install this directory's `.htaccess` at the project root. The repository root
`index.php` now delegates to Laravel when its directory is present, preserving
the mounted request path. This directory's standalone `index.php` is an
equivalent minimal entry point for hosts that do not deploy the legacy shell.
Keep backups outside `httpdocs` and preserve the server's `.env`, student API
configuration, uploads and database.

## Runtime preparation

1. Run Composer **install**, using `laravel/composer.lock`, in `laravel/` with
   PHP 8.4.1 or newer. Do not use Composer update for deployment.
2. Preserve the existing DB credentials and APP_KEY in `laravel/.env`. Initialize
   APP_KEY only when empty; never rotate an established key as a deployment step.
3. Set the following public deployment configuration:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://krumost.com/sena_reflection
   ASSET_URL=https://krumost.com/sena_reflection
   SESSION_PATH=/sena_reflection
   SESSION_COOKIE=sena_reflection_session
   SESSION_SECURE_COOKIE=true
   VITE_APP_BASE_PATH=/sena_reflection
   PHP_CLI_BINARY=/opt/plesk/php/8.4/bin/php
   SENA_CHROME_BIN=/path/to/working/chrome
   SENA_PYTHON_BIN=/usr/bin/python3
   ```

4. Build the current frontend and deploy **all** of `laravel/public/build/`:

   ```sh
   cd laravel
   npm ci
   npm run build:production
   npm run check:production-build
   ```

5. Deploy `laravel/config/app.php` with `asset_url` support. If config is cached,
   clear/rebuild it with Artisan after changing environment values.
6. Back up the database before any required additive migrations. Never run
   `migrate:fresh`, overwrite the database, or seed production during this step.

## Document editor update: 2026-10-23

The **จัดเอกสารก่อนพิมพ์** record/draft routes now open the advanced editor
directly, seeded from the original Thai report layout. Deploy backend, migrations
and the complete production build together. Run `php artisan migrate --force`
after the database backup; the document migrations include private assets,
versions and the nullable `binding_key` / `binding_snapshot` columns.

The build must contain `resources/js/document-snapshot.tsx` in its Vite manifest
and the Thai font assets. Advanced snapshots use this same canvas for browser
printing and server Chrome PDF output. Preserve `laravel/storage/app/private/`
alongside public storage and legacy uploads; private document files must not be
served directly from a public directory. Restart the export worker after deploy.

Local browser/PDF tests use an isolated fixture database and do not verify that
the hosting dependencies reported below have been installed. Recheck Chrome,
Python `pypdf`, file permissions and the worker on the hosting account before
claiming server exports are available there. The user/admin manual is
[docs.md](../../docs.md).

## Verification performed on 2026-10-20

- Actual production `/sena_reflection/` redirected to `/sena_reflection/login`.
- Login and compiled JavaScript returned HTTP 200; the modern React login UI
  rendered in Chrome.
- One nonexistent deployment-check account received the normal invalid-login
  validation message, demonstrating a working database lookup without logging in.
- Unauthenticated legacy records API returned Unauthorized, not database details.
- `.env`, SQL and ZIP URLs returned HTTP 403.
- Original `.env` and legacy `index.php` were copied to the hosting account's
  `sena-private-backups` directory; the uploaded runtime ZIP was moved there too.
- No production database records, account passwords or uploads were changed.

## Follow-up production verification on 2026-10-20

- Exported the production MariaDB database to
  `sena-private-backups/krumostc_reflection_2026-10-20_19-03-42.sql.zip` before
  running additive migrations. The old database contained six legacy tables
  and no migration ledger. `artisan migrate --force --no-interaction` completed
  successfully; no reset, rollback or seeder was used.
- The authenticated district dashboard, existing record detail, document editor
  and student listing loaded. The student page reported its API available.
- Fixed document subprocesses invoking `php-fpm` instead of PHP CLI. Plesk
  `open_basedir` prevents metadata inspection of `/opt/plesk/php`; the app now
  validates the trusted configured executable by a direct CLI/version probe,
  without changing the hosting restrictions.
- The existing cached Chrome binary could not run because OS libraries were
  missing: `libnspr4.so`, `libnss3.so`, `libnssutil3.so`, `libsmime3.so`,
  `libatk-1.0.so.0`, `libatk-bridge-2.0.so.0`, `libxkbcommon.so.0`,
  `libasound.so.2`, `libgbm.so.1`, `libXcomposite.so.1`, `libXdamage.so.1`,
  `libXfixes.so.3`, `libXrandr.so.2`, `libatspi.so.0`.
- `/usr/bin/python3 -c 'import pypdf'` returned exit code 1. The hosting provider
  reported `ModuleNotFoundError: No module named 'pypdf'`. The hosting provider
  must make these Chrome dependencies and Python `pypdf` available to the site
  user. Keep Chrome sandbox protections enabled; do not use `--no-sandbox`.
- Background downloads also need a site-scoped queue worker after the renderer
  dependencies are ready, for example:

  ```sh
  /opt/plesk/php/8.4/bin/php /var/www/vhosts/krumost.com/httpdocs/sena_reflection/laravel/artisan queue:work exports --queue=exports --timeout=1800 --tries=1 --stop-when-empty
  ```

  Use a supervised worker or the hosting provider's scheduler; do not change
  any existing tasks belonging to other applications.

## Browser printing when the hosting renderer is unavailable

An authenticated `/records/{record}/browser-print` route now renders the same
saved document snapshot with embedded fonts/images, without server Chrome.
Normal browser navigation to `/records/{record}/print` automatically redirects
there only when the server document runtime is unavailable. The user can choose
**Print / Save as PDF**, select Save as PDF, use A4, and turn off browser headers
and footers. Printing resets screen-only zoom, transforms, margins and spacing.
Explicit PDF/API requests remain 503 rather than returning HTML as a fake PDF.

The browser route preserves the existing teacher/district authorization scope.
It is not a replacement for server-generated binary previews, merged downloads
or background exports: those still require the hosting dependencies above and
a queue worker. An anonymous sample rendered locally into a two-page A4 PDF;
both pages were visually checked for Thai typography and pagination. Generated
sample files and local settings are excluded from Git.

Server PDF output cannot be claimed ready until those dependencies are
installed and an actual generated PDF has been visually checked. Record
creation/update/delete and role-specific teacher actions were not performed on
real data as smoke tests. TypeScript, five frontend mount-path tests and the full
PHP regression suite (90 tests / 702 assertions) passed locally.

## Semester backups and SDL School integration

Deploy both migrations introduced on 2026-10-25: `create_semester_backups_table`
and `create_student_api_connections_table`, with the complete production build.
Keep the existing `APP_KEY`: it signs semester ZIPs and encrypts the SDL token.
Do not run `key:generate` on an existing installation. Preserve a separate secure
backup of the database, `.env`, uploads, and private/public storage before deployment.

The module needs PHP ZipArchive and writable private local storage. ZIPs are
limited to 512 MB total uncompressed data, with 64 MB of JSON and 20 MB per image.
The upload UI reports the lower effective limit of the module and PHP's
`upload_max_filesize` / `post_max_size`. Existing server-side backup entries can
be restored without uploading their ZIP again. Files live below
`storage/app/private/semester-backups`, behind authenticated district routes;
never expose that folder through a public alias or copy it into Git.

After deploying and migrating, a district administrator opens
**จัดการระบบ → ตั้งค่า Student API**, supplies an active SDL School token with
`student-data:read` and `sena-care:teachers`, and tests/saves the connection.
The legacy SENA Care key does not authenticate to the SDL integration endpoint.
On 2026-10-25, the narrowly scoped teachers endpoint and a separate Reflection
API client were installed on SDL School. Reflection's live connection test
reported term 2/2569 and 631 students. Existing integration clients were retained.
See the [SDL installation note](sdl-reflection-teachers/README.md) for the exact
endpoint patch, least-privilege setup, token expiry and revocation by client ID.
On 2026-10-26, the missing Reflection connection-settings migration completed
with PHP 8.4 through Plesk Run Now, without adding a recurring task. A new
non-expiring Reflection client 4 was created for Sena district with only the
two read abilities. Production's older middleware requires the narrowly scoped
non-expiring-client allowlist patch in that note; enable only client 4, retaining
the prior rejection of other non-expiring clients. The middleware and allowlist
config were deployed and matched the reviewed artifact hashes. Live verification,
saving, and verification after reloading with the encrypted stored token all
completed successfully: term 2/2569, 631 students. The new config loaded without
clearing config cache. Client 4 remains active with NULL expiry; trial client 3
was revoked after that verification, with only one guarded row changed. Clients
1 and 2 were untouched. The artifact suites pass 20 tests / 58 assertions using
isolated in-memory SQLite; see the installation note for the checks and rollback.
Plaintext credentials and token hashes are not stored in this repository.

Semester archive is an explicit user workflow, never a deployment task or a
scheduled purge. Create and download a verified backup, review blockers, then
type the displayed confirmation. Restore first checks identifiers and existing
weekly submissions and will not overwrite current work. The module leaves
private drafts, users, settings, and original shared image files in place. It
reduces rows in the active teaching-record graph; it does not reclaim all disk
space or replace a complete disaster-recovery backup. See [the user guide](../../docs.md).
