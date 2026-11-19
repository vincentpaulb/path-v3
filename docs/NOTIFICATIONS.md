# Browser notifications without additional software

PATH's in-page notifications use its existing Laravel database queue and a native PHP Server-Sent Events (SSE) process. This SSE path needs no cloud accounts, Redis, Node, or browser push services. Notifications work in open browser tabs, including background tabs while the browser permits execution. Closed or suspended browsers catch up when they reconnect. The optional [desktop Web Push feature](WEB-PUSH.md) separately uses a PHP library, browser-vendor push services, and a dedicated delivery worker.

## Flow

1. Proposal activity enqueues a small `DeliverProposalActivity` event job after the database transaction commits.
2. One queue worker resolves authorized recipients, saves notifications, and writes a per-user revision file under `storage/framework/notification-signals`.
3. A separate PHP socket process checks revisions once a second, once per connected user, without database queries. It multiplexes browser connections with `stream_select`; it does not allocate an application request worker per stream.
4. The browser receives an invalidation and requests its authorized notification summary. Bursts are coalesced. Sound, popups, and the existing notification menu remain.

Jobs have deterministic per-event/per-recipient notification IDs, five attempts, and increasing retry delays. Retrying after a partial delivery does not duplicate stored notifications. Archived/deleted proposals and deleted actors are skipped; recipient access is evaluated when the job runs. ActivityService reports queue enqueue failures in the application log, so monitor that log as well as failed jobs.

The database still handles queue reads/writes, notification storage, page rendering, and summaries after changes. One idle queue worker checks approximately every two seconds, independent of the number of browsers. Expected delivery is usually within a few seconds, depending on queue load. This is reduced database traffic, not zero server load.

## Run

Ensure the existing migrations have run. Start these two long-running PHP processes from the project directory:

```shell
php artisan queue:work database --queue=notifications,default --sleep=2 --tries=5 --timeout=60 --max-time=3600
php artisan notifications:serve
```

Use the operating system's existing process management to start/restart both on boot and on failure. The worker exits after one hour and must be restarted by that manager; omit `--max-time` for manual local development. On Windows, run them in separate terminals or use Task Scheduler. Restart both after code/configuration changes. PHP on Windows lacks PCNTL job timeout enforcement, so monitor stuck workers. Inspect `php artisan queue:failed` and retry specific failed IDs after fixing the cause.

With `php artisan serve` on HTTP localhost or 127.0.0.1, the application automatically connects the browser directly to the local PHP daemon port. Keep `NOTIFICATION_STREAM_URL` unset/blank for this detection. Signed tickets bind the allowed browser origin, and the application permits that stream address in its Content Security Policy. For Apache/nginx, HTTPS, or LAN access, use the reverse proxy below.

For this Laragon checkout, `scripts/Configure-NotificationApache.ps1` backs up and configures the existing `path-v3.test` Apache virtual host, enables its bundled proxy modules, validates the configuration, and reloads Apache. If Apache runs directly under Laragon without a Windows service, it verifies that installation's launch arguments and restarts its processes; this briefly interrupts local Apache sites. It writes outside the project and requires the corresponding operating-system permission. `scripts/Start-NotificationWorkers.ps1` starts both PHP processes hidden and writes logs under `storage/logs`. These scripts do not install software or register startup tasks. Run the worker script again after a reboot, or configure it in Windows Task Scheduler. Laragon can regenerate `auto.*.conf` virtual hosts; reapply the proxy configuration if that happens.

## Existing web-server configuration

The browser uses `/notification-events` on the same origin as PATH. Proxy that exact path to `http://127.0.0.1:8091/notification-events`, before any front-controller rewrite. Keep the PHP socket listener bound to loopback; TLS stays at the existing web server. Do not cache, compress, or buffer this stream, and omit query strings from access logs for this path because they contain short-lived tickets.

Apache requires its bundled `mod_proxy` and `mod_proxy_http` modules (enable them; no software installation). Add to the PATH virtual host:

```apache
ProxyPass "/notification-events" "http://127.0.0.1:8091/notification-events" connectiontimeout=5 timeout=330
ProxyPassReverse "/notification-events" "http://127.0.0.1:8091/notification-events"
SetEnvIf Request_URI "^/notification-events$" no-gzip=1
```

If using nginx instead, add:

```nginx
location = /notification-events {
    proxy_pass http://127.0.0.1:8091;
    proxy_http_version 1.1;
    proxy_buffering off;
    proxy_cache off;
    gzip off;
    proxy_read_timeout 330s;
    access_log off;
}
```

`NOTIFICATION_LISTEN` changes the daemon binding. `NOTIFICATION_STREAM_URL` changes the public same-origin path; any proxy must map it to the daemon's `/notification-events` path. Serving under a subdirectory requires the matching public path setting.

## Security and limits

An authenticated, CSRF-protected endpoint issues HMAC-signed tickets valid for five minutes. Streams close at expiry; browsers reauthenticate with backoff and jitter. Streams only contain random revision identifiers, never proposal content. Actual summaries use the normal session and user-scoped queries. Logged-out sessions cannot fetch summaries; an already-open stream can receive revision signals until its ticket expires. Marking notifications read also signals other tabs.

The implementation supports one application host with shared local storage between PHP workers and the stream command. It caps stream clients at 200, bounds request/output buffers, times out incomplete handshakes, and disconnects slow clients. Browser HTTP/1 connection limits can constrain multiple open tabs; use HTTP/2 at the proxy when available. Apache also consumes connection/thread resources for proxied clients, so size its limits appropriately. This is not an unlimited-scale broker.

On reconnect the browser fetches the current summary, recovering missed changes without a persistent SSE event log. The menu shows the latest six entries; older entries remain on the full notifications page. If the stream is unavailable, clients back off and the menu can still be refreshed by opening it. There is no periodic notification-table polling fallback.

## Verification

```shell
php artisan test --compact
node --check public/js/path-notifications.js
node --test tests/js/notifications.test.cjs
php scripts/check-notification-stream.php http://path-v3.test
php scripts/check-notification-stream.php http://localhost:8000 --development
```

With both processes and the proxy running, open PATH as two authorized users. Have one update a proposal: the recipient's badge/menu/popup should update without reloading. Mark all read in another tab and confirm the badge clears. Restart the stream command and confirm reconnection recovers updates. Browser Network should show one pending event stream per tab and summary requests on changes/reconnect/menu opening, rather than every 30 seconds.
