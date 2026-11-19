# Desktop notifications with Web Push

PATH retains the existing SSE in-page notifications and adds optional desktop alerts. Each browser must explicitly opt in. No desktop application or cloud account is required, but Web Push uses the browser vendor's external push infrastructure. PHP uses `minishlink/web-push` for VAPID signatures and standard encrypted Web Push payloads.

## Enable and test

1. Open PATH at `http://localhost:8000` or `http://127.0.0.1:8000` for local development, or use a trusted HTTPS deployment. Plain `http://path-v3.test` is not a secure browser context and cannot enable Web Push.
2. Sign in, open the bell menu, and click **Enable desktop alerts**. Choose **Allow** in the browser prompt.
3. Click **Send test**. A separate Web Push worker sends the desktop alert. The page checks briefly whether the provider accepted it and listens for the service worker's receipt/display acknowledgement. Provider acceptance alone does not prove browser delivery. If nothing appears, use **Check desktop display**, which invokes the browser notification API directly without sending a push. If that also produces no alert, check Windows Settings > System > Notifications, Chrome/Edge notification banners, and Do Not Disturb. If local display works but Web Push does not, turn alerts off and enable them again, then check the browser/network's connectivity to its push service.
4. Close the PATH tab and have another authorized user update a proposal relevant to your account. Your own edits still do not notify you.

Keeping a login cookie is not what makes Web Push work: the browser retains a push subscription and can start a service worker to display an alert. Closing the site does not revoke that subscription. Delivery with all browser windows closed depends on the browser, operating system, background settings, internet connectivity, and notification permission; a fully exited browser is not guaranteed to receive immediately. Opening an alert still requires a valid PATH login. **Remember me** helps restore login when opening PATH again.

**Turn off desktop alerts** revokes the current browser subscription. Explicitly signing out revokes that browser's server-side subscription and pending jobs; other devices stay subscribed. A message already handed to a browser push service cannot be recalled, so all desktop messages contain only generic text, never proposal names, participant names, or other proposal details. Deleted users and notifications already marked read are skipped at send time. Enable again after signing back in if desired.

## Server setup

The new `web_push_subscriptions` and `web_push_deliveries` tables are created by `2026_09_17_000001_create_web_push_tables.php`.

```shell
php artisan migrate
php artisan webpush:keys
php artisan queue:work database --queue=notifications,default --sleep=2 --tries=5 --timeout=60
php artisan queue:work database --queue=webpush --sleep=2 --tries=5 --timeout=30
php artisan notifications:serve
```

On this Windows/Laragon checkout, `scripts/Start-NotificationWorkers.ps1` starts all three processes hidden, configures PHP's bundled OpenSSL configuration file, and writes logs under `storage/logs`. It does not register startup tasks. Run it after a reboot or configure Windows Task Scheduler. After deploying code, use `php artisan queue:restart`, wait for queue workers to exit, then run the script again. The SSE process is separately restarted with `-RestartStream` when its code changes.

For manual key generation/tests on this Windows PHP installation, set the OpenSSL configuration before starting PHP:

```powershell
$env:OPENSSL_CONF = Join-Path (Split-Path (Get-Command php.exe).Source) 'extras\ssl\openssl.cnf'
php artisan webpush:keys
```

The key command preserves existing keys and never prints them. Back up `storage/app/private/webpush-vapid.json` securely; it is ignored by source control and is outside the public directory. Changing these keys requires browsers to enable alerts again. Alternatively, set `WEB_PUSH_PUBLIC_KEY` and `WEB_PUSH_PRIVATE_KEY` in the server environment.

`WEB_PUSH_SUBJECT` must be an administrator `mailto:` address or a valid HTTPS contact URL. The development fallback is `https://path-v3.test`; replace it with the real contact before production. `WEB_PUSH_ENABLED=false` stops creating new push deliveries; stop the Web Push worker as well if pausing already queued deliveries. Restart workers after changing configuration. Keep the existing `APP_KEY` backed up too: it encrypts subscription secrets at rest.

The server and browsers need outbound HTTPS access to their push services. Accepted subscription endpoints are restricted to Google FCM, Mozilla's push domains, Microsoft's WNS domains, and Apple's Web Push endpoint. Redirects are disabled during delivery. No subscription endpoint is accepted as an arbitrary server-side request destination.

## Queues and delivery behavior

The notification row, per-device delivery rows, and database-backed Web Push jobs are created in a database transaction. Keep the database queue on the same application database for this atomic behavior. A separate `webpush` worker isolates outbound network delays from SSE notification processing. Each delivery retries up to five times with backoff; accepted deliveries are recorded and skipped if the job repeats. HTTP 404/410 responses delete expired subscriptions. Browser notification tags prevent multiple alerts with the same notification ID from accumulating if a crash occurs after provider acceptance but before recording success. Delivery is at-least-once, not exactly-once.

Push messages have a one-hour provider TTL. A successful send means the push service accepted it, not that Windows displayed it. In-page notifications and stored notification history remain available independently. Test alerts are rate-limited to three requests per minute. API calls require the normal authenticated session and CSRF protection. Explicit logout disables delivery on that browser even if its browser-side subscription still exists.

Inspect `storage/logs/webpush-queue.log`, `webpush-queue.err.log`, and `php artisan queue:failed` when investigating delivery failures. Error messages intentionally omit subscription capability URLs and cryptographic secrets. Retrying a failed job uses its existing delivery row and rechecks the subscription, user, and read status. Delivery records can be retained for troubleshooting; include a retention policy for this table in normal database maintenance.

## Verification

`php scripts/webpush-status.php` prints provider names and recent provider-acceptance timestamps without printing subscription URLs or signing keys. Browser acknowledgements are shown on the open page during **Send test**; they are not proof that Windows rendered a banner and are not stored as delivery receipts.

```shell
php artisan test --compact
node --test tests/js/notifications.test.cjs tests/js/webpush.test.cjs
```

Automated tests cover authenticated subscription ownership, endpoint/key validation, encrypted storage, logout revocation, deduplication, transient errors, expired subscriptions, real library encryption/signing against a mocked HTTP endpoint, browser permission handling, disable behavior, and service-worker display/click handling. A real provider-to-desktop test requires the user to grant browser notification permission and use **Send test**; automated backend acceptance is not a substitute for that check.
