# PATH v3 — DOST Central Visayas

PATH (Proposal Administration and Tracking Hub) manages proposals, funded projects, rental records, monitoring reports, and the public project directory.

## Set up and run locally

### 1. Prepare the requirements

- PHP **8.3 or newer**, available in your terminal.
- Composer.
- MySQL or MariaDB. Laragon is suitable for Windows development.
- PHP extensions required by the installed packages, including PDO MySQL, OpenSSL, mbstring, XML/DOM, GD, and ZIP. Composer checks the exact requirements.
- Internet access for dependency installation and external map tiles/fonts.

The application uses Blade templates and assets in `public/`. **An npm build is not required** for the local startup instructions below. Node.js is optional for JavaScript tests.

Start MySQL/MariaDB in Laragon, then open a terminal in the project directory:

```powershell
cd C:\laragon\www\path-v3
composer install
composer check-platform-reqs
```

Use `composer install` to honor `composer.lock`.

### 2. Configure the environment

For a new installation only, copy the example configuration. Do not overwrite an existing `.env`:

```powershell
Copy-Item .env.example .env
```

Edit `.env` to match your local database and URL:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8001
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=path
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Create an empty database named `path` using your database manager, or change `DB_DATABASE` to your chosen database. Use your own database credentials.

For local development without SMTP, set `MAIL_MAILER=log`. Mail will be written to `storage/logs/laravel.log`. To send real email, configure the `MAIL_*` settings with your SMTP provider.

For a **new installation**, generate the application encryption key:

```powershell
php artisan key:generate
```

When restoring an existing installation, retain its original `APP_KEY` and `.env`; encrypted data depends on that key.

### 3. Initialize the database

For a fresh database:

```powershell
php artisan migrate
php artisan db:seed
php artisan optimize:clear
```

The seeder creates the initial local administrator if the username does not already exist:

- Username: `admin`
- Password: `ChangeMe123!`

Change this starter password after signing in. Do not use the starter credentials on a publicly accessible installation.

For an existing PATH database, back it up, configure `.env` to use it, and run `php artisan migrate` instead of recreating it. Do not run `migrate:fresh` or `db:wipe` against data you need to keep.

If restoring a current-system SQL backup, import it into the intended database before running pending migrations. The file `database/path13.sql`, if supplied, is a database dump, not an automatic installation step. Confirm its source/schema before importing. **Legacy PATH dumps require a separate source database and the legacy merge workflow**; see [legacy migration instructions](docs/LEGACY_DATABASE_MIGRATION.txt).

### 4. Start the application

Run this in the project terminal and leave it open:

```powershell
php artisan serve --host=127.0.0.1 --port=8001
```

Open **http://127.0.0.1:8001**. The homepage is the public project directory. Select **Staff sign in** to enter the staff/admin portal. `/login` redirects to the homepage sign-in dialog.

If port 8001 is occupied, choose another port and update `APP_URL`. Use the same hostname consistently in your browser.

### 5. Start background notifications

For live in-page notifications, run these in **two additional terminals**, from the project directory:

```powershell
php artisan queue:work database --queue=notifications,default --sleep=2 --tries=5 --timeout=60
```

```powershell
php artisan notifications:serve
```

Keep these processes running while using notifications. The stream server normally listens on `127.0.0.1:8091`. With `artisan serve` on HTTP localhost/127.0.0.1, leave `NOTIFICATION_STREAM_URL` unset so the app can detect the development stream address.

For optional desktop alerts, generate VAPID keys once and start a separate delivery worker:

```powershell
php artisan webpush:keys
php artisan queue:work database --queue=webpush --sleep=2 --tries=5 --timeout=30
```

Then sign in, open the bell menu, and select **Enable desktop alerts**. Desktop alerts require localhost or trusted HTTPS and browser permission. See [Web Push setup](docs/WEB-PUSH.md).

Alternatively, Windows users can run `scripts/Start-NotificationWorkers.ps1`, which starts the notification workers and stream server in hidden processes and writes logs under `storage/logs`. Use either the helper or manually started processes to avoid duplicate listeners. The helper does not register automatic startup after reboot.

### Using Laragon Apache instead

Configure the virtual host document root as **`path-v3/public`**, set `APP_URL` to the host URL, and run `php artisan optimize:clear`. Never serve the repository root: it contains private environment and database files.

Apache requires a reverse proxy for `/notification-events`. See [notification configuration](docs/NOTIFICATIONS.md) and `scripts/Configure-NotificationApache.ps1`. The script modifies the local Apache configuration and reloads/restarts Apache. Plain HTTP custom hostnames such as `path-v3.test` do not support desktop Web Push; use localhost or trusted HTTPS for that feature.

## Main features

- **Proposal administration:** proposal/project codes, documents, evaluator assignments, comments, audit history, and spreadsheet bulk uploads.
- **Separate status tracking:** Proposal Status (Ongoing Evaluation, Approved, Disapproved); Funded Project Status (New, Ongoing, Stopped Payment, Completed, Graduated, Terminated, Withdrawn); and Funding Processing (Ongoing Processing, Cheque Released).
- **Funding workflow:** recording Date Funded sets the proposal to Approved and initializes its funded status as New. Funded Project Status is only applicable when Date Funded is recorded.
- **Rental Records:** payment schedules, receipts, editable schedule adjustments, temporary monthly-due changes, unexpended balances, applicable penalties, and per-project Excel export.
- **Monitoring:** RTEC, Rental, Refund Rate, Liquidation, and Annual Monitoring, with reporting filters and available exports.
- **Acquisition and liquidation:** approved equipment/technology and actual acquisition records. Purchased Amount counts as liquidated once Date Liquidated is populated; historical projects without acquisition records retain the legacy monitoring fallback.
- **Public directory:** funded proponents, search/filter controls, map locations, and public project highlights.
- **Administration:** users, roles/permissions, evaluator access, provinces, industry sectors, archives, and compliance records.

### Annual Monitoring

Open **Overview → Annual Monitoring**. The page includes funds released/refunded, project counts, funds to be settled, accumulated refund rates, provincial figures, funded-status percentages, sex disaggregated data, and projects with no refunds since rental start.

- **Reporting year** defaults to the current year. The current year uses today's cutoff; a past year uses December 31.
- **Date From / Date To** provide custom reporting controls. Accumulated figures include earlier balances through Date To; with only Date From selected, the cutoff is today. Date From does not remove opening balances or older funded projects from this accumulated report.
- **Target Average:** for each funded SETUP project with a started saved rental schedule, scheduled dues through the cutoff ÷ total project fund × 100; then take the arithmetic mean of those percentages.
- **Actual Average:** arithmetic mean of each included project's saved refunds through the cutoff ÷ project fund × 100. Penalties are excluded.
- **Funded Project Status** drives the status summary and pie chart. Status and sex reflect currently saved classifications, not historical snapshots.
- **Region/province filters** control the visual breakdowns; regional summary cards retain their labeled coverage.
- **Export Excel** downloads the report sections and calculation notes using the selected reporting options. It exports tabular values rather than a screenshot of the dashboard.

Projects without owner/contact sex appear under **Not recorded**. Returned-equipment values are not currently deducted from funds to be settled because those values are not recorded.

## Updating an existing installation

Back up the database, `.env`, and stored files before applying updates. From the project root:

```powershell
composer install
php artisan migrate
php artisan optimize:clear
php artisan queue:restart
```

Restart manually managed queue workers after they exit, and restart the notification stream process after code/configuration changes. Preserve application and VAPID keys. Changes to `.env` require configuration cache clearing and long-running process restarts.

## Checks and troubleshooting

```powershell
php artisan migrate:status
php artisan view:cache
php artisan test
```

Tests use the SQLite in-memory connection configured in `phpunit.xml`; enable PDO SQLite for testing. Node.js can run the browser-side unit tests:

```powershell
node --test tests/js/notifications.test.cjs tests/js/webpush.test.cjs
```

Known local verification limitation: the socket-based `NotificationStreamTest` has hung in this Windows environment. The rest of the PHP suite can be run separately while investigating it:

```powershell
php artisan test --filter='^(?!.*NotificationStreamTest).*$'
```

Common issues:

- **Missing application key:** generate a key only for a fresh installation; restore the original key for an existing installation.
- **Database/session/cache errors:** check database credentials, start MySQL, and run pending migrations.
- **Old configuration:** run `php artisan optimize:clear` and restart workers.
- **Live notifications disconnected:** ensure the stream server and notification worker are running; check the proxy when using Apache.
- **Desktop push errors:** check browser permission, secure context, the `webpush` worker, and [Web Push troubleshooting](docs/WEB-PUSH.md).
- **OpenSSL “Unable to create the key” on Windows:** point `OPENSSL_CONF` to the `extras/ssl/openssl.cnf` bundled with the PHP installation used by your terminal. The Windows worker helper handles this for its processes.
- **Write-permission errors:** the PHP process needs write access to `storage/` and `bootstrap/cache/`.

Application logs are in `storage/logs/`. Generated exports/import previews are temporary; stored uploads, keys, and database backups are not disposable cache files.

## Project structure

| Path | Purpose |
| --- | --- |
| `app/` | Controllers, models, services, requests, jobs, and commands |
| `config/` | Application configuration |
| `database/` | Migrations, seeders, and any separately supplied database dump |
| `resources/views/` | Blade templates |
| `public/` | Web entry point, CSS, JavaScript, images, and service worker |
| `routes/` | HTTP and console routes |
| `scripts/` | Windows startup/configuration and notification diagnostics |
| `storage/` | Private files, logs, generated exports, and runtime caches |
| `tests/` | PHP and JavaScript tests |
| `docs/` | Feature and operational documentation |

## Further documentation

- [Notifications and Apache proxy](docs/NOTIFICATIONS.md)
- [Desktop Web Push](docs/WEB-PUSH.md)
- [Public directory](docs/PUBLIC-DIRECTORY.md)
- [Acquisition and liquidation](docs/ACQUISITION-LIQUIDATION-INTEGRATION.md)
- [Roles and permissions](docs/ROLE-PERMISSIONS.md)
- [Evaluator data scoping](docs/EVALUATOR-DATA-SCOPING.md)
- [Required documents](docs/REQUIRED-DOCUMENTS-MANAGEMENT.md)
- [Legacy database migration](docs/LEGACY_DATABASE_MIGRATION.txt)
- [Third-party notices](docs/THIRD-PARTY-NOTICES.md)

Keep `.env`, database dumps, credentials, private keys, uploads, and generated runtime files out of public source distributions. Retain `composer.lock` for reproducible dependency installation.
