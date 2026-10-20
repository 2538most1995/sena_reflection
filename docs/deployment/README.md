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
   VITE_APP_BASE_PATH=/sena_reflection ASSET_URL=https://krumost.com/sena_reflection npm run build
   ```

5. Deploy `laravel/config/app.php` with `asset_url` support. If config is cached,
   clear/rebuild it with Artisan after changing environment values.
6. Back up the database before any required additive migrations. Never run
   `migrate:fresh`, overwrite the database, or seed production during this step.

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
