# Launchpad setup checklist

Everything you have to set up yourself before Launchpad runs in production. The code is finished; this is the operations side. Tick items off as you go. Details and reasons live in `superpowers/HANDOFF.md` (production checklist) and `../api/README.md` (settings and endpoints).

Local development is separate: see "How to run everything" in `superpowers/HANDOFF.md`.

## 1. Hosts and domains

- [ ] Pick the public https URLs for the web app and the API (for example `app.example.com` and `api.example.com`).
- [ ] TLS certificates for both, plus one for the websocket (Reverb) endpoint.
- [ ] A load balancer or reverse proxy in front of the API. Note its addresses for `TRUSTED_PROXIES`.
- [ ] The web host must serve `/unsubscribe` and forward its signed query string unchanged to `POST /api/v1/email/unsubscribe`. It posts only on an explicit click and sends `Referrer-Policy: no-referrer`.

## 2. API environment (`api/.env`, never committed)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `APP_KEY`: generate with `php artisan key:generate`.
- [ ] `LAUNCHPAD_HMAC_KEY`: at least 32 characters, for example `openssl rand -hex 32`.
- [ ] `APP_URL` and `WEB_URL`: public **https** URLs. If they are not, every optional email fails as unrenderable.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] `SESSION_DOMAIN=.example.com` when web and API are sibling subdomains.
- [ ] `SANCTUM_STATEFUL_DOMAINS`: the web host (for example `app.example.com`).
- [ ] `TRUSTED_PROXIES`: the load balancer's addresses. `*` and `/0` ranges are refused.
- [ ] `QUEUE_CONNECTION=database` or `redis`, never `sync`.
- [ ] `CACHE_STORE=redis` (scheduler locks and the settings cache).
- [ ] Keep the keys safe. Rotating `APP_KEY` or `LAUNCHPAD_HMAC_KEY` later invalidates stored hashes and resets anti-cheat history, so do it only on purpose.

## 3. Database (PostgreSQL)

- [ ] PostgreSQL 18 (or the version you test on), with a database and an application role.
- [ ] The role may create the trusted extensions `unaccent` and `pg_trgm`.
- [ ] Run `php artisan migrate --force --seed` (the seeders add topics and settings, no users).
- [ ] Big-table migrations (`products` search columns, staff-list indexes, `comments`) take locks. Run them in a maintenance window, or create those indexes first with `CREATE INDEX CONCURRENTLY` under the same names.
- [ ] The role that runs `launchpad:purge-retention` must keep `UPDATE` on `audit_logs` (the purge empties `ip_hash` and `user_agent`). The application role may have it revoked (see `../api/README.md`).
- [ ] Backups and a restore test.

## 4. Redis

- [ ] A Redis instance for cache, queue (if used) and view counters.
- [ ] `REDIS_VIEWS_DB`: a database number **not shared** with cache or queue.
- [ ] Never use an `allkeys-lru` eviction policy on the views database.
- [ ] Persistence (AOF or RDB) if losing up to a minute of view counts matters to you.

## 5. Background processes (nothing works without these)

- [ ] **Queue worker**, supervised (systemd, Supervisor or a container service): `php artisan queue:work`. Run `php artisan queue:restart` on every deploy. It sends mail and notifications, broadcasts, builds exports and cleans up votes and comments.
- [ ] **Scheduler**: cron `* * * * * cd /path && php artisan schedule:run` (keep the log), or `php artisan schedule:work`. It runs the 14 scheduled commands (publish launches, hot scores, freeze days, mail outbox, view flush, rank snapshots, retention purge and more; the full table is in `superpowers/HANDOFF.md`).
- [ ] The Compose file has neither a worker nor a scheduler service. Add them yourself if you deploy with Compose.

## 6. Realtime (Laravel Reverb)

- [ ] `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`: real random values. The app refuses to serve with blank ones.
- [ ] `REVERB_ALLOWED_ORIGINS`: exactly the web origin (no partial wildcards).
- [ ] Put TLS in front of Reverb and keep its port bound to loopback behind the proxy.
- [ ] `BROADCAST_CONNECTION=reverb`.
- [ ] If you ever add fields or items to the top-100 `rank.updated` frame (about 9.3 KB now), raise `REVERB_MAX_REQUEST_SIZE` above its 10,000 limit first.
- [ ] Web build values: `VITE_REVERB_APP_KEY` (same as the API key; empty turns live updates off), `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`.

## 7. Email

- [ ] A real mail provider: set `MAIL_MAILER`, `MAIL_HOST`/credentials as the provider requires, `MAIL_FROM_ADDRESS`, and `MAIL_TIMEOUT=20`.
- [ ] Add SPF, DKIM and DMARC DNS records for the sending domain.
- [ ] Send yourself the three auth mails (verify email, reset password, change email) and one notification mail as a test.

## 8. File storage

- [ ] **Exports**: a **private** S3-compatible bucket on the `exports` disk (no public URL), and PHP `ext-zip` installed.
- [ ] **Avatars and product media**: choose and configure `AVATAR_DISK` and `PRODUCT_DISK` (public disk with `php artisan storage:link`, or a public bucket or CDN).
- [ ] Set the bucket credentials and region in `.env`.

## 9. Google sign-in (optional but in the brief)

- [ ] Create an OAuth client in the Google console.
- [ ] `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (the callback is `/api/v1/auth/google/callback` on the API host).
- [ ] `GOOGLE_MOBILE_CLIENT_IDS`: comma-separated iOS/Android client ids when the phone app exists.

## 10. Web app build and hosting

- [ ] `VITE_API_URL`: the public API URL, then `npm run build` in `web/`.
- [ ] Host the built `web/dist` as a static site with SPA fallback (every path serves `index.html`) and https.
- [ ] Serve `/unsubscribe` (see section 1).

## 11. First launch

- [ ] Create the first admin: `php artisan launchpad:make-admin you@example.com` (the only way to create an admin; the account must already exist and have a confirmed email, so sign up and verify first).
- [ ] Review the admin settings screen: launch limit per day, retention windows, anti-cheat weights, digest send times.
- [ ] Optionally set `retention.rank_snapshots_days` (default 180, range 7 to 365); an existing database keeps its stored value.
- [ ] Smoke test in a browser: sign up, verify email, submit a product, approve it as admin, vote from a second account, and watch the live count and the bell.

## 12. Monitoring (not built in, you add it)

- [ ] Alert on queue depth, failed jobs and the age of the oldest unsent email in the outbox.
- [ ] Alert if the scheduler stops (no recent `schedule:run`), and on a large open risk queue.
- [ ] Application error tracking and log shipping.

## 13. Housekeeping

- [ ] Retire the deprecated `VoidDeletedMemberVotes` job class once `SELECT count(*) FROM jobs WHERE payload LIKE '%VoidDeletedMemberVotes%'` (and the same on `failed_jobs`) returns 0.
- [ ] Phone app later: it must send its own `User-Agent` (the default `okhttp` one is on the bot list, so its views would not count).
- [ ] Optional: a CDN is limited by the device cookie on public reads; see the open gaps in `superpowers/HANDOFF.md` before adding one.
