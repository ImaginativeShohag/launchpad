# Launchpad API

Laravel 13 JSON API (`/api/v1`) for the Launchpad web app and the future phone app.

- **Going live? Follow the [setup checklist](../docs/SETUP-CHECKLIST.md)** (everything you must configure yourself).
- Design: `../docs/superpowers/specs/2026-10-15-foundation-accounts-design.md`
- Schema: `../docs/database/launchpad.dbml`; decisions: `../docs/database/DECISIONS.md`
- `../docs/database/launchpad.html` is a viewer generated from the DBML: it embeds a copy of `launchpad.dbml` (the `<script type="text/plain" id="dbml-source">` block) that it shows when opened as a `file://` page, and it fetches the live `.dbml` only when served over http. It has no build script, so **after every change to `launchpad.dbml` regenerate the embedded copy** from the repo root: `python3 -I -c "import re,pathlib; h=pathlib.Path('docs/database/launchpad.html'); d=pathlib.Path('docs/database/launchpad.dbml').read_text(); assert '</script' not in d; h.write_text(re.sub(r'(<script type=\"text/plain\" id=\"dbml-source\">\n).*?(</script>)', lambda m: m.group(1)+d+m.group(2), h.read_text(), count=1, flags=re.S))"` (no network or extra tool needed), then commit both files.

## Run it

    ./vendor/bin/sail up -d
    ./vendor/bin/sail artisan migrate --seed      # an existing dev database predating the timestamptz change on personal_access_tokens needs migrate:fresh
    ./vendor/bin/sail artisan storage:link --force
    ./vendor/bin/sail artisan queue:work          # sends queued emails (view them at http://localhost:8025)
    ./vendor/bin/sail artisan launchpad:make-admin you@example.com   # the only way to create an admin

Run PHP, Artisan, Pest, Pint and Larastan inside the container (`docker compose exec -T laravel.test ...`), never on the host.
`storage:link --force` must run inside the container: a symlink created on the host dangles inside it.

## Quality

    ./vendor/bin/sail test            # Pest, on PostgreSQL (`testing` database)
    ./vendor/bin/sail composer lint   # Pint + Larastan level 8
    ./vendor/bin/sail composer format

Never run two test suites at once; they share the `testing` database.

For sample launches on every day of the current month (selected in Asia/Dhaka), run from `api/`:

    docker compose exec -T laravel.test php artisan db:seed --class=MonthlyDemoSeeder

This local/testing-only seeder adds 24 passwordless demo members and ten products per day. Days that have begun in UTC get live launches, votes, comments, hourly view counts and past-day rankings; future days are scheduled with no activity. Reruns preserve existing products and skip previously seeded slugs. Model events are muted, so seeding sends no mail or broadcasts. The normal ranking backfill also catches up any other unranked launches in this month. Future launches need the scheduler to publish them; this seeder does not simulate future activity. Logos use the web app's fallback; no media files are generated. The default `db:seed` continues to seed only settings and topics.

## Key facts

- Web app: session cookie + CSRF (`GET /sanctum/csrf-cookie`, then send `X-XSRF-TOKEN`). Phone app: `POST /api/v1/auth/token`.
- Email links open the web app; the web app forwards the link's query string to the API (`POST /auth/email/verify?id=...&hash=...&expires=...&signature=...`, same for email-change confirm/cancel).
- Google browser flow: `GET /api/v1/auth/google/redirect`. Mobile: `POST /api/v1/auth/google/token`.
- All IDs are ULIDs; members are addressed by `handle`.
- `UserResource` (the `/me` shape) omits `links` unless the relation is loaded. Only `/me` and the avatar endpoints load it; the public profile returns them through its own resource.
- Admins exist only through `launchpad:make-admin` (an existing, confirmed member). The role endpoint can set `member` or `reviewer` only and refuses to change an admin, so an admin cannot be demoted through the API.

## Production checklist

### Environment

| Variable | Production value |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` (with debug on, exception class and file details leak into error bodies) |
| `APP_KEY` | Random, never reused between environments |
| `LAUNCHPAD_HMAC_KEY` | `openssl rand -hex 32`, required, at least 32 characters |
| `SESSION_SECURE_COOKIE` | `true`. The `lp_did` device cookie follows it, and falls back to the request scheme when it is unset |
| `SESSION_DOMAIN` | Empty (host-only) in development. On a split domain (`app.example.com` + `api.example.com`) set the shared parent, for example `.example.com` |
| `SANCTUM_STATEFUL_DOMAINS`, `WEB_URL` | The web app's host and origin (CORS allows only `WEB_URL`, with credentials) |
| `TRUSTED_PROXIES` | Comma-separated IPs/CIDRs of your load balancer. Default is to trust nothing; `*` is ignored on purpose |
| `QUEUE_CONNECTION` | `database` or `redis`, never `sync` |
| `HASH_DRIVER` | `argon2id` |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | From the Google console |
| `GOOGLE_MOBILE_CLIENT_IDS` | Comma-separated OAuth client ids of the iOS/Android apps |
| `AVATAR_DISK` | `public`, or `s3` (see Avatars) |
| `BROADCAST_CONNECTION`, `REVERB_APP_ID/KEY/SECRET` | Key and secret are required: the app refuses to serve in production with `BROADCAST_CONNECTION=reverb` and a blank key or secret. With the `log` or `null` driver `/broadcasting/auth` answers 503 instead of an empty 200 |

### Queue worker (required)

Verification, reset and email-change mail is queued. Run `php artisan queue:work` under a supervisor, or no mail is sent.
The queue must be a real async driver. Forgot-password defers its account-dependent work to a job so every request costs the same;
under `sync` the request time reveals whether an account exists (argon2id makes the extra hashing work easy to measure).
Notifications retry 5 times; the reset job's first backoff is 65 s because the password broker throttles each user for 60 s.

### APP_KEY rotation

The reset job and the two email-change notifications implement `ShouldBeEncrypted`, so the new address and reset data are not stored in plaintext in `jobs`.
After rotating `APP_KEY`, keep the old key in `APP_PREVIOUS_KEYS` until the queue has drained, otherwise those jobs fail permanently.
Also note that a failed lookup can put SQL bindings (an email) into `failed_jobs.exception` and the logs; restrict access to both.

### LAUNCHPAD_HMAC_KEY rotation

IPs and device ids are stored only as HMACs. Rotating the key invalidates every stored `ip_hash` and `device_hash` (old rows no longer match new ones), which resets anti-cheat history. Rotate deliberately.

### trustProxies

Without `TRUSTED_PROXIES`, behind a load balancer every request appears to come from the proxy: all IP-keyed rate limits share one bucket and all `ip_hash` values are identical. List the real proxy CIDRs. Never trust everything: any client could then spoof its IP and sidestep the limits.

### Production database role

The immutability triggers block UPDATE, DELETE (row triggers) and TRUNCATE (statement trigger `audit_logs_no_truncate`) on `audit_logs` for everyone, but the table owner can still drop the triggers or disable them.
Run, as the DB owner, for the role the app connects as (here `launchpad_app`):

    REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM launchpad_app;
    -- the app role must not own the table or the trigger function, and needs no ALTER/DROP rights

### Avatars and storage

Avatars are re-encoded to 512 px WebP (limits: 2 MB upload, 4000 px per side, 8 megapixels, checked from the header before decoding). With `AVATAR_DISK=s3` the disk needs `'visibility' => 'public'` and an https `APP_URL`, or the stored URLs will not load.

### Settings cache

Admin settings are cached per key for 10 minutes and the cache is cleared after each committed change. If the cache store is down at that moment the change still succeeds, and readers can see the old value for up to 10 minutes (the entry then expires by itself). `Settings::all()` makes one cache round trip per key.
After changing a `SettingKey::default()` in code, run `php artisan cache:clear`, or cached defaults linger up to 10 minutes.
`Settings::set` locks the row it edits, but two admins creating the same missing key at the same moment can still hit a primary-key violation (the seeder normally creates every key, so this is theoretical).

### Reverb (live updates)

Reverb is the websocket server (Laravel Reverb). The app never talks to browsers directly: it publishes an event to Reverb over HTTP, and Reverb pushes it to every connected socket.

**Starting it.** In Sail, `compose.yaml` has a `reverb` service (the image `laravel.test` builds, running `php artisan reverb:start`), so `sail up -d` starts it. It is published on **loopback only** (`127.0.0.1:${REVERB_PORT:-8080}`), so other machines on the network cannot reach it. To expose it, put a TLS reverse proxy in front (for example `wss://ws.example.com` proxying to `127.0.0.1:8080`, or the proxy on the Docker network) rather than publishing the port on `0.0.0.0`. The service refuses to start (exit 1, `REVERB_APP_ID/KEY/SECRET must be set`) when the id, key or secret is blank: the production guard below only runs for web requests, not console processes. Outside Sail run `php artisan reverb:start`. Browsers connect to `ws://localhost:8080/app/<REVERB_APP_KEY>`.

| Variable | Meaning |
|---|---|
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Where the **app publishes** (server to server). Inside Sail the app container reaches Reverb at `REVERB_HOST=reverb`; outside Sail use `localhost`. Browsers use their own host and port from the web app's config |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | What the Reverb process listens on inside its container (default `0.0.0.0:8080`; read by `reverb:start`). The compose port mapping targets container port 8080, so if you change `REVERB_SERVER_PORT` also change the target port in `compose.yaml`; `REVERB_PORT` is only the host side of the mapping |
| `REVERB_ALLOWED_ORIGINS` | Web app origins allowed to open a socket (see below) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Generate real values per environment (`openssl rand -hex 32` for key and secret). Keep them blank in `.env.example`; an empty secret still signs, so a blank value is forgeable, and production refuses to serve web requests with `BROADCAST_CONNECTION=reverb` and a blank key or secret (console processes skip that guard; the compose service has its own blank check) |

**Allowed origins.** `REVERB_ALLOWED_ORIGINS` is a comma-separated list (default `http://localhost:5173`). Reverb compares the **host** of the browser's `Origin` header only (scheme and port are ignored) with `Str::is`, so any `*` is a wildcard. `config/reverb.php` reduces every entry to its host (`https://app.example.com:5173` and `app.example.com` mean the same; URL userinfo is ignored, so `http://localhost@evil.com` becomes the host `evil.com` and can never match `localhost`) and keeps only `localhost` or a host of at least two DNS labels with at most one leading `*.` (`*.example.com`). Everything else is dropped: `*`, `**`, `*.*`, `*.com`, `a*`, `*.example.*`, IP literals, blank entries. A list that ends up empty falls back to the default, so a typo cannot open the socket to every site (a `*.example.com` entry still admits every subdomain of that domain, by design). The check applies to the websocket handshake only; server-to-server publishing is signed with the key and secret.

**Queue worker (required).** Broadcasts are queued: the vote answers first, and the `BroadcastVoteCount` listener runs on the queue. Without a running worker (`php artisan queue:work`) no live update is ever sent. Production needs an async queue driver plus a worker, as for mail. With `QUEUE_CONNECTION=sync` the broadcast runs inside the request; it still cannot fail the vote.

**Failure behaviour.** A broadcaster or Reverb outage never fails or rolls back a vote: the listener catches the error, `report()`s it and carries on; the next vote announces the then-current count. With an async queue the actual HTTP call to Reverb is a second queued job (`VoteCountUpdated`, one try): while Reverb is down those jobs land in `failed_jobs` (prune them with `queue:prune-failed`) and no request is affected.

**Channels and events.**

| Channel | Event | Payload | Sent when |
|---|---|---|---|
| `product.{id}` (public, the product's ULID) | `vote.count` | `{"product_id": "...", "votes_count": 12}` | a vote is cast, withdrawn or voided |
| `today` (public) | `rank.updated` | `{"date": "2026-10-21", "items": [{"product_id": "...", "rank": 1, "votes_count": 12}, ...]}` (top 100) | the recompute changed at least one score |
| `private-user.{id}` (private, the member only) | `notification.count` | `{"unread_count": 3, "notification_id": "<uuid or null>"}` (the count stops at 100; `notification_id` is the newest unread row) | a notification was recorded, or notifications were marked read (so a second tab clears its badge); authorised by `POST /api/v1/broadcasting/auth` |

Two near-simultaneous votes can publish out of order (each broadcast carries the count read when its job ran), so the frame is a hint: clients re-read the product through the API when the exact number matters.

`product.{id}` is public: anyone who knows the id can listen, so the payload is exactly the product id and the count, never a voter, a user id or anything else. The count is read again from the database when the listener runs (not taken from the event), so a late delivery still carries the current number. A product that is not publicly visible (taken down, soft-deleted, not live yet, or by a deleted maker) broadcasts nothing, so the public channel cannot reveal a hidden product's count. Clients should treat the broadcast as a hint and use `votes_count` from the API for the first paint.

`today` is public and its `rank.updated` frame is a hint, like `vote.count`: it lists the current top 100 launches of the day (id, rank, votes count; no scores, names or member data), read again from the database when the queued `BroadcastRankUpdate` listener runs, and published inside that same job (`ShouldBroadcastNow`, no second queued job, so frames cannot arrive out of order). The listener is unique per day while pending (30 s lock, `ShouldBeUniqueUntilProcessing`), so a worker outage does not replay a backlog of identical frames, and has a 10 s timeout. Only publicly visible products are listed. A stale event (its day is no longer today in UTC) and an empty day broadcast nothing. A failing publish (Reverb down, oversized payload) fails and is reported in the listener job only, never the recompute: `launchpad:recompute-hot-scores` still exits 0.

Limits of the hint: a new day announces itself only after the first score change of that day, and taking a product down or hiding it changes no score, so `today` can keep listing a hidden id (ids and counts only) until the next change. Clients should re-read `GET /today` when the date changes, periodically, and whenever exactness matters.

**Payload size.** Reverb rejects a publish whose HTTP request is larger than `max_request_size` (`REVERB_MAX_REQUEST_SIZE`, default 10,000 bytes; `max_message_size` only limits inbound websocket frames). The top 100 are about 8.8 KB of body (the Pusher envelope escapes the JSON) and about 9.3 KB as a request with headers, so only about 700 bytes of headroom remain: do not add fields to the items or raise the item count without raising `REVERB_MAX_REQUEST_SIZE`. A too-large publish fails loudly (a 4xx, reported as a `BroadcastException`).

`routes/channels.php` authorises the private `user.{id}` channel, and `POST /api/v1/broadcasting/auth` is a normal API route (it validates the channel name strictly, requires the `private-` prefix, and uses its own limiter).

## Web app contract

### CSRF flow

1. `GET /sanctum/csrf-cookie` (sets `XSRF-TOKEN`).
2. Send every stateful `POST/PATCH/PUT/DELETE` with `X-XSRF-TOKEN` set to the URL-decoded cookie value, plus `Origin`/`Referer` of the web app, with credentials. A stateful request without the header gets `419`.

### Emailed links

The links are signed with `signed:relative`, which is order-sensitive. The web app must forward the emailed query string verbatim (do not re-serialise or sort it).
The pages that receive a link (`/verify-email`, `/reset-password`, `/confirm-email-change`, `/cancel-email-change`) must:

- serve `Referrer-Policy: no-referrer`, load no third-party scripts or analytics,
- call the API immediately, then strip the query string with `history.replaceState`,
- for reset: tell the user to wait a minute before asking again (a repeat within 60 s returns 202 but sends no mail).

### Google sign-in

Browser callback redirects to `WEB_URL`: `/` on success, `/signin?error=<code>` on failure, with one of:

| Code | Meaning |
|---|---|
| `google_email_unverified` | Google does not vouch for the email; nothing is linked or created |
| `account_unavailable` | Suspended or deleted account |
| `google_identity_mismatch` | The account is already bound to a different Google identity |
| `google_failed` | Cancelled consent, bad state, or any Google error |

Mobile `POST /auth/google/token` (`id_token`, `device_name`):

| Status | Body code |
|---|---|
| 422 | invalid token (validation error) |
| 409 | `google_email_unverified` or `google_identity_mismatch` |
| 403 | `account_unavailable` |
| 503 | `google_unavailable` (Google keys could not be fetched; retry) |

The ID token's issuer, audience (every id in `GOOGLE_MOBILE_CLIENT_IDS`) and expiry are verified. There is no nonce or replay binding: a stolen token is valid for about an hour.
Accounts link by email only when Google says `email_verified` is true; an account that already has a Google identity never gets a second one; linking to an account whose email was unverified wipes its password, sessions, tokens and reset tokens. Real Google (consent screen, `state`, live token verification) cannot be tested in CI.

## Rate limits

`throttle:api` (60/min per user, or per IP for guests) wraps every API route; the named limiter on a route applies in addition, so only a lower one can bite. The `api` limiter runs before authentication, so a bearer-token request is keyed by an HMAC of the token (never the raw token), falling back to user id or IP when there is none. A user with several tokens therefore gets several buckets, and a junk token starts a fresh one every time, so a per-IP backstop of 300/min also applies to token requests.

| Limiter | Routes | Limit |
|---|---|---|
| `api` (group) | every API route | 60/min per bearer token (plus 300/min per IP backstop), else per user or IP |
| `login` | `auth/login`, `auth/token` | 5/min per IP + email, 20/hour per email, and 30/min per IP |
| `google` | `auth/google/*` | 20/min and 120/hour per IP |
| `register` | `auth/register` | 3/min per IP |
| `password-forgot` | `password/forgot`, `password/reset` | 3/min per IP + email, 20/hour per IP |
| `verification-resend` | `auth/email/resend` | 1/min and 6/hour per user |
| `password-change` | `PUT me/password` | 5/min and 20/hour per user |
| `account-delete` | `DELETE me` | 5/min and 20/hour per user |
| `email-change` | `POST me/email` | 3/hour per user |
| `avatar` | `me/avatar` | 10/min per user |
| `admin-write` | `PATCH admin/users/{user}/role`, the anti-cheat writes (`POST staff/risk-signals/{signal}/review`, `staff/risk/members/{member}/void-votes` and `/suspend`, `staff/votes/{vote}/void`), `PATCH admin/settings/{key}`, admin topic writes, `staff/products/*` review, take-down and restore, `POST staff/reports/{type}/{id}/resolve`, `POST admin/users/{handle}/suspend` and `restore` | 30/min and 200/hour per admin |
| `product-write` | `POST/PATCH/DELETE products`, launch date, logo removal, screenshot order and removal | 30/min and 300/hour per user |
| `product-media` | logo and screenshot uploads | 10/min and 60/hour per user |
| `product-submit` | `products/{id}/submit` and `withdraw` | 5/min and 20/hour per user |
| `vote` | `PUT/DELETE products/{id}/vote` | 30/min and 300/hour per user |
| `comment-create` | `POST products/{id}/comments` | 6/min and 60/hour per user |
| `comment-modify` | `PATCH` and `DELETE comments/{id}` | 10/min and 60/hour per user |
| `follow` | `PUT/DELETE users/{handle}/follow`, `PUT/DELETE topics/{slug}/follow` | 20/min and 200/hour per user |
| `report` | `POST comments/{id}/report`, `POST products/{id}/report` | 5/min and 30/hour per user |
| `notifications-read` | `POST me/notifications/read` | 30/min and 300/hour per user |
| `email-prefs` | `PATCH me/email-preferences` | 20/min and 100/hour per user |
| `unsubscribe` | `POST email/unsubscribe` | 20/min and 100/hour per IP (runs before the signature check, so forged links count) |
| `search` | `GET search` | 45/min per viewer (the IP for a guest; an HMAC of the bearer token, never the raw token, for a token caller) plus a separate 90/min per-IP backstop for bearer requests; the key never contains the query |
| `view` | `POST products/{id}/view` | 30/min and 600/hour per device (the HMAC of the `lp_did` cookie or `X-Device-Id` the client presented; a client that presented none is keyed by its IP, so 30/min per IP), plus 120/min per IP (IPv6 reduced to its /64). The 120 is above the group's 60 and only binds a bearer caller that rotates tokens or device ids |
| `export-start` | `POST me/products/{id}/exports` | 3/hour and 10/day **per member** (no per-product bucket, or a stranger could exhaust the owner's allowance; the idempotent 200 for an export already in flight counts; runs before the `active` guard like the other route middleware) |
| `export-poll` | `GET me/products/{id}/exports` and `.../exports/{export}` | 30/min per member |
| `export-download` | `GET me/products/{id}/exports/{export}/download` | 20/hour per member |
| `dashboard` | `GET me/products/{id}/dashboard...` and `GET staff/products/{id}/dashboard...` | 30/min per member, shared by all eight dashboard reads and by the maker and staff routes |
| `broadcast-auth` | `POST broadcasting/auth` | 30/min per user |

## Products and launches

- **Lifecycle.** `draft -> pending -> approved -> scheduled -> live`, with `changes_requested`, `rejected`, `taken_down` and withdraw (back to `draft`). The rules live in `ProductStatus::canTransitionTo()` and `ProductPolicy::editableFields()`; clients read `editable_fields` and `media_editable` from the founder resource instead of copying the table. Resubmitting after `changes_requested` (or a withdraw) sets a new `submitted_at`, so the product goes to the back of the oldest-first review queue.
- **Error codes the web and phone apps switch on** (all JSON `{"message", "code"}`): `invalid_status` (409), `product_locked` (403), `own_product` (403), `voting_closed` (409, see Voting and ranking), `stale_submission` (409), `maker_unavailable` (409), `try_again` (409), `topic_in_use` (409), `has_active_product` (403), plus the existing `email_unverified` and `account_unavailable` (403). A hidden, foreign or unknown product is always `404 {"message":"Not found."}`; `draft` products are 404 for staff too.
- **Review decisions carry a token.** `POST /staff/products/{id}/review` must echo the `submitted_at` the reviewer saw; if the founder withdrew and resubmitted meanwhile the answer is `409 stale_submission`. The founder resource lists the review history with `current: true` on the one `changes_requested` or `rejected` decision that put the product in its present status (false everywhere else); the take-down reason is visible to the founder.
- **Go-live scheduler.** `launchpad:publish-launches` runs every minute through Laravel's scheduler. Production needs `* * * * * cd /path && php artisan schedule:run` (or `schedule:work` under a supervisor). With more than one server keep `CACHE_STORE=redis` so `onOneServer()` and `withoutOverlapping` can lock; the command is idempotent either way and catches up missed days. It skips products whose maker is suspended or deleted (they go live on the first run after the account is active) and exits non-zero when an item failed.
- **Maker state.** Restoring a taken-down product answers `409 maker_unavailable` for a suspended or deleted maker. A live product stays public when its maker is suspended (staff use take-down); products of a deleted maker are hidden from the public reads. `DELETE /me` answers `has_active_product` while a product is pending, approved, scheduled or live; it removes drafts and `changes_requested` products (files after commit) and rewrites their slugs to `deleted-<ulid>`, as does deleting a draft, so the slug is free again. Rejected and taken-down products keep their content, hidden.
- **Storage.** Logos and screenshots go to `PRODUCT_DISK` (default `public`; run `php artisan storage:link --force` inside the container). Everything is re-encoded to WebP; screenshots are scaled to fit 1600 px, logos cropped to 512 px square. Files of unpublished products are served by an unguessable URL from the public disk.
- **Events.** `ProductSubmitted`, `ProductWithdrawn`, `ProductApproved`, `ProductRejected`, `ProductChangesRequested`, `ProductScheduled`, `ProductWentLive`, `ProductTakenDown` and `ProductRestored` are dispatched after commit and carry ids only. Nothing listens yet (sub-project 5; make the `ProductWentLive` listeners queued).
- **Plain text.** Names, taglines, descriptions, alt texts, review notes, take-down reasons and the before/after values in `product.edited_after_launch` audit rows are plain text. Clients must render them escaped; the API strips control and bidi characters but does not strip HTML.
- **Links** are rewritten on every save, so their ids change; do not key UI state on a link id.
- **Public reads** (`GET /products/{slug}`, `GET /users/{handle}/products`) set the `lp_did` device cookie (to visitors that do not already send a valid one) and Laravel's default `no-cache, private`: decide before any CDN work (skip the cookie on anonymous GETs and send `public, max-age`).
- **Settings** an admin can change (`PATCH admin/settings/{key}`): `launch.max_per_maker_per_day` (2, 1 to 20), `launch.max_days_ahead` (60, 1 to 365), `product.max_topics` (3, 1 to 5), `product.max_screenshots` (6, 1 to 20), `product.max_pending_per_maker` (5, 1 to 50). The two ranking settings are listed under Voting and ranking, the nine `retention.*` windows under Settings below.
- **Lists** use keyset cursors; an empty `?cursor=` means page one on every list (review queue, audit log, my products, a member's products).
- **Not built, on purpose:** no cap on drafts or on pending products per maker (`product-write` and `product-submit` bound the rate); a flood of pending products is possible at about 20 submissions an hour per member. Add a per-maker cap in `SubmitProduct` if abuse shows up.
- **Lock order** (do not break it): a member's product rows (id order), then the active-admin set (id order), then a user row. The community sub-project extends it, see "Community" below.

## Voting and ranking

Design: `../docs/superpowers/specs/2026-10-19-voting-ranking-design.md` (see its "Clarifications made while planning/building" section for what was built); plan: `../docs/superpowers/plans/2026-10-19-voting-ranking.md`.

### Rules

- **Window.** A product can be voted on only on its launch day: `launch_date` equals today in **UTC** (00:00 to 24:00 UTC). Before that the product is not public (404); after it the day is closed (`409 voting_closed`) and, once frozen, final.
- **One vote per member per product.** `PUT /products/{id}/vote` casts, `DELETE /products/{id}/vote` withdraws. Both are idempotent: casting twice or withdrawing an inactive vote answers 200 with the current state and changes nothing (no second count, no event). A re-vote after a withdrawal reuses the row (`toggle_count` + 1).
- **Own product.** A maker cannot vote for their own product (`403 own_product`).
- **Voided votes** (mechanism only; nothing decides yet, sub-project 8) never count and cannot be revived by the member: a cast on a voided vote answers 200 and stays voided, without telling the member.
- **Counts** (`products.votes_count` and the hourly bucket) change in the same transaction as the vote, under the product row lock, so they are exact and instant. The **order** follows the votes within about a minute (the hot score is recomputed every minute).
- **Order of checks** (under the product lock): visible product (else the one uniform `404 {"message":"Not found."}`: unknown, hidden, taken down, deleted maker, future launch, malformed id), then window (`voting_closed`), then own product (`own_product`).
- **Closed days.** A finished day is frozen into `daily_rankings` by `launchpad:freeze-day` (insert-only, guarded by triggers). Past-day pages read only that table.
- **Withdrawing a deleted account's votes.** `DELETE /me` queues `VoidMemberVotes` (reason `account_deleted`; the old `VoidDeletedMemberVotes` class is a deprecated subclass, see "Anti-cheat") after its commit; it voids the member's votes on days that are not frozen yet. `launchpad:sweep-deleted-member-votes` (hourly) re-dispatches it if the dispatch or the job was lost.

### Endpoints (all under `/api/v1`)

| Method and path | Auth | Answer |
|---|---|---|
| `PUT /products/{id}/vote` | member (`auth:sanctum`, `active`, `verified.api`), limiter `vote` | `200 {"data": {"voted": true, "votes_count": n}}` |
| `DELETE /products/{id}/vote` | same | `200 {"data": {"voted": false, "votes_count": n}}` |
| `GET /today` | public; a signed-in caller (token or SPA session) also gets `has_voted` | `{"data": [card + rank + votes_count (+ has_voted)], "meta": {"date", "generated_at"}}`; top 100 by hot score, then votes, then id; no paging (the order moves every minute) |
| `GET /days/{date}` | public (`YYYY-MM-DD`) | `{"data": [...], "meta": {"date", "frozen"}}`; a past day reads the frozen order, today answers like `/today` (`frozen: false`), a day with no launches is `[]`; a malformed date is 422, a future date 404 |
| `GET /leaderboards?period=week\|month\|all[&topic=slug]` | public | `{"data": [... top 50 ...], "meta": {"period", "topic", "generated_at"}}`; ranked by `votes_count`, then `launch_date` (newer first), then id; week = last 7 launch days, month = last 30 (UTC), all = everything; `period` is required; an unknown period or topic and any array/object value is 422; the query string only is read (a body is ignored) |

There is **no endpoint that lists voters**, and none is planned: `has_voted` is returned only to the caller about themselves, and `VotingRoutesTest` fails if any route URI contains `vote`, `voter`, `likes` or `upvote` beyond the two write routes (it checks route paths, not response bodies). Vote writes (and their refusals) are `Cache-Control: no-store, private`. The public reads send `Vary: Authorization, Cookie` always, and `no-store, private` only when the caller is a known member.

**Error codes on the vote routes** (JSON `{"message", "code"}`; verified in `ProductRuleViolation` and `LocksVotableProduct`): `voting_closed` (409, not the launch day), `own_product` (403), plus the account guards `email_unverified` / `account_unavailable` (403), an unknown or hidden product (404 without a code), 401 for a guest, 429 from the limiters. `day_frozen` (409) is raised by `VoidVote` when a vote of an already-frozen day is voided; today only the deleted-member job and future anti-cheat call it, so no HTTP route returns it yet (the job treats it as "nothing left to do"). `try_again` (409) is `DeleteAccount`'s "a product appeared while the locks were being taken"; the vote routes never return it.

### Settings (`PATCH /admin/settings/{key}`, admin only)

| Key | Default | Validation |
|---|---|---|
| `ranking.decay_exponent` | `"0.9"` | a JSON string holding a plain decimal (`0.1` to `3`, at most 3 decimals, no sign, exponent or trailing newline) |
| `ranking.early_bonus` | `"0.35"` | the same shape, `0` to `5` |

The retention windows are integers (days):

| Key | Default | Allowed | Enforced by |
|---|---|---|---|
| `retention.auth_events_days` | 180 | 7 to 3650 | `launchpad:purge-retention` |
| `retention.vote_hashes_days` | 90 | 7 to 3650 | `launchpad:purge-retention` |
| `retention.rank_snapshots_days` | 180 | 7 to 365 | `launchpad:purge-retention` |
| `retention.audit_network_days` | 365 | 30 to 3650 | `launchpad:purge-retention` (empties `audit_logs.ip_hash` and `user_agent`) |
| `retention.notifications_days` | 90 | 7 to 365 | `launchpad:purge-retention` (bell rows, read or not) |
| `retention.email_messages_days` | 90 | 30 to 365 | `launchpad:purge-retention` (finished outbox mail, and digest run rows a day earlier) |
| `retention.exports_days` | 7 | 1 to 30 | `launchpad:purge-retention` (`PurgeExports`: expires finished exports, deletes their zips) |
| `retention.risk_signals_days` | 365 | 30 to 3650 | `launchpad:purge-retention` (`PurgeRiskSignals`: unreviewed signals; also the 365 day cap on reviewed ones) |
| `retention.risk_signals_reviewed_days` | 90 | 7 to 3650 | `launchpad:purge-retention` (`PurgeRiskSignals`: reviewed signals, counted from the review) |

The community settings are integers too (`Settings::integer()`, which re-checks type and range on every read and falls back to the default, with a warning that names only the key, for a stored value outside the range):

| Key | Default | Allowed | Enforced by |
|---|---|---|---|
| `community.comment_edit_minutes` | 15 | 0 to 1440 (0 = comments can never be edited) | `EditComment` (window counted from `created_at`) |
| `community.report_open_cap` | 20 | 1 to 200 | `CreateReport` (open reports one member may have at a time) |

`PATCH /admin/settings/{key}` accepts a digit string such as `"15"` for these and stores the integer; `true`, floats, arrays, objects and null are 422.

`Settings::days()` re-checks type and range on every read; a stored value that is not an integer inside the range (a direct database edit) serves the **default** (a longer retention, never a clamp to the nearest bound and never `0`), and logs a warning with the key only (never the value).

They are strings on purpose (settings hold integers or strings); `Settings::decimal()` re-checks the shape and range on every read and falls back to the default for a stored value that is out of range. The score is `sum(votes_in_hour / (age + 2) ^ decay)` where `age` is the current hour index minus the bucket's hour index over the day's hourly buckets plus `early_bonus * votes_in_hours_0_and_1`; the class is `HotScore` (`score(array $votesByHour, int $currentHour)`).

### Scheduled commands

`routes/console.php` (14 scheduled events, listed below); every one uses `withoutOverlapping(n)` and `onOneServer()` (which needs a shared cache, `CACHE_STORE=redis`, when more than one server runs the scheduler) and is idempotent. A failure is reported and the command exits non-zero.

| Command | Frequency | Lock (min) | What it does |
|---|---|---|---|
| `launchpad:publish-launches` | every minute | 5 | scheduled products whose day began go live (catches up missed days) |
| `launchpad:recompute-hot-scores` | every minute | 5 | recomputes `hot_score` of today's live products, then fires `RankingUpdated` when a score changed |
| `launchpad:freeze-day` | every minute | 5 | writes `daily_rankings` for finished days (60 s after midnight at the earliest), appends late arrivals; `--from=YYYY-MM-DD` widens the 31-day look-back for a backfill |
| `launchpad:snapshot-ranks` | every 10 minutes | 15 | writes `rank_snapshots` (top 500 of today) for the 10-minute mark; re-running inside a window is a no-op |
| `launchpad:sweep-exports` | every 5 minutes | 10 | the export queue's safety net: re-dispatches an export still `queued` after 10 minutes, fails one `processing` for 10 minutes as `stalled` and deletes its orphan zip; non-zero if a row failed |
| `launchpad:flush-views` | every minute | 5 | writes the page views counted in Redis into `product_hourly_stats.views` and `product_referrers_daily` (see "Views and the founder dashboard"); non-zero if Redis could not be read or a batch failed |
| `launchpad:detect-abuse` | every 5 minutes | 10 | the anti-cheat detection run (see "Anti-cheat"); runs in the background; non-zero if a chunk failed |
| `launchpad:sweep-deleted-member-votes` | hourly | 10 | re-dispatches the vote clean-up for deleted members |
| `launchpad:sweep-deleted-member-comments` | hourly at minute 30 (`30 * * * *`) | 10 | re-dispatches the comment clean-up for deleted members who still own comments that were not erased, and for deleted reporters whose open reports can be closed (see "Community") |
| `launchpad:send-emails` | every minute | 5 | sends the due mail of the outbox (see "Notifications and email"); exits non-zero after a transient failure, a lost (lease-exhausted) row or a tripped breaker |
| `launchpad:reconcile-notifications` | hourly at minute 45 (`45 * * * *`) | 30 | re-derives the notifications and mail of the last 48 hours from state and fills gaps; exits non-zero if an item failed |
| `launchpad:queue-launch-reminders` | every 10 minutes | 15 | the day before a launch, from `digest.daily_send_at_utc` (UTC), queues a reminder for every scheduled product launching tomorrow; runs in the background; non-zero if an item failed (see "Notifications and email") |
| `launchpad:queue-digests` | every 10 minutes | 60 | queues the daily top five (every day) and the weekly best (Mondays) into the outbox, resumably, in chunks of 500; runs in the background; non-zero if a kind failed or an old run was abandoned |
| `launchpad:purge-retention` | daily at 03:15 UTC | 60 | the retention purge (see "Privacy and retention"): empties old vote hashes, deletes old `auth_events`, `rank_snapshots`, `notifications`, finished `email_messages` and `digest_runs`, empties the IP hash and user agent of old `audit_logs` rows; prints one line per table and a total, exits non-zero if any table failed |


**The 14 scheduled commands** (`php artisan schedule:list`): `launchpad:detect-abuse` (`*/5 * * * *`, 10, background), `launchpad:sweep-exports` (`*/5 * * * *`, 10), `launchpad:flush-views` (`* * * * *`, 5), `launchpad:publish-launches` (`* * * * *`, lock 5), `launchpad:recompute-hot-scores` (`* * * * *`, 5), `launchpad:freeze-day` (`* * * * *`, 5), `launchpad:send-emails` (`* * * * *`, 5), `launchpad:snapshot-ranks` (`*/10 * * * *`, 15), `launchpad:queue-launch-reminders` (`*/10 * * * *`, 15, background), `launchpad:queue-digests` (`*/10 * * * *`, 60, background), `launchpad:sweep-deleted-member-votes` (`0 * * * *`, 10), `launchpad:sweep-deleted-member-comments` (`30 * * * *`, 10), `launchpad:reconcile-notifications` (`45 * * * *`, 30) and `launchpad:purge-retention` (`15 3 * * *`, 60). `launchpad:requeue-failed-emails` is manual and not scheduled.

### What production must run

A web server is not enough. Without these the site still answers votes, but ranks, past days, snapshots and live updates silently stop:

1. **The scheduler**: cron `* * * * * cd /path && php artisan schedule:run >> storage/logs/schedule.log 2>&1` (keep the output: a silent scheduler hides failed jobs; rotate the file), or `php artisan schedule:work` under a supervisor.
2. **An async queue worker** (`php artisan queue:work` under a supervisor that restarts it, `QUEUE_CONNECTION=database` or `redis`, never `sync`; run `php artisan queue:restart` on every deploy so the worker loads the new code): broadcasts, mail, `VoidDeletedMemberVotes` and `DeleteMemberComments` run on it. Without a worker a deleted member's comments stay masked on reads but their counters stay inflated, and their open reports stay open (the minute-30 sweep only re-dispatches, it needs the worker too). `.env.example` ships `QUEUE_CONNECTION=database`.
3. **Redis** (`CACHE_STORE=redis` in `.env.example`): scheduler locks (`onOneServer`, `withoutOverlapping`), the unique-job locks and the settings cache (`Settings` uses the default cache store, which is `redis` in `.env.example`).
4. **A Reverb process** (`php artisan reverb:start`, the compose `reverb` service in Sail) with real `REVERB_APP_ID/KEY/SECRET`, `REVERB_ALLOWED_ORIGINS` and a TLS proxy in front. See "Reverb (live updates)" above for the channels (`product.{id}` `vote.count`, `today` `rank.updated`), payloads, origin rules and the 10,000 byte publish limit; they are not repeated here.

### Privacy and retention of vote data

A vote row stores `ip_hash` and `device_hash` (keyed HMACs, never raw values), a user agent truncated to 255 characters (not hashed), the vote state and timestamps, and `toggle_count`. Nobody can read them through the API, and the audit log gets no vote rows.

**The retention purge** is `launchpad:purge-retention` (`App\Actions\Retention\PurgeRetention`, one class per table, nine tables), daily at 03:15 UTC. The clock is read once per run. Per table:

| Table | Rule | Window setting |
|---|---|---|
| `votes` | `ip_hash`, `device_hash` and `user_agent` set to NULL when the product's `launch_date` is more than N days before today (UTC). "90 days after the launch day" therefore means purged from day 91; the launch day exactly N days back is kept (do not change `<` to `<=`; a test pins it, also across UTC midnight). A vote on a product without a launch day (draft, pending) is never touched. The vote row, its status, counts and timestamps stay. Idempotent (an emptied row is not selected again). | `retention.vote_hashes_days` (90) |
| `auth_events` | rows with `created_at` older than N days are deleted (index `auth_events_created_at_index`). No trigger guards this table and no foreign key points at it. | `retention.auth_events_days` (180) |
| `rank_snapshots` | rows with `captured_at` older than N days are deleted (index `rank_snapshots_captured_at_index`). | `retention.rank_snapshots_days` (180) |
| `audit_logs` | **rows are never deleted** (who, what, when, subject and `meta` stay for ever, append-only). Only `ip_hash` and `user_agent` are set to NULL once `created_at` is older than N days (strictly older: a row exactly N days old is kept). `PurgeAuditNetwork` is chunked like the others (oldest first, `SKIP LOCKED`, idempotent, partial index `audit_logs_unpurged_network_index` on rows that still hold either column). See "The audit_logs exception" below. | `retention.audit_network_days` (365, 30 to 3650) |
| `notifications` | bell rows with `created_at` strictly older than N days are deleted, **read or not** (an unread row older than the window goes too). Index `notifications_created_at_index`. | `retention.notifications_days` (90, 7 to 365) |
| `email_messages` | rows in `sent`, `failed` or `suppressed` older than N days are deleted (the row holds the recipient's address); a `queued` or `sending` row is **never** purged, however old. The effective age never drops below 72 hours (`Reconcile::MIN_EMAIL_RETENTION_HOURS`) whatever the setting says, because a purged `sent` row frees its idempotency key and the 48 hour reconcile sweep would queue the mail again. Index `email_messages_terminal_created_index`. | `retention.email_messages_days` (90, 30 to 365) |
| `digest_runs` | rows whose `period_date` is older than N days are deleted, with the **same setting as the mail** but measured in whole days from the UTC midnight, so a run row goes about a day before its mail rows. Index `digest_runs_period_date_index`. | `retention.email_messages_days` |
| `exports` | a `ready` export past its `expires_at` (set to completion time + `retention.exports_days` when it finished, so a later change of the setting does not move it) or owned by a deleted member becomes `expired`; the zip of every `expired`/`failed` row is deleted **after the chunk commits** (a failed delete keeps the path on the row and the next run finishes it); a `failed`/`expired` row without a file is deleted 30 days after its last change. Picked in a `MATERIALIZED` CTE with `SKIP LOCKED`, appended last to `PurgeRetention`; export rows only, no user or product row is locked. | `retention.exports_days` (7, 1 to 30) |
| `risk_signals` | an unreviewed signal once `detected_at` is older than N days; a reviewed one once `reviewed_at` is older than M days or its `detected_at` is older than the N day cap; strictly older (a row exactly at the cutoff is kept). **Reviewed `fresh_account`, `disposable_email` and `single_maker_voter` signals (the once-per-account kinds) are kept for ever, whatever the outcome**: their dedupe key would otherwise be free again and the next detection run would raise a fresh open signal for something staff already decided (they are one small row per account; unreviewed rows of those kinds still go at N days). Candidates come from three single-predicate branches, each served by one partial index, picked in a `MATERIALIZED` CTE with `SKIP LOCKED`; no user or product row is locked. The `audit_logs` rows of reviews stay for ever; a purged confirmed signal simply stops counting at the next scoring pass. | `retention.risk_signals_days` (365) and `retention.risk_signals_reviewed_days` (90) |

Mechanics: each table runs in its own try/catch, so one failing table does not stop the others; a failure is reported through `report()`, printed as `FAILED` and makes the command exit non-zero. Work is done in chunks of 2,000 rows, each chunk its own short transaction (the statement picks its rows in a `WITH picked AS MATERIALIZED (SELECT ... ORDER BY ... LIMIT n FOR UPDATE SKIP LOCKED)` CTE and updates or deletes `FROM`/`USING picked`; `MATERIALIZED` makes the chunk bound independent of the plan, which with a plain `WHERE id IN (subselect)` could be re-run by a semi-join and touch more than `n` rows), at most 100 chunks per table per run, so a large backlog continues over the next nights (the command says when a table stopped at the cap). The purge locks only the rows it changes: never a user or a product row, and never a vote of the current day, so it cannot block a `CastVote`. Nothing reads the vote signal columns after the vote is written (`CastVote` only writes them), so a NULL breaks no feature. Partial index `votes_unpurged_signals_index` keeps the daily vote scan small. `rank_snapshots` and `product_hourly_stats` hold no personal data.

**The audit_logs exception.** The trigger function `audit_logs_immutable()` (replaced by migration `2026_10_21_000003`, never edit the older ones) lets an `UPDATE` through only when all of these hold: the transaction set the transaction-local flag `launchpad.audit_purge` to `on` (`set_config(..., true)`; only `PurgeAuditNetwork` sets it, for one statement, and switches it off again right after), `ip_hash` and `user_agent` both end up NULL, and every other column is unchanged. Any other UPDATE, every DELETE and TRUNCATE still raise `audit_logs is append-only`. The flag dies with its transaction (tests prove it on a real connection). **It is not a privilege boundary:** any role that can run SQL can set the flag, so it protects against bugs and mistakes, not against a hostile database role; for that keep the `REVOKE UPDATE ... FROM launchpad_app` above (then the purge must run as a role that keeps UPDATE on `audit_logs`, for example the migration/owner role). `meta` is not touched and may hold short reasons, never personal data.

**Operating notes**
- **A table that stops at the cap** is printed as a warning (the exit code stays 0): the next nightly run continues the backlog. A table that fails prints `FAILED after N row(s) purged` (rows from chunks that committed before the error are counted) and the command exits 1.
- **Manual run:** `php artisan launchpad:purge-retention` is safe at any time and idempotent. There is no dry run.
- **Creating the new indexes on a large production database.** The migrations (`rank_snapshots_captured_at_index`, `votes_unpurged_signals_index`, `audit_logs_unpurged_network_index`) use plain `CREATE INDEX`, which blocks writes to `rank_snapshots` and `votes` while it builds. On a large table create them by hand first, outside a transaction, with the same names: `CREATE INDEX CONCURRENTLY IF NOT EXISTS rank_snapshots_captured_at_index ON rank_snapshots (captured_at);` and `CREATE INDEX CONCURRENTLY IF NOT EXISTS votes_unpurged_signals_index ON votes (product_id) WHERE ip_hash IS NOT NULL OR device_hash IS NOT NULL OR user_agent IS NOT NULL;` and `CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_logs_unpurged_network_index ON audit_logs (created_at) WHERE ip_hash IS NOT NULL OR user_agent IS NOT NULL;`. The migrations then skip them (`IF NOT EXISTS`). A failed concurrent build leaves an INVALID index: `DROP INDEX CONCURRENTLY` it before retrying.
- **Cross-sub-project:** vote `ip_hash`/`device_hash` older than the vote window (90 days) are gone, so a later anti-cheat job cannot use them, while `risk_signals` live 365 days (90 after review). `launchpad:detect-abuse` only scans yesterday's and today's launch days, so it always finds the hashes it needs; the evidence it stores is ids and counts only, never a hash.

### Open decisions and known limits

- **`rank_snapshots` retention window.** Up to about 72,000 rows a day at 500 rows per 10-minute mark, no personal data. The 180-day default (`retention.rank_snapshots_days`, 7 to 365) is an owner decision (2026-10-22, raised from 30; about 13M rows at steady state), and the founder dashboard's timeline lookback follows it. Ranks above 500 are never stored; readers must apply `visible()`; the 00:00 snapshot may be empty or partial; a skipped window is a permanent gap.
- **Decided and built (2026-10-21): `audit_logs.ip_hash` and `user_agent` are emptied after `retention.audit_network_days` (365 by default).** The rows themselves stay for ever. Until the first nightly run after deploy, rows older than the window still hold both columns; the first runs work off that backlog in chunks (100 chunks of 2,000 per night). The trigger exception is documented under "The audit_logs exception".
- **Public reads set cookies.** Every public answer carries the global `lp_did` device cookie, and a request from a stateful SPA origin also gets a session cookie and `XSRF-TOKEN` (framework behaviour of `statefulApi()`), so `today`, `days` and `leaderboards` are not CDN-cacheable until that is decided.
- **Sanctum `last_used_at`.** A public read that carries a valid token (for `has_voted`) makes Sanctum write `last_used_at`; the `api` limiter bounds it, but it is a write on a read path.
- **Missed days.** A launch released more than 31 days late is not frozen by the default run: use `launchpad:freeze-day --from=YYYY-MM-DD`.
- **Today's feed has no paging** (top 100). Revisit if one day ever has more launches.
- **Hidden products stay on `today` frames** until the next score change (the frame is a hint; clients re-read `GET /today`).

## Community

Design: `../docs/superpowers/specs/2026-10-21-community-design.md` (its section 15 lists every place the build differs from the spec); plan: `../docs/superpowers/plans/2026-10-21-community.md`. Phases A (comments), B (follows, the feed, member reports) and C (staff queue and resolution, suspension, the deleted-member job) are built.

### Endpoints (all under `/api/v1`)

Member routes carry `auth:sanctum`, `active` and `verified.api` (so a guest is 401, a suspended member 403 `account_unavailable`, an unverified one 403 `email_unverified`, the same for an existing and a missing id). Ids in `{...}` are plain strings; every hidden, missing or foreign target is the one `404 {"message":"Not found."}`. Non-cacheable methods under `comments`, `users`, `topics`, `products` and `me/*` are `Cache-Control: no-store, private`.

| Method and path | Auth, limiter | Answer |
|---|---|---|
| `GET /products/{slug}/comments?sort=top\|newest&per_page=&cursor=` | public (a signed-in caller also gets `can_edit`/`can_delete`) | `{"data": [comment + replies (first 3)], "links", "meta"}`; `per_page` 1 to 50, default 20; `top` (default) = most replies, then newest; the product must be public with an available maker, else 404 |
| `GET /comments/{id}/replies?per_page=&cursor=` | public | the visible replies of a top-level comment, oldest first, same paging; a reply id, an unknown id or a hidden product is 404 |
| `POST /products/{id}/comments` | member, `comment-create` | body `{"body", "parent_id"?}`; `201` with the comment; `parent_id` is any comment of the product (see "Comment rules") |
| `PATCH /comments/{id}` | member, `comment-modify` | body `{"body"}`; `200` with the comment; `409 edit_window_closed` after the window |
| `DELETE /comments/{id}` | member, `comment-modify` | `200` with the placeholder; idempotent |
| `PUT /users/{handle}/follow`, `DELETE /users/{handle}/follow` | member, `follow` | `200 {"data": {"following": bool, "followers_count": n}}` (always 200, idempotent, no 201) |
| `PUT /topics/{slug}/follow`, `DELETE /topics/{slug}/follow` | member, `follow` | the same shape; `followers_count` is the topic's |
| `GET /me/follows/users?per_page=&cursor=` | member | the members I follow, newest follow first: `{id, handle, name, headline, avatar_url, is_suspended, followed_at}` (a suspended member shows only name and handle); `per_page` 1 to 100, default 25 |
| `GET /me/follows/topics` | member | all my followed topics ordered `position, name`, no paging |
| `GET /me/feed?per_page=&cursor=` | member | `{"data": [product card + votes_count, comments_count, has_voted, reasons], "links", "meta": {..., "has_follows": bool}}`; `per_page` 1 to 50, default 20 |
| `POST /comments/{id}/report`, `POST /products/{id}/report` | member, `report` | body `{"reason", "details"?}`; `201` for a new report, `200` with the same report when the member already has an open one on it; `{"data": {id, type, target_id, reason, status, created_at}}` |

Other community fields: `GET /users/{handle}` gains `followers_count` and `following_count` (public, always) and `is_following` (only for a known viewer; the owner looking at themselves gets `false`; the key is omitted for guests, like `has_voted`). The maker cards on product reads carry no counts. Product cards everywhere `votes_count` appears (single read, today, days, leaderboards, the feed) also carry `comments_count`.

A comment is `{id, parent_id, state, body, author, reply_to, created_at, edited_at, replies_count, replies?, can_edit?, can_delete?}`. `state` is `visible`, `removed` (by staff) or `deleted` (by the author or a deleted account); `body` and `author` are `null` unless `visible`; `reply_to` is `{id, handle, name}` or `null`. `created_at` and `edited_at` keep microseconds. The removal reason and who removed a comment are never public. `can_edit` is true for the author inside the edit window, `can_delete` for the author of a visible comment.

### Comment rules

- **Body.** Plain text, 1 to 1000 characters after cleaning (control, bidi and invisible characters are stripped, line breaks kept, at most 2 consecutive newlines); it must contain a letter, a number or an emoji; invalid UTF-8 is 422. The database also allows up to 2000 as a backstop. The server never emits HTML and never auto-links: clients render the text escaped.
- **Link cap: at most 3.** Counted on an NFKC-folded copy of the body (so fullwidth forms count): `scheme://...`, `www....` and bare domains such as `example.com/page`. An e-mail address is not a link, and neither are "version 3.5" or "see you at 5pm." The stored body stays exactly as typed. More than 3 links is 422 on `body`.
- **One level of replies.** `parent_id` may be any visible comment of the same product. Answering a reply attaches the new comment to the top-level ancestor and sets `reply_to` to the author of the comment that was answered; answering a top-level comment sets `reply_to` to `null`. A removed or deleted comment cannot be answered (404). The database enforces the depth with a trigger.
- **Edit window.** The author may edit a visible comment within `community.comment_edit_minutes` (default 15) of `created_at`, any number of times; `edited_at` is set. After that, 409 `edit_window_closed`. Sending the same text again is a quiet 200 at any time and does not bump `edited_at`.
- **Soft delete.** `DELETE` sets `deleted_at` and **wipes the body**; the row stays so replies keep their parent. Repeating it is a 200 no-op. A comment removed by staff is "missing" (404) to its author on edit and delete.
- **Placeholders.** A removed or deleted top-level comment is listed (`state` `removed`/`deleted`, no body, no author) only while it still has visible replies; a removed or deleted reply disappears from the lists.
- **Ghost authors.** A comment whose author deleted their account reads as `deleted` at once (the reads mask it), but it is still counted in `comments_count` and `replies_count` until the deleted-member job corrects the counters.
- **Counters.** `products.comments_count` (comments neither removed nor deleted) and `comments.replies_count` change only by relative updates in the comment's own transaction, under the product row lock.
- **Paging.** Cursors are keyset and shape-checked (a tampered one is 422, an empty `?cursor=` is page one). `links.next` and `links.prev` carry `sort` and `per_page`, so a client can follow them as they are. Replies embedded in a page are the first 3 per thread; the rest come from `GET /comments/{id}/replies`.
- **Event.** `CommentCreated` (ids only) is dispatched after the transaction commits, inside a try/catch that reports and swallows a failing listener. Nothing listens yet (sub-project 5).

### Follows and the feed

- **Public counts, private lists.** `followers_count` and `following_count` are plain `COUNT(*)` on the indexed follow tables. Only the owner reads a list (`GET /me/follows/...`); no route lists anyone's followers.
- **Rules.** Following yourself (or unfollowing yourself) is 403 `self_follow`; a deleted member is the uniform 404; a suspended member cannot be newly followed (409 `member_unavailable`) but an existing follow can always be removed; reviewers and admins can be followed. No cap (the `follow` limiter is the brake). A topic is addressed by its lowercase slug; any other spelling or an unknown slug is 404. A handle that is not valid UTF-8 is a 400, never a 500.
- **Feed.** Launched, public products whose maker I follow or that carry a topic I follow, never my own, `launched_at desc, id desc`, keyset. Each card has `reasons`: at most one `{"type":"member","user":{id,handle,name}}` first, then at most three `{"type":"topic","topic":{id,slug,name}}`. `meta.has_follows` is true when I follow anyone or any topic, so a client can show "follow someone" instead of an empty list.
- **Built at read time and never cached.** There is no feed table and no cache; the query is a two-branch `UNION` (followed makers through a `LATERAL` per followed member, and followed topics), each branch index-driven, so a follow or an unfollow shows on the next request. `me/*` answers `no-store, private`.
- **Deleting an account** deletes the member's follows in both directions and their topic follows. **Deleting a topic** deletes the follows of an unused topic; a topic in use is still 409 `topic_in_use`.

### Reports (member side)

- **Targets.** A public product (with an available maker) or a visible comment of a live author on such a product. Anything else, including hidden, removed, deleted and unknown ids, is the uniform 404. You cannot report your own product or comment (403 `own_content`).
- **Order of checks.** Target (404), own content (403), an existing **open** report of mine on it (200, nothing written, also when I am at the cap), an earlier **dismissed** report of mine on it (409 `already_reviewed`), the open cap (409 `report_limit`), then the insert.
- **One open report per member per target** (partial unique index); a closed report never blocks a later one except a dismissed one (the action refuses it).
- **Open cap.** `community.report_open_cap` (default 20) limits a member's open reports. `CreateReport` serialises one member's requests with a `FOR UPDATE` on the reporter's user row, so the cap is exact for that member's parallel requests; it is still a brake, not a quota (closed reports free slots, the `report` limiter bounds the rate).
- **Details.** `reason` is `spam`, `abuse`, `misleading`, `inappropriate` or `other`; `details` (display text, max 1000) is required for `other`. The reporter's text and identity are staff-only; the response never says how many others reported. Reports never hide anything by themselves.
- **A comment deleted by its author** closes its open reports as `resolved` with no handler and the note `deleted_by_author`.

### Staff side: the report queue, resolution and suspension

Routes (under `/api/v1`). **Order of answers on every staff and admin route: 403, then 404, then 422.** The role is judged before the route parts or the body are looked at, so a non-staff caller gets the same 403 for an existing and an unknown id; staff then get 404 for an unknown type, a non-ULID id, a draft product (or a comment on one), then 422 for a bad body.

| Method and path | Who, limiter | Answer |
|---|---|---|
| `GET /staff/reports?type=comment\|product` | reviewer or admin | `{"data": [row], "meta": {"total_targets", "truncated"}}`; one row per reported target, no cursor |
| `GET /staff/reports/history?type=&per_page=&cursor=` | reviewer or admin | closed reports, newest handled first, keyset `(handled_at, id)` over `reports_history_index` |
| `GET /staff/reports/{type}/{id}` | reviewer or admin | one target in full with its open and closed reports (`type` is `comment` or `product`) |
| `POST /staff/reports/{type}/{id}/resolve` | reviewer or admin (`take_down`: admin only), `admin-write` | body `{"action", "note"?}`; `{"data": {closed, action, type, target_id, target_changed}}` |
| `POST /admin/users/{handle}/suspend` | admin, `admin-write` | body `{"reason"}` (1 to 500 characters); `{"data": {id, handle, is_suspended, suspended_at, suspended_reason}}` |
| `POST /admin/users/{handle}/restore` | admin, `admin-write` | the same shape, `is_suspended: false` |

- **The queue.** A row is `{type, target, open_reports, reasons, score, oldest_report_at}`; `target` is a compact summary (a comment: id, `state`, body excerpt of 140 characters, author handle, product; a product: id, slug, name, status, maker handle). Targets that are removed, deleted, taken down or hidden stay listed so staff can close them (a deleted comment shows an empty excerpt). Free text in it is data: render it as text.
- **Eligible reporters.** Only reports of reporters who are verified, not suspended and not deleted count; a target whose open reports are all from others is absent from the queue (the detail still lists them, each marked `reporter_eligible`).
- **Reliability.** A reporter's reliability is `(upheld + 1) / (decided + 2)` over their closed reports that a staff member handled (`resolved` = upheld, `dismissed` = not). A report closed by the system (`handled_by` null: the target was deleted by its author, or the reporter's account was deleted) counts for nothing either way. A new reporter is 0.5.
- **Habitual false reporters.** A reporter with at least 3 decided reports and a reliability below 0.25 has weight 0: the report adds nothing to `score` and is not counted in `open_reports`, so a ring of fake accounts cannot out-rank real reports by number. Their reports are still listed in the detail, still counted in `reasons`, and still closed by a resolve.
- **Ordering.** `open_reports` desc, then `score` (the summed reliabilities, rounded to 6 places so equal sums tie) desc, then the oldest eligible open report, then the target id.
- **At most 100 rows.** The top 70 by that ranking plus up to 30 of the oldest remaining targets (by oldest report), so a flood of high-count targets cannot starve a lone real report; the 100 are shown in ranking order. `meta.total_targets` is the number of distinct targets with an eligible open report and `meta.truncated` says whether some are not listed. There is no cursor (the order moves as reports arrive; the queue is meant to be drained).
- **Detail.** The stored text of a comment even when removed (the evidence), the author, the product, who removed it and why; `open_reports` are the oldest 200 (with reporter id and handle, never an email, `reason`, `details`, `reporter_eligible`) and `open_total` is how many exist; `closed_reports` are the latest 50. The history lists every closed report with its target, outcome, handler (`null` = the system) and note; the reporter's `details` text is kept for staff.
- **Resolve.** `action` is `remove_comment` (type `comment` only), `take_down` (type `product` only, **admin only**, the existing `TakeDownProduct` rules apply: a product that is neither live nor taken down is `409 invalid_status`) or `dismiss` (both types). `note` (cleaned, max 500) is required for the first two and optional for `dismiss`; an action that does not fit the type is 422. The staff note is stored whole in `reports.resolution_note`; the comment's `removed_reason` keeps the first 255 characters.
- **Per target, idempotent.** One resolve closes **every** open report of the target together (`resolved` for the two removing actions, `dismissed` for `dismiss`; ineligible and weightless reporters' reports too). **Nothing changes unless at least one open report was locked:** a resolve with no open report (a replay, or staff acting on something nobody reported) answers `200 closed: 0, target_changed: false` and writes nothing, not even an audit row. A comment already removed or deleted, or a product already taken down, is left as it is and its reports are closed as `resolved` (`target_changed: false`), with no second removal audit row. The actor's role is read from the database before the locks and **again after the last lock**, so a reviewer suspended or demoted while waiting writes nothing.
- **Audit.** `comment.removed` (meta `product_id`, `reason`), `report.resolved` and `report.dismissed` (meta `type`, `reports` = number closed, `action`, `note`), plus the existing `product.taken_down`; `user.suspended` (meta `reason`) and `user.restored` (meta `previous_reason`). Meta holds ids, enum values and short staff-written text, never names, e-mails or comment bodies.
- **Suspension.** Admin only. The target must not be the admin themselves or any admin: `403 cannot_suspend` (so the last admin cannot be locked out). Suspending signs the member out everywhere (`SignOutEverywhere`: sessions, tokens, remember token) and blocks every authenticated action, sign-in included; it is **idempotent** (an already suspended member keeps the first reason and time; no second audit row, no second sign-out). Their products, comments, votes and follows **stay public as they are**; comments and profiles show the member reduced. Open Reverb sockets are **not** closed: a private channel subscribed before the suspension keeps receiving frames until it reconnects (the next authorisation is refused). Restoring clears the reason and time, writes the old reason into the audit row (`previous_reason`) and does not bring sessions back (the member signs in again); restoring a member who is not suspended changes nothing. A deleted member is 404 on both. Lock order: the active-admin set (id order, the actor must be in it), then the target row `FOR UPDATE`; no product row.
- **Deleted members' comments (`DeleteMemberComments`).** `DELETE /me` dispatches it after its commit. Unique per user (`$uniqueFor` 3600), `$timeout` 60, 3 tries with backoff 10 s then 60 s. It walks the deleted member's not-yet-erased comments in id order, 100 at a time, each in its own transaction under the **product row** (then the top-level parent, the comment, its open reports), so it never runs inside a transaction that holds a user row. For a counted comment it wipes the body and corrects `comments_count` and the parent's `replies_count` (the ghost counters); a staff-removed comment only loses its body (it was uncounted already, `removed_*` stays); a taken-down product's comments are erased too; the comment's open reports are closed as `resolved` by the system with the note `account_deleted`. A run has a 45 s budget: when it is over, it hands the rest to a fresh job through the bus dispatcher (the unique lock is still held by the running job) and returns normally. At the end it closes the deleted member's **own** open reports as `dismissed` by the system (note `reporter_deleted`), but only for targets that have **no other eligible open report** (otherwise they stay until a resolve closes them together). A job for an account that is not deleted does nothing. If anything fails it is reported and the job fails (retried); the hourly sweep at minute 30 re-dispatches for deleted members who still own comments that were not erased or whose reports can be closed, and exits non-zero if a dispatch failed. **Production needs an async queue and a running worker for this job.**

### Error codes

All `{"message", "code"}`: `edit_window_closed` (409), `self_follow` (403), `member_unavailable` (409), `own_content` (403), `already_reviewed` (409), `report_limit` (409). Existing codes still apply to the same routes (`email_unverified`, `account_unavailable`, 401, 429 from the limiters). Validation failures are 422 (array, object, null and empty payloads included, never 500). Phase C adds `cannot_suspend` (403) and reuses `invalid_status` (409) for `take_down` on a product that is neither live nor taken down.

### Lock order (extended; do not break it)

1. A member's product rows (id order); `FreezeDay` locks a day's rows in id order. **Every comment action locks exactly one product row.**
2. The active-admin set (id order).
3. Then, in this order: **comment rows (the top-level parent first, then the reply), open report rows of one target (id order)**, topic rows (`FOR SHARE` in follows, `FOR UPDATE` in `DeleteTopic`), and finally **user rows** (the actor `FOR SHARE`, the audit foreign key `FOR KEY SHARE`, a target user `FOR UPDATE`).

Notes: the product lock serialises all comment writes of a product. A follow takes only user rows (actor and target `FOR SHARE` in one statement, id order) and never a product row; `FollowTopic` takes the topic row `FOR SHARE` first. `CreateReport` takes the product `FOR SHARE`, the comment `FOR SHARE`, does not lock report rows, and locks the reporter's user row `FOR UPDATE` **last**. `DeleteAccount` locks the member's follow rows first (`ORDER BY follower_id, followed_id FOR UPDATE`) so two members deleting each other cannot deadlock. `DeleteTopic` writes its audit row (a user-row lock) before it deletes follow rows. `ResolveReport` reads the actor's role (no lock), locks the product row, then the comment's top-level parent and the comment, then the target's open reports, and only then writes the audit rows. `SuspendUser`/`RestoreUser` lock the active-admin set and then the target row, and no product row. `DeleteMemberComments` takes one product row per comment. No code path may take a user row `FOR UPDATE` and then a product row; background jobs take one product row per item and never inside a transaction that holds a user row.

## Notifications and email

Design: `../docs/superpowers/specs/2026-10-21-notifications-design.md` (its section 17 lists every place the build differs from the spec); plan: `../docs/superpowers/plans/2026-10-21-notifications.md`. Phases A (the bell), B (preferences, unsubscribe, the outbox, the producers, the reconcile sweep, longer auth-mail retries) and C (launch reminders, digests, retention, account deletion) are built.

### Two stores

- **The bell** (`notifications`): what a member sees on the site. Written for every event, whatever the member's mail settings, and also for an unverified member. A deleted or suspended recipient gets nothing.
- **The outbox** (`email_messages`): the mail. Written in the **same transaction** as the bell row by one action (`RecordNotification`), so mail survives a provider outage and exists before the first attempt. Duplicates are impossible: both rows carry idempotency keys derived from the event (`review:{review_id}`, `product_live:{product}:{launch_date}`, `comment:{comment_id}`, and the matching mail keys; the hour in a comment mail key comes from the comment's `created_at`, never the clock).
- **Notification types** (the bell): `review_approved`, `review_rejected`, `review_changes_requested`, `launch_reminder`, `product_live`, `new_comment`. The bell `data` is `{message_key, product_id?, review_id?, comment_id?, params, href}`: ids, a key such as `notifications.new_comment`, scalar params (`product_name`, `kind`) and an app-relative `href`. Clients render `params` as text, never as HTML. Comment text and reviewer notes are never in `data` or in any mail.
- **Mail types.** Mandatory (always sent, cannot be switched off, their own queued notifications with 8 tries over about 2.9 hours: waits 60, 120, 300, 900, 1800, 3600, 3600 s; the password-reset job waits 65 s first): `verify_email`, `reset_password`, `email_change`. Optional (go through the outbox, default **on**, one preference each): `review_outcome` (approved, rejected and changes requested share it), `launch_reminder`, `product_live`, `new_comment` (a comment or a reply), `daily_top5`, `weekly_best`. `export_ready` joins the list in a later sub-project.
- **Who is told about a comment.** The product's maker (kind `comment`) and the person answered by a reply (`reply_to_user_id`, else the top-level author; kind `reply`). Distinct, never the commenter; if they are the same person that person gets one row of kind `reply`. At most one mail per recipient, product, kind and UTC hour. The reply mail subject is fixed text ("New reply to your comment").
- **Preferences gate email only.** The bell always gets the notification. The preference is checked when the row is enqueued (to save a row) and again at **send time**: a message whose type was switched off meanwhile becomes `suppressed` (`last_error` `opted_out`) and is never sent. An unverified, suspended or deleted recipient is suppressed too.

### Endpoints (all under `/api/v1`)

Member routes carry `auth:sanctum` and `active` but **not** `verified.api`: an unverified member can read and clear the bell and switch mail off. All are `Cache-Control: no-store, private`. Errors are the framework bodies (401, 403 `account_unavailable`, 422 `{message, errors}`, 429), no invented codes.

| Method and path | Auth, limiter | Answer |
|---|---|---|
| `GET /me/notifications?per_page=&cursor=&unread=0\|1` | member | newest first, keyset `(created_at, id)` with a UUID cursor; `per_page` 1 to 50, default 20; `unread=1` lists unread only (`0`, absent and empty mean all); `{"data": [{id, type, data, read_at, created_at}], "links", "meta"}`; `links.next` carries `per_page` and `unread` |
| `GET /me/notifications/unread-count` | member | `{"unread_count": n, "capped": bool}`; counts up to 100 (`capped` is true at 100) |
| `POST /me/notifications/read` | member, `notifications-read` | body is **exactly one** of `{"ids": [uuid, ...]}` (1 to 50, a JSON list) or `{"all": true}` (the JSON boolean); `{"marked": n, "unread_count": m}`. Foreign, unknown and already-read ids are silently not counted (no oracle); a repeat marks 0; `all` marks what existed when the call started |
| `GET /me/email-preferences` | member | `{"data": [{type, enabled}]}` for the six optional types, in a fixed order; a missing row reads as `true` |
| `PATCH /me/email-preferences` | member, `email-prefs` | body `{"preferences": {"daily_top5": false, ...}}`, a non-empty object, partial, every value a real JSON boolean (not `0`, `1`, `"false"`); only the optional types; returns the full list |
| `POST /email/unsubscribe?user=&type=&signature=` | public, `unsubscribe`, `signed:relative` | `200 {"type": "...", "enabled": false}` for every valid signature, whether or not anything changed or the member exists. A bad or missing signature is the framework 403; a mandatory or unknown type with a valid signature is 422 |

The unsubscribe route is **excluded from CSRF** (`preventRequestForgery(except: ['api/v1/email/unsubscribe'])`): mail clients POST it without a cookie or token, and a request from the SPA's stateful origin must not become a 419. The signature has no expiry (an old mail must still work) and covers one member and one type; the body is ignored.

### The outbox

`launchpad:send-emails` runs every minute (`withoutOverlapping(5)`, `onOneServer()`).

- **States.** `queued` -> `sending` -> `sent`; a transient failure goes back to `queued` with the next wait; `failed` is **final only** (a permanent error, or the ninth attempt used); `suppressed` means it must not be sent (opted out, unverified, suspended, deleted, `target_gone`, `user_missing`). Constants live in `config/launchpad.php` under `outbox`: batch 50, run budget 50 s, lease 10 min, 9 attempts, breaker 5, waits 1, 5, 15, 60, 180, 360, 720, 1440 minutes.
- **Claim.** Up to 50 due rows are picked `FOR UPDATE SKIP LOCKED` inside a `MATERIALIZED` CTE, marked `sending` with a lease of 10 minutes in `next_attempt_at`, and their attempt is counted, all in one short transaction that commits **before** any send. A claim is identified by `(id, attempts)`: results are recorded only `WHERE status = 'sending' AND attempts = ?` under a row lock, so a worker whose lease expired cannot overwrite a re-claim. A lease-expired row already at its ninth attempt becomes `failed` (`lease_expired`) instead of being claimed again.
- **Send.** Lock and re-check (the send-time rule, the member's current address), render and send **outside** any transaction, lock again and record. Delivery is **at least once**: a crash after the provider accepted but before the record sends again after the lease, with the same `Message-ID` (`<row id@mail domain>`), so clients can collapse the copy. `provider_message_id` stores that header, **not a provider receipt**.
- **Failures** are classified by exception class and structured status, never by message text. Permanent (`failed` at once): `RfcComplianceException`, an unrenderable payload, an HTTP transport 4xx other than 408 and 429, and an SMTP `TransportException` whose code is 500 to 599 (a hard bounce). Everything else, including SMTP 4xx and no code (a dropped connection), is transient. They are logged as `outbox.send_failed` warnings with a redacted summary (class and message with addresses replaced by `[email]`), never `report()`ed; `last_error` keeps the same summary (500 characters) or a reason code.
- **Backoff and the breaker.** After attempt n the row waits 1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h; the ninth failure is `failed`. After 5 consecutive transient failures the run stops (the provider is down) and gives the unsent claims back: `queued`, due now, with their attempt taken off, so an outage does not burn the retry budget of mail it never tried. A run also stops at its 50 s budget.
- **Exit codes of `launchpad:send-emails`.** Non-zero after any transient failure or retry, any lease-exhausted row (lost mail) or a tripped breaker, so a monitor sees an outage. A permanently failed row (a bad address) is final: it is printed and logged and the run exits 0.
- **`launchpad:reconcile-notifications`** (hourly at minute 45, lock 30): for the last 48 hours it re-derives the review outcomes, the products that went live and the comments that still count from state and records them through the same code and keys as the listeners. A normal run creates nothing; a lost queue job, or a lost outbox row for an existing bell row (the repair rule), is filled in. Non-zero if an item failed. The retention window of `email_messages` must stay at least 72 hours (the purge enforces the floor): a purged `sent` row frees its idempotency key.
- **`launchpad:requeue-failed-emails [--since=48h] [--type=...]`** is manual (not scheduled): it puts `failed` rows back in the queue (attempts 0, due now), for example after a provider misconfiguration failed a batch. `--since` looks like `36h` or `7d`.
- **Unrenderable mail.** An unknown type or a bad payload fails the row; a product that is gone or no longer visible suppresses it (`target_gone`).

### Launch reminders

- `launchpad:queue-launch-reminders` runs every 10 minutes (lock 15, in the background). On the day before a launch (UTC), **from `digest.daily_send_at_utc` until midnight**, it finds every product that is `scheduled` with `launch_date` = tomorrow (not soft-deleted, maker neither deleted nor suspended) and records a `launch_reminder` bell row and outbox mail for the maker. Before the send time it prints "outside the send window" and exits 0. It is chunked by product id (200), one try/catch per product, and exits non-zero if any item failed.
- **Once per product and launch date.** The date is in both keys (`launch_reminder:{product}:{date}` and the mail key), so a re-run creates nothing and a reschedule to another date creates one more reminder when that date's window opens.
- **The link is the maker's own page**, `/me/products/{id}` (a scheduled product is not public, its public page is a 404).
- **The mail is re-validated at send time.** The recipient must still be the product's maker, the status still `scheduled` and the launch date still that day; otherwise the row is `suppressed` with `target_gone`.

### Digests

- `launchpad:queue-digests` runs every 10 minutes (lock 60, in the background). **Daily top five:** every day from `digest.daily_send_at_utc`, about the UTC day that just ended; the list is that day's frozen ranking (`daily_rankings`) **filtered by `visible()` and an available maker before the first five are taken** (frozen ranks are not dense once a product is taken down); subject "Yesterday's top 5". **Weekly best:** on Mondays from `digest.weekly_send_at_utc`, the best five of the Monday-to-Sunday week that just ended (`Leaderboard::ranking('week', ...)` with a `through` upper bound of that Sunday, so Monday's launches never count). The weekly run's period is the Monday of the week covered.
- **Settings.** `digest.daily_send_at_utc` and `digest.weekly_send_at_utc` are `HH:MM` strings and must be **between 00:00 and 21:59 UTC** (the write rule rejects later times, so the 23:00 give-up hour always leaves room). `Settings::timeOfDay()` re-checks on every read and falls back to the default (`07:00`) with a logged warning (key only) for a stored value that is not `HH:MM` before 22:00.
- **Who gets it.** Every member who is verified, not suspended, not deleted and not opted out of that type (all roles). A digest has an outbox row but **no bell row**.
- **`digest_runs`.** One row per kind and covered period (`kind`, `period_date`, `product_ids`, `last_user_id` cursor, `enqueued`, `started_at`, `completed_at`). The products are chosen once, so every member sees the same list. Recipients are read in chunks of 500: each chunk is **one transaction** that locks the run row `FOR UPDATE`, reads the users `FOR SHARE` in id order (a concurrent account deletion finishes first), inserts the outbox rows (`ON CONFLICT DO NOTHING`, key `{kind}:{period}:{user}`) and moves the cursor. A crash resumes at the cursor; a finished run is one cheap lookup.
- **Resume and abandon.** An open run is resumed by the next tick for up to **18 hours** after it started, even past midnight (its period stays its own). An older one is **abandoned**: `completed_at` is set, a warning is logged once, it counts as a failed kind and the command exits 1. A run with `completed_at` set but `enqueued` below the number of eligible members is therefore an abandoned run, not a finished one.
- **Empty days wait.** A day with no `daily_rankings` rows yet (not frozen, or truly empty) makes no run row until 23:00 UTC; from then it is closed with `enqueued = 0`. If the rows exist but none is visible any more, it closes with 0 at once. A digest whose products have all disappeared by send time is `suppressed` (`target_gone`).

### Account deletion and notifications

`DELETE /me` (inside its transaction, after the member's user row is locked `FOR UPDATE`): the member's bell rows and `email_preferences` are **deleted**; every `email_messages` row of the member gets the anonymised address (`deleted+<id>@invalid`) and loses its `last_error` (it may quote the address); rows that were waiting (`queued` or `sending`) also become `suppressed` with no due time, an empty payload `{}` and `last_error` `user_deleted`. Lock order: the user row, then the outbox rows (updated, never locked first; a sender holds an outbox row without asking for a user lock). `RecordNotification` reads the recipient `FOR SHARE`, so it finishes first or finds a deleted member. **Caveat (at-least-once):** a row already in flight when the account is deleted can still be delivered once to the old address; the send-time check refuses a deleted member for everything not yet claimed.

### Operations and open items

- The compose file has **no queue worker or scheduler service**; run `php artisan queue:work` and `php artisan schedule:work` (or cron) yourself. Without the scheduler no digest, reminder or outbox send happens at all; without the worker the listeners and auth mails never run.
- **Provider caveats.** SMTP rejections are classified by the server's reply code (5xx permanent, 4xx and a dropped connection transient); an HTTP mailer is classified by its structured 4xx status (other than 408/429). A provider outage loses nothing: rows wait in the outbox and the run's breaker stops after 5 consecutive transient failures.
- **Settings-enforcement rule.** Every `retention.*` key is enforced by a purge (the last one, `retention.risk_signals_days`, by `PurgeRiskSignals`). Rule: a setting is added in the phase that enforces it, and every new key gets a test that changing it changes behaviour.
- **Standing rule:** *Mail goes through the outbox (`RecordNotification`); never send event-driven mail directly.* The only mail outside the outbox is the three auth mails, which keep their own queued notifications.
- **Open items (not built):** a staff view of the outbox and a manual resend (only `launchpad:requeue-failed-emails` exists), bounce and complaint webhooks, per-member time zone and quiet hours, push notifications, a queue-depth metric, collapsing the bell for hot products, moving the auth mails into the outbox, an unsubscribe-all link, compose services for the worker and scheduler, and `export_ready` joining `EmailType::preferenceTypes()` in sub-project 7.

### Production requirements

- `QUEUE_CONNECTION=database` or `redis`, **never `sync`**, with a running worker (the listeners that produce notifications are queued; so are the three auth mails and the broadcasts) **and** the scheduler (`launchpad:send-emails` and the reconcile sweep are scheduled commands). Without the scheduler no event-driven mail is ever sent, although the rows pile up safely in the outbox.
- `MAIL_MAILER` set to a real provider (`.env.example` ships `MAIL_MAILER=log`, which writes mail to the log and sends nothing) and **`MAIL_TIMEOUT=20`** (the smtp mailer's `timeout`; without it a hung provider would stall a run until the 5-minute overlap lock expires).
- **`APP_URL` and `WEB_URL` must be public https URLs.** Mail links are built from them (the unsubscribe headers from `APP_URL`, the footer link and product links from `WEB_URL`). In production a base that is not https or whose host is empty, `localhost`, a loopback address or `0.0.0.0` makes the row fail as unrenderable instead of sending dead links.
- **The web app must serve `/unsubscribe`** and forward the signed query string **verbatim** (`user`, `type`, `signature`) to `POST /api/v1/email/unsubscribe`. The page must POST **only on an explicit click** (a GET prefetch by a mail scanner must change nothing) and send `Referrer-Policy: no-referrer` so the signed URL never leaks.
- The Sail `compose.yaml` has no worker or scheduler service: run `php artisan queue:work` and `php artisan schedule:work` yourself when testing mail locally.
- Known trade-offs: the bell is never gated by preferences (by design); a crash between "provider accepted" and "recorded" can resend once; every old test now runs the producers under the sync queue.

## Search

Design: `../docs/superpowers/specs/2026-10-21-search-design.md` (its last section lists every place the build differs from the spec); plan: `../docs/superpowers/plans/2026-10-21-search.md`. PostgreSQL only: no search service, no table, no job.

### Endpoint

`GET /api/v1/search` is **public** (a signed-in caller, by token or SPA session, also gets `has_voted`). Query string only (a body is ignored).

| Param | Rule |
|---|---|
| `q` | optional text, at most 100 characters. Arrays and objects (`q[]=`, `q[a]=`) and non-text are 422; a repeated scalar (`?q=a&q=b`) is last-wins, as everywhere in PHP. Absent, empty and whitespace-only count as absent |
| `topic` | optional topic slug; an unknown slug is 422 (like `/leaderboards`) |
| `sort` | `relevance`, `newest` or `votes`. Default: `relevance` when `q` has a usable token, `votes` for a topic alone. `relevance` without `q` is 422 |
| `per_page` | integer 1 to 50, default 20; an empty `per_page=` is 422 |
| `cursor` | optional; shape-checked for the sort; empty is page one |

- **What `q` becomes.** Only letters, combining marks and digits survive; everything else (punctuation, operators, quotes, newlines) is a word break, so no input can form a query operator or a syntax error. ZWNJ and ZWJ stay inside a token (Persian and Bengali words). A token needs at least 2 characters and one letter or digit (shorter ones are dropped), is cut at 50 characters, and at most **8 tokens** are used. NFKC and lower case are applied in PHP; accents and case are folded in the database.
- **Empty or useless input.** Neither `q` nor `topic` is 422 (`Enter something to search for, or choose a topic.`). A `q` with text but no usable token (for example `a`, `!!`, `?`) is 422 on `q` with **`Enter at least two letters or digits.`** (with a `topic`, a useless `q` is still 422 rather than silently ignored).
- **Matching.** Searchable text is the product `name` (weight A), `tagline` (B) and `description` (C), nothing else: not topic names, not maker names, not links. Text config `simple` (no stemming, no stop words; the site is multi-language) after one shared fold function `launchpad_fold` (NFKC, `unaccent`, lower case, ASCII punctuation to spaces). **Every token must match** (AND); the **last token also matches as a prefix** (as-you-type). **Typo tolerance** (trigram `word_similarity >= 0.5` on the folded name) applies **only to a single-word query of at least 3 characters**; with two or more tokens every token must match in the full text. Transposition typos (`lauchnpad`) score below the threshold and are a documented limit; so are names such as `c++` or `c#` (the punctuation is a separator).
- **Relevance score** (sort `relevance`): `2 * (full-text hit) + 2 * ts_rank_cd(name A / tagline B / description C) + word_similarity(query, folded name) + (name equals the query exactly)`, rounded to 4 decimals. Any full-text hit scores at least 2 and a typo-only hit at most 1, so fuzzy matches always come after real ones. Ties fall to `votes_count` desc, then id. A **lone token under 3 characters** uses a cheap score (tier + exact-name bonus only, no `ts_rank_cd`), so the order falls back to votes; this keeps two-letter prefixes fast.
- **Visibility.** `Product::visible()` plus `whereMakerAvailable()`, the same two scopes as every public list, plus the partial indexes' own `status = 'live' AND deleted_at IS NULL`. Drafts, pending, approved, scheduled, rejected, taken-down, deleted and future-launch products and products of deleted makers are never returned, and the answer looks like a search that found nothing (no total, no count of hidden rows).
- **Sorts.** `votes` is exactly the leaderboard order (`votes_count desc, launch_date desc, id`); `newest` is `launched_at desc, id` (launched products only).

**Response** (200, also when nothing matches): `{"data": [card], "links": {"next": url|null}, "meta": {"query", "topic", "sort", "per_page", "next_cursor"}}`. A card is the public product card (`ProductResource`) with `votes_count`, `comments_count` and, for a known viewer only, `has_voted`; no `rank`, no score. `meta.query` is the **tokens the server used** (NFKC, lower case, joined by one space; accents kept, so the client highlights accent-insensitively) or `null`; there is no highlighting in the API and no `total`. `links.next` repeats `q`, `topic`, `sort`, `per_page` and the cursor, so a client can follow it as it is (when NFKC expansion made the normalised phrase longer than 100 characters, the link carries the cleaned text as typed, which parses to the same tokens).

**Cursors** are keysets written by hand (relevance `score, votes_count, id`; votes `votes_count, launch_date, id`; newest `launched_at, id`) and **bound to the search** by a 12-hex-character hash of the sort, the tokens and the topic: a tampered cursor, one from another query/sort/topic, or one with the wrong keys is 422. There is no snapshot: while votes move a row can repeat or be skipped between pages.

**Failure and caching.** The search statement runs under a time cap, `SEARCH_TIMEOUT_MS` (`config('launchpad.search_timeout_ms')`, default 2000 ms); past it the answer is `503 {"message", "code": "search_timeout"}` with `Retry-After: 2`, decided on SQLSTATE 57014 only. Cache headers are the shared optional-viewer policy: `Vary: Authorization, Cookie` always, `no-store, private` for a known member. `429` comes from the `search` limiter (see Rate limits) or `api`. Search writes nothing and logs nothing: no search history, no audit row; recent searches belong in the client's local storage.

### Performance (50,000 live products, 1,500-character descriptions, measured in Phase B)

| Query | Database time |
|---|---|
| a selective word | about 5 ms |
| a single-word typo | about 10 ms |
| a very common word | about 370 ms |
| a two-character prefix (before the cheap-score change) | about 500 ms |
| `votes` / `newest` sorts | 1 to 2 ms |
| a topic alone | about 20 ms |

The spec budget was 50 ms for relevance, which a common word does not meet: relevance costs O(matches) per page. The plan tests assert the plan shape (a `BitmapOr` over both GIN indexes, no `Seq Scan on products`, no `Sort` for votes and newest) on an analysed indexed copy of the table.

### Operations and known limits

- **Extensions.** `CREATE EXTENSION unaccent` and `pg_trgm` run in a migration (`IF NOT EXISTS`); both are trusted extensions, so the database owner may create them, but the production role needs that permission (managed hosts offer both). The migration also creates `launchpad_fold` and `launchpad_prefix_tsquery` with `CREATE OR REPLACE` (`migrate:fresh` keeps functions).
- **The migration rewrites `products`.** Adding the two stored generated columns (`search_vector`, `name_search`) takes an `ACCESS EXCLUSIVE` lock and rewrites every row (reads and writes wait), and the two plain `CREATE INDEX` statements block writes while they build. On a big table run it in a maintenance window, or add the columns first and create the two indexes by hand with `CREATE INDEX CONCURRENTLY` (same names and definitions).
- **Changing the fold.** Changing `launchpad_fold` or the `unaccent` rules does not recompute stored values: rebuild both generated columns and both indexes in one maintenance migration.
- **Width.** The weighted tsvector is stored in the product row (about 4 KB per product on average), and every `Product` read is `select *`, so it travels with every product query (including `lockForUpdate` reads). Follow-up: a lean global scope or a `product_search` side table.
- **Vote writes.** A vote updates `products.votes_count`, which is an indexed column, so the update is not a HOT update and writes a new entry into every index of the row, including the two partial GIN indexes (live products only). That is extra write cost per vote; the follow-up is the same side table.
- **Deep pages** of the `votes` sort scan every row before the cursor (the OR-chain keyset, as on the other lists).
- **Short queries** can match name tails (`pad` finds `Launchpad`).
- Search adds no scheduled command and no `SettingKey` (the cap is config, not a setting).

## Views and the founder dashboard

Design: `../docs/superpowers/specs/2026-10-21-founder-dashboard-design.md` (its last section lists every place the build differs from the spec); plan: `../docs/superpowers/plans/2026-10-21-founder-dashboard.md`. Phases A (view counting), B (the dashboard reads) and C (exports) are built.

### The view beacon

`POST /api/v1/products/{id}/view` is public (a signed-in caller is recognised only to skip the maker and staff). Optional JSON body `{"referrer": "<document.referrer>"}`; the web app sends `document.referrer.slice(0, 2048)` once per page load after render. No CSRF token is needed (the path is excluded in `bootstrap/app.php`).

- **Answers.** `204` with no body for every valid request, whether or not the view was counted (so a caller learns nothing); `404` (the one uniform body) for a product the public cannot see (draft, pending, scheduled, taken down, deleted, deleted maker, future launch, non-ULID, unknown); `422` for a `referrer` that is not a string or is longer than 2048 characters (an array, object or number too); `429` from the `view` limiter. A `referrer` with invalid UTF-8 is **ignored** (counted as `direct`), not an error; a body that is not a JSON object, a query-string referrer and a non-JSON body mean "no referrer". The response is `Cache-Control: no-store, private` (every non-GET under `products/*`).
- **What counts.** A publicly visible product (`visible()` plus an available maker), on or after its launch day; **not the maker and not staff** (a signed-in maker or reviewer previewing the page is skipped); not a bot; **one view per device per product per UTC day**, where the device is the HMAC of the `lp_did` cookie or the `X-Device-Id` header. A cookie-less client gets a fresh device id on every request, so there is also a per-IP cap: **at most 25 counted devices per IP per product per UTC day** (IPv6 is reduced to its /64 first, an IPv4-mapped IPv6 address counts as its IPv4). The cap counts **first sightings only** and is checked **before the device's dedupe key is created**: a repeat view by a known device never consumes it (an office or carrier NAT refreshing a page does not burn the cap), and an address at its cap creates no new keys, so capped traffic does not grow Redis.
- **Bots.** An empty user agent is never counted; neither is one containing a denylist word (`config/launchpad.php`, `views.bot_agents`: `crawl`, `spider`, `headless`, `curl`, `okhttp`, `axios`, `python-requests`, `whatsapp`, ...). `bot` matches as a whole word or after a lower-case letter (`Googlebot`, `AhrefsBot`) but not inside an all-caps brand (`CUBOT` handsets are counted). **The mobile app must send a custom User-Agent**: the default `okhttp` is on the list.
- **Referrer sources.** The referrer URL is reduced to a host and the host to one source key; path, query, fragment, credentials and port are discarded and never stored. Order: no/invalid/over-long/non-http(s) referrer is `direct`; the site's own hosts (`WEB_URL`, `SANCTUM_STATEFUL_DOMAINS`) are `launchpad`; a host matching the allowlist in `config/referrers.php` (suffix on a label boundary, so `evilgoogle.com` is not `google`) becomes its key (`google`, `bing`, `duckduckgo`, `ecosia`, `yahoo`, `x`, `facebook`, `linkedin`, `reddit`, `hacker_news`, `youtube`, `mastodon`, `bluesky`, `github`); any other valid host (it must contain a dot and end in an alphabetic or punycode label, 80 characters at most, never an IP) is stored as itself; anything else is `other`. Categories are derived from the config, never stored: `search`, `social`, `internal` (launchpad), `direct`, `other`. **At most 50 distinct sources per product per day**: new sources beyond that fold into `other` at flush time.

### Where the counters live (Redis)

- Two connections to the same Redis database, both in `config/database.php`: `views` (used by the flush and diagnostics; 1 s connect, 5 s read) and `views_beacon` (used only by the beacon: **0.25 s timeouts, no retries**, so a dead Redis costs a request a quarter of a second). The database number is `REDIS_VIEWS_DB` (`.env.example`: 2; `phpunit.xml` forces 15 so a test run cannot wipe dev data). If Redis is down the view is dropped, the request still answers 204, and the outage is `report()`ed at most once a minute.
- All access goes through `App\Support\ViewCounters` (one Lua script, every key passed in `KEYS`). Keys: `view:{product}:{Ymd}:{device}` (dedupe, 25 h), `viewip:{product}:{Ymd}:{ip}` (the per-IP counter, 25 h), `views:pending` (hash of counts), `views:seq`, `views:batches` (set of rotated, not yet dropped batch ids), `views:flushing:{n}` (a rotated batch) and `views:claim:{n}` (a runner's claim, 600 s). Keys hold HMACs and counts only, never an IP, a device id or a URL.
- **`launchpad:flush-views`** runs every minute (lock 5). It renames `views:pending` to `views:flushing:{n}` and, for that batch and any older batch a crashed run left, **claims** it (`SET NX EX 600`; a second runner skips it), then writes it in **one transaction**: upserts `product_hourly_stats.views` (bucketed by the arrival hour) and `product_referrers_daily.visits` (counts are added, never replaced, so a bucket made by a vote only gains views). Only after the commit is the Redis batch dropped. A batch that throws is rolled back, reported, counted and stays for the next run; the command exits non-zero. A product that no longer exists is skipped and counted.
- **Lock order.** Before it inserts any bucket the flush locks the batch's product rows `FOR KEY SHARE` **in id order** (never `FOR UPDATE`). Inserting a bucket takes a key share on its product row through the foreign key, after the new tuple exists; without the early share lock a vote that holds the product row `FOR UPDATE` and waits on the flush's uncommitted bucket deadlocks with it (reproduced in a two-session test). A key share never blocks a vote's counter update.
- **Counters are approximate.** A drop that fails after the commit makes the next run count that minute a second time; a Redis restart without persistence loses up to a minute of counts. The invariant per product and day is `sum(visits) = sum(views)` of its hourly buckets. Production: set `REDIS_VIEWS_DB` to a database that is not shared with the cache or queue (a `flushdb` of one must not touch the other), never give that database an `allkeys-lru` eviction policy, and enable Redis persistence if losing a minute matters.

### Dashboard reads

Eight read-only GETs, all `no-store, private`, all with the `dashboard` limiter, all UTC. The maker's: `GET /api/v1/me/products/{id}/dashboard` (summary), `.../dashboard/series`, `.../dashboard/rank`, `.../dashboard/referrers`. The staff mirrors (reviewer or admin, read-only support): the same four under `GET /api/v1/staff/products/{id}/dashboard...`. There are **no public numbers**: view counts are visible to the maker (and staff) only.

- **Authorisation.** `me/` routes: the maker of a non-deleted product, else the uniform `404` **whatever the query parameters are** (a stranger never sees a 422; a reviewer on a `me/` route is simply not the maker). `staff/` routes: the role gate first (non-staff `403`, identical for an existing and an unknown id), then `404` for an unknown, non-ULID or deleted id, then the policy (`viewStaff`: a draft is `404`). The id is a plain string; authorisation runs before validation.
- **Parameters** (`series` and `referrers`; query string only): `from` and `to` as `YYYY-MM-DD` real calendar dates in 2000 to 2099 (anything else is 422), `granularity` = `hour` (default) or `day` (series only). `summary` and `rank` take no parameters and ignore any. Caps: an hourly window spans at most **168 hours (7 days)**, a daily window or a referrers window at most **366 days**; a written `from` after `to`, or a written span over the cap, is 422. The window is then **clamped** to `[launch_date, today]`. Defaults: an hourly series is the launch day (24 points); a daily series and the referrers run from the launch date to today (limited to the last 366 days for an older product, so a product older than a year does not fail its own default read). With only `from`: it is clamped up to the launch date, then an hourly window runs one cap's worth of days from there (a daily window runs to today). With only `to`: `from` is the launch date or the cap's start, whichever is later. A product before its launch has no window at all (empty answer, `from`/`to` null).
- **Summary** `{"data": {product_id, name, slug, status, phase, launch_date, taken_down, views, votes, comments, conversion, current_rank, best_rank, final_rank, top_source, timeline_available, as_of}}`. `phase` is `before_launch`, `launch_day` or `after` (a taken-down product keeps the phase of its launch date and its history stays visible to the maker). `views` is the lifetime total of the hourly buckets, `votes` and `comments` come from the product's own counters, `conversion` is `votes / views` rounded to 4 decimals (`null` with no views), `top_source` is `{source, category, visits}` or `null`.
- **Series** `{"data": {granularity, from, to, points: [{t, votes, views, cumulative_votes, cumulative_views}]}}`: **zero-filled** (a point for every hour or UTC day of the window), and the cumulative curve continues from the totals between the launch and the window start, so a later window joins the same curve. Hours before the launch are never counted.
- **Rank** `{"data": {launch_date, phase, frozen, final_rank, current_rank, best_rank, best_rank_at, timeline_available, interval_minutes: 10, points: [{t, rank, score}], outside_top: 500}}`. `current_rank` is the product's **position on today's whole list** (the feed's own query, `TodayRanking::positionOf`, a single SQL statement), **not capped at 100**; it is `null` outside the launch day or when the product is not live. `final_rank` is the frozen rank in `daily_rankings`. `best_rank` is the best 10-minute mark of the launch day (marks outside the top 500 do not exist; nothing is inverted or smoothed). `timeline_available` is true while the launch day's end is inside the `retention.rank_snapshots_days` window (180 days by default); after that `points` is empty and `best_rank` is `null`, but `final_rank` stays.
- **Referrers** `{"data": {from, to, total, sources: [{source, category, visits, share}], by_category: [{category, visits, share}]}}`: the top 10 sources by visits then name (byte order), everything below folded into one `other` row, `share` to 4 decimals.
- **Cache.** Each read is cached for `DASHBOARD_CACHE_SECONDS` (`.env.example`: 30; `phpunit.xml` pins 0) per product, endpoint and query, with the product's **status in the key** (a take-down or restore never sits behind a stale entry); no member id is in the key (authorisation always runs first). A name edit can therefore show up to 30 seconds late. A cache failure is reported and the answer is computed. Nothing in a dashboard response is shared between products or holds personal data.

### Exports

A maker can download their own numbers as a zip of four CSV files. **Only the maker** can export: there is no staff export route (a staff member on a `me/` route is simply not the maker: 404).

| Method and path | Answer |
|---|---|
| `POST /api/v1/me/products/{id}/exports` (`export-start`) | `202` with the new export; `200` with the export already `queued` or `processing`; a request after a `ready` one **replaces** it (the old row becomes `expired` and its zip is deleted after the commit) and answers `202`; after a `failed` or `expired` one a new export starts at once |
| `GET /api/v1/me/products/{id}/exports` (`export-poll`) | `{"data": [export]}`: the latest five of the product, newest first |
| `GET /api/v1/me/products/{id}/exports/{export}` (`export-poll`) | `{"data": export}`; while `queued` or `processing` it carries `Retry-After: 5` (poll at that pace) |
| `GET /api/v1/me/products/{id}/exports/{export}/download` (`export-download`) | the zip as an **authenticated stream** (`Content-Disposition: attachment; filename="launchpad-{slug}-{Ymd}.zip"`, `Content-Type: application/zip`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`); `409 {"code": "export_not_ready"}`, `410 {"code": "export_expired"}` (also when `expires_at` has passed before the purge ran, or the file is gone); a stranger's, another product's, an unknown or malformed id, or a staff member is the uniform `404` |

- **An export** is `{id, product_id, status, created_at, completed_at, expires_at, file_size, row_counts, error}`; never a file path, a disk or a URL (the client builds the download path itself; the web app fetches it with its session or bearer token as a blob). `status` is `queued`, `processing`, `ready`, `failed` or `expired`; `error` is a short code only (`generation_failed`, `too_large`, `stalled`), never exception text; `row_counts` is `{summary, hourly, referrers_daily, rank_timeline}` once ready. At most one export per product is `queued`, `processing` or `ready` (the partial unique index `exports_one_active_per_product`); a parallel request that loses the race gets the winner's export.
- **The zip** holds `summary.csv` (`key,value` rows: `product_name`, `product_slug`, `launch_date`, `generated_at_utc`, `views`, `votes`, `comments`, `conversion`, `final_rank`, `best_rank`, `current_rank`, `timeline_available`, `hourly_truncated`), `hourly.csv` (`hour_start_utc,votes,views,cumulative_votes,cumulative_views`), `referrers_daily.csv` (`day,source,category,visits`) and `rank_timeline.csv` (`captured_at_utc,rank,score`). `hourly.csv` covers the launch day through `min(today, launch + 365 days)` (the first 366 days, at most 8,784 rows); `hourly_truncated` is `true` when the product is older. All four are built from the same Actions the dashboard reads (never from its cache), at one instant, inside one `REPEATABLE READ, READ ONLY` snapshot when the job runs outside a transaction (the queue worker); inside an outer transaction (the test suite) it runs plain. The settings those reads consult are warmed before the snapshot, and a failing settings cache write is swallowed and reported.
- **CSV rules.** Rows are built by hand, RFC 4180: CRLF line ends, a cell containing a comma, a quote, CR or LF is quoted and quotes are doubled. **Formula injection:** a string cell whose first character is `=`, `+`, `-`, `@`, tab, CR or LF, or whose first character after leading blanks (space, tab, CR, LF) is one of the first four, gets a leading apostrophe (`'`); numbers, booleans and instants are written as such, so a real `-3` stays `-3`. The 5 MB cap is checked twice: a cheap guard on the text before zipping and the final zip size (over it the export fails with `too_large`, which is final).
- **The job** `GenerateExport` (queued; unique per export, `$uniqueFor` 600, `$timeout` 75, 3 tries, backoff 10 then 60 s): a short transaction claims the row (`processing`), the files are built and the zip written with no lock and no transaction, a second short transaction re-checks the row and marks it `ready` with `file_path`, `file_size`, `row_counts`, `completed_at` and `expires_at` = now + `retention.exports_days`. A deleted owner or product mid-export ends the export `expired` (no file, no event). The last failed try marks it `failed` (`generation_failed`). `ExportReady` is raised once, after the finishing commit. **`launchpad:sweep-exports`** (every 5 minutes, lock 10) re-dispatches an export still `queued` after 10 minutes and fails one `processing` for 10 minutes as `stalled`, deleting any orphan zip. Production needs an async queue and a running worker.
- **Storage.** The zip lives on the private `exports` disk (`config/filesystems.php`: local `storage/app/private/exports`, no URL, no serving) at `{product_id}/{export_id}.zip`. In production point the disk at a **private S3-compatible bucket** by configuration; it must never be public. `ext-zip` must be installed.
- **The `export_ready` notification.** When the export finishes, the maker gets a bell row (`export_ready`, key `export_ready:{export_id}`; `params` are `product_name` (raw display text: clients render it as text) and `days` (how long the file is kept); `href` is `/me/products/{id}/dashboard`) and, by default, an email (the seventh optional type `export_ready`, switchable in `email-preferences`; the link goes to the dashboard, never to the file). The mail is `suppressed` (`target_gone`) unless the export is still `ready` and the recipient's own. The reconcile sweep does **not** cover `export_ready` (best effort: exports are short-lived).
- **Account deletion.** `DELETE /me` deletes the member's export rows inside its transaction (export rows are leaves of the lock order) and removes their zips after the commit; a build that finishes later finds its row gone and deletes its own file.

### Operations and open items

- **Production requirements.** `ext-zip`; a private `exports` disk (private S3-compatible bucket); an async queue and a running worker for `GenerateExport` (the sweeper is only the safety net); the scheduler; the Redis `views` database must never use an `allkeys-lru` eviction policy and should not be shared with the cache or queue.
- **Settings cache fix (affects every integer setting in production).** `Settings::get` now casts a digit string back to an integer for an integer key: the Redis cache store returns integers as numeric strings, and before this fix **every integer setting read from a warm Redis cache looked wrongly typed and silently served its default** (the retention windows, the community and notification settings). A failing cache write is swallowed and reported instead of breaking the read.
- **Open items (not built):** a signed download link for the mobile app; a kept `best_rank` or a longer rank timeline than 180 days; Do Not Track; up to a minute of view counters can be lost if Redis dies; a product name or slug edit can show up to 30 seconds late in the dashboard (the cache).

## Anti-cheat

Design: `../docs/superpowers/specs/2026-10-21-anti-cheat-design.md` (its last section lists every place the build differs from the spec); plan: `../docs/superpowers/plans/2026-10-21-anti-cheat.md`. Detection is **scheduled, not inline**: nothing runs on the vote path. It finds suspicious patterns, writes **risk signals**, scores members, and **flags** votes. It never changes a count, a ranking or an account by itself: staff do that.

### Detection: `launchpad:detect-abuse`

`launchpad:detect-abuse [--dry-run]` runs every 5 minutes (lock 10, `runInBackground`). It is stateless: it scans the products whose launch day (UTC) is yesterday or today and that have a vote (chunks of 50), then their voters (chunks of 500), then the two run-level rules; a missed run heals at the next. Every finding is written with a **dedupe key**, so a rerun creates nothing and an existing row (even a reviewed one) is never touched: its first evidence and weight stay. The weight is frozen at detection (after any discount); a later setting change never rewrites it. Evidence (`details`) holds ids and counts only, never a hash, an address or an e-mail.

Output lines: `Dry run: nothing was saved.` (only with `--dry-run`), then one line per new signal kind (`shared_device: 3`) or `No new signals.`, `users rescored: N`, `votes flagged: N, unflagged: N`, and `Failed chunks: N` with exit code 1 when any chunk failed (the other chunks' signals are saved either way). `--dry-run` runs the whole pipeline inside one outer transaction that is rolled back at the end, so the counts (including rules that read the signals written earlier in the same run, and scoring and flagging) are real and nothing persists.

| Rule | Kind | Fires when | Weight (default) | Settings |
|---|---|---|---|---|
| R1 | `shared_device` | at least N distinct accounts have an active vote on the **same product** from the same device hash inside the window (one signal per account) | 40 | `min_accounts` 3, `group_window_hours` 24 |
| R2 | `ip_cluster` | at least N distinct accounts vote on the same product from the same IP hash inside the window; the weight is multiplied by the discount (rounded half away from zero) when **every** account of the group is older than `established_days` (households, campuses and carrier NAT) | 15 | `min_accounts` 5, `established_days` 30, `discount` 0.5 |
| R3a | `vote_burst` (product form, no member) | the active votes on one product in a sliding window of W minutes reach `min_votes` **and** at least `ratio` times the product's baseline (its average per window over up to the three previous full hours since launch, never below 1; a product in its first hour is skipped). The scan reaches back W+5 minutes so a burst straddling two runs is seen. **Weight 0**: it feeds product integrity and the staff queue, it never scores a voter (a viral launch must not punish its voters) | 0 | `burst.window_minutes` 10, `burst.min_votes` 30, `burst.ratio` 4 |
| R3b | `vote_burst` (member form) | one member has at least N active votes in any M-minute stretch of the last M+5 minutes (distinct products); one signal per member per UTC day | 25 | `user_burst.votes` 8, `user_burst.minutes` 5 |
| R4 | `fresh_account` | an active vote cast less than N minutes after the account was created; once per account, ever | 15 | `fresh_account.minutes` 30 |
| R5 | `disposable_email` | the e-mail domain (the part after the **last** `@`) equals a list entry or is a subdomain of one (suffix match on a dot boundary: `x.mailinator.com` matches, `notmailinator.com` does not); the evidence names the list entry, never the address; once per account | 25 | `disposable_domains` (list) |
| R6 | `single_maker_voter` | a member with at least N active votes in the last D days gave at least `share` of them to products of one maker (compared in thousandths, no float edge at 0.8); once per (member, maker) | 20 | `single_maker.min_votes` 5, `days` 30, `share` 0.8 |
| R7 | `login_churn` | run-level: at least N distinct accounts signed up or logged in from one device inside the window **and** the member has an active vote in it (a household that never votes is not touched); at most 2,000 device groups per run, a member in two groups keeps the larger | 20 | `login_churn.min_accounts` 4, `group_window_hours` 24 |
| R8 | `coordinated_votes` | run-level, last: among members who already carry another open signal (including this run's), the **connected component** of the pair graph (pairs sharing at least `min_products` votes on distinct products in the last 30 days with overlap shared / the smaller vote set >= `overlap`) having at least `min_accounts` members; every member gets a signal. The candidate list is capped at **500, members touched by this run first**, then the strongest and newest signals; the pair self-join runs under a 10 s statement timeout. **Popularity cap:** a product voted by more than `max_popularity_pct` percent of the candidate set is ignored, so piling onto the day's biggest launch links nobody (100 switches it off) | 35 | `coordinated.min_accounts` 3, `min_products` 4, `overlap` 0.8, `max_popularity_pct` 40 |

A weight of 0 switches its rule off (R3a has no weight setting: raise `anticheat.burst.min_votes` to mute it; R3b is switched off by `anticheat.weight.vote_burst = 0`). Votes of deleted members never count in any rule.

**Settings** (`PATCH /admin/settings/{key}`, admin only; 32 `anticheat.*` keys). Integers: `anticheat.flag_risk_score` 70 (1 to 100); weights `anticheat.weight.shared_device` 40, `ip_cluster` 15, `vote_burst` 25, `fresh_account` 15, `disposable_email` 25, `single_maker_voter` 20, `login_churn` 20, `coordinated_votes` 35 (each 0 to 100); `anticheat.group_window_hours` 24 (1 to 48); `anticheat.shared_device.min_accounts` 3 (2 to 20); `anticheat.ip_cluster.min_accounts` 5 (2 to 50); `anticheat.ip_cluster.established_days` 30 (1 to 365); `anticheat.burst.window_minutes` 10 (1 to 60); `anticheat.burst.min_votes` 30 (5 to 1000); `anticheat.user_burst.votes` 8 (3 to 50); `anticheat.user_burst.minutes` 5 (1 to 60); `anticheat.fresh_account.minutes` 30 (1 to 1440); `anticheat.single_maker.min_votes` 5 (3 to 50); `anticheat.single_maker.days` 30 (7 to 90); `anticheat.login_churn.min_accounts` 4 (2 to 50); `anticheat.coordinated.min_accounts` 3 (2 to 20); `anticheat.coordinated.min_products` 4 (2 to 50); `anticheat.decay_days` 14 (1 to 90); `anticheat.coordinated.max_popularity_pct` 40 (5 to 100). Decimal strings (plain decimals as for the ranking settings): `anticheat.ip_cluster.discount` `"0.5"` (0 to 1), `anticheat.burst.ratio` `"4"` (1 to 20), `anticheat.single_maker.share` `"0.8"` and `anticheat.coordinated.overlap` `"0.8"` (0.5 to 1), `anticheat.product_watch_ratio` `"0.9"` and `anticheat.product_alert_ratio` `"0.7"` (0.1 to 1; the watch ratio must stay above the alert ratio: raise the watch ratio first when moving both up). `anticheat.disposable_domains` is a comma separated list of lower-case domains (20 well-known services by default, at most 300 entries and 4,000 characters; a write must carry at least one entry, so switch R5 off with its weight). Every integer, decimal and list is re-checked at read time and falls back to its default (with a warning naming the key) when a stored value is out of range. The two retention windows are in the retention table (`retention.risk_signals_days` 365, `retention.risk_signals_reviewed_days` 90).

### Scoring and flagging

- **Score** (`users.risk_score`, 0 to 100, recomputed every run for the members a run touched and everyone with a score above 0, so decay lowers scores without a separate job): per signal a **dismissed** signal counts 0, a **confirmed** one its full weight for ever, an **open** one its weight faded linearly to 0 over `anticheat.decay_days` (14); then per **kind only the strongest** contribution counts, the kinds are **summed** and the sum is **capped at 100**. All integers, one rounding per signal.
- **Flagging only.** A member whose score reaches `anticheat.flag_risk_score` (70) gets their active votes on **yesterday's and today's launch days** (UTC; older days are frozen history) marked `flagged`; when the score drops below the threshold the votes are un-flagged. **A flagged vote still counts everywhere** (`votes_count`, hourly buckets, the hot score, rankings treat `counted` and `flagged` alike): flagging changes one status column and nothing else (no counter, bucket, event, broadcast or audit row; no product or user row is locked). There is no automatic void and no automatic suspension. A deleted member's votes are never flagged (the deletion job voids them); a suspended member's are scored and flagged like anyone else's. Latency is up to five minutes. The flag pass runs in a single statement per chunk (rows picked `FOR NO KEY UPDATE SKIP LOCKED` in a `MATERIALIZED` CTE, the update conditional on the expected status) and a chunk that fails ends that direction for the run.
- **Product integrity** (staff read, computed on read from the vote rows): `counted / (counted + flagged)` and a band: `clean` at or above `anticheat.product_watch_ratio` (0.9), `watch` at or above `anticheat.product_alert_ratio` (0.7), else `alert`.
- **Locks.** `SignalWriter` writes each chunk in its own transaction with `lock_timeout` 5 s: it first locks the referenced **products** `FOR KEY SHARE` in id order, then the referenced **users** the same way, and only then inserts (an insert takes those key shares in row order, which would otherwise form a cycle with `FreezeDay` and `DeleteAccount`). Scoring locks user rows `FOR NO KEY UPDATE SKIP LOCKED`.

### Staff API (all under `/api/v1`)

Roles are judged first: a non-staff caller gets 403 for existing and unknown ids alike, then lookups (404), then validation (422). **Reads: reviewers and admins.**

| Method and path | Who | Answer |
|---|---|---|
| `GET /staff/risk-signals?status=open\|reviewed\|all&kind=&user=&product=&min_weight=&from=&to=&per_page=&cursor=` | reviewer, admin | the signal list (`{id, kind, status, weight, detected_at, reviewed_at, reviewer, review_note, outcome, member, product, observed, threshold}`); `open` (default) is worst first (`weight, detected_at, id`), `reviewed` is `reviewed_at desc`, `all` is `detected_at desc`; keyset |
| `GET /staff/risk-signals/{signal}` | reviewer, admin | one signal with its full evidence (ids and counts) |
| `GET /staff/risk/members?min_score=&suspended=true\|false&per_page=&cursor=` | reviewer, admin | members by risk, worst first (`min_score` defaults to the flag score): `{id, handle, name, risk_score, suspended, account_age_days, open_signals, flagged_votes}`; no e-mail |
| `GET /staff/risk/members/{member}` | reviewer, admin | one member's risk summary: profile basics, `breakdown` per kind, vote counts, sign-ins, linked accounts, the signals; never an e-mail or a hash |
| `GET /staff/products/{product}/vote-integrity` | reviewer, admin | counted/flagged/voided/removed/active counts, `integrity_ratio`, `band`, `open_signals`, the last 24 hourly buckets (with a `burst` marker), and the top device/IP groups of at least two accounts as 8-hex **fingerprints** |
| `GET /staff/products/{product}/votes?status=&user=&per_page=&cursor=` | reviewer, admin | the product's votes, `voted_at desc, id desc`: voter, status, `toggle_count`, `device_fp`, `ip_fp` |
| `POST /staff/risk-signals/{signal}/review` | reviewer, admin | body `{"outcome": "confirmed"\|"dismissed", "note"?}` (note up to 255); `{"data": signal, "meta": {"changed"}}` |
| `POST /staff/votes/{vote}/void` | **admin only** | body `{"reason"}` (5 to 255); `{"data": {"voided": bool}}` |
| `POST /staff/risk/members/{member}/void-votes` | **admin only** | body `{"reason", "product_id"?}`; `{"data": {voided, skipped_frozen, failed, has_more}}` |
| `POST /staff/risk/members/{member}/suspend` | **admin only** | body `{"reason"` (5 to 500)`, "void_votes"?` (a JSON boolean, default true)`}`; the member's risk summary plus `"meta": {suspended_now, signals_confirmed, void_votes: queued\|dispatch_failed\|not_requested}` |

- **Review.** Confirming or dismissing recomputes the subject's score in the same transaction (signal row, then the subject's user row `FOR NO KEY UPDATE`, then the audit row). Re-reviewing with the other outcome overwrites reviewer, time, outcome and note (audited with before and after); the same outcome again changes nothing and writes no second audit row. Flags follow on the next run. **`403 own_signal`**: nobody reviews a signal about their own account or about their own product. **`403 admin_only_signal`**: a reviewer cannot review a signal about a reviewer or an admin. The actor's role is read from the database first.
- **Void a vote.** Uses `VoidVote` (counter, hourly bucket, `vote.voided` audit row, `VoteVoided` event) after a plain role read. A vote on an already frozen day is **`409 day_frozen`** (frozen days are final); an unknown id is 404; voiding twice answers `voided: false`.
- **Void a member's votes.** At most **100 votes per call**, only on launch days that are not frozen (today or later), oldest product first, each vote its own transaction; one failing vote never stops the others. `skipped_frozen` counts the member's active votes on frozen days (informational, never processed) plus any day that froze during the call; `failed` counts votes that threw (reported). A summary audit row `member.votes_voided` is written when anything was voided or failed. **Stop rule for callers:** loop until `has_more` is false **or `voided` is 0** (100 or more permanently failing votes keep `has_more` true while a call voids nothing). A deleted member's votes can still be voided.
- **Suspend for abuse.** One implementation of suspension (`SuspendUser`: admin only, `403 cannot_suspend` for oneself or any admin, idempotent), then the member's open signals are confirmed (note `suspended`, one `risk_signal.reviewed` audit row each, score recomputed), then, unless `void_votes` is false, `VoidMemberVotes` (reason `suspended`) is queued after both commits (it needs other makers' product rows, which must never be taken while a user or signal row is held). Three separate commits, so a failure is repaired by **calling the endpoint again** on the (now suspended) member: it repeats the confirmation and the dispatch. `void_votes: dispatch_failed` in the response means the queue push failed and nothing is queued. A suspended member's votes keep counting unless staff asked for the void.
- **Error codes:** `day_frozen` (409), `own_signal` (403), `admin_only_signal` (403), `cannot_suspend` (403), plus the account guards (`try_again` is `DeleteAccount`'s and none of these routes returns it). **Limiter:** the four writes use `admin-write` (30/min and 200/hour per admin); the reads ride the global `api` limiter. **Audit actions:** `risk_signal.reviewed`, `vote.voided`, `member.votes_voided`, `user.suspended` (the audit meta holds ids, enum values and short staff-written reasons only).
- **Privacy.** Staff never see a raw hash: a vote carries `device_fp` and `ip_fp`, the first 8 hex characters of the hash, computed in SQL, which are `null` once `PurgeVoteHashes` has emptied the vote. Group lists show fingerprints only; evidence holds ids and counts. Reviewers see fingerprints too (a reviewers-see-no-fingerprints variant is an open item).

### The job rename and the sweep

The vote clean-up job is now `App\Jobs\VoidMemberVotes` with a `reason` (`account_deleted`, the default, or `suspended`). The guard follows the reason (`account_deleted` needs `users.deleted_at`, `suspended` needs `users.suspended_at`; a member restored before the job runs is skipped) and the unique id is `reason:userId`. `App\Jobs\VoidDeletedMemberVotes` is a **deprecated empty subclass** kept so payloads queued before the rename still unserialize (`reason` is a plain property with a default for exactly that reason; do not make it readonly or promoted). To retire it, wait until both of these return 0, then delete the class: `SELECT count(*) FROM jobs WHERE payload LIKE '%VoidDeletedMemberVotes%'` and the same on `failed_jobs`. `VoidMemberVotes::dispatchSafely()` never throws and, on a failed push, **releases the unique lock** (it is taken before the push and would otherwise make every repair dispatch a silent no-op for an hour); `DeleteAccount`, the sweep and the suspend endpoint all use it. `launchpad:sweep-deleted-member-votes` keeps its name, class and schedule and stays **deleted-only**: sweeping suspended members would void every suspended member's votes, which community decided to leave counting. Production needs an async queue and a running worker for it.

### Open items

Un-voiding and appeals; an automatic hold; a queue-size alert; a durable marker so the sweep can cover suspended members who were suspended with `void_votes`; retiring `VoidDeletedMemberVotes`; IPv6 /64 hashing for the group rules and campus allow-lists; excluding staff from R1/R2 groups; a `(user_id, voted_at)` index on `votes` if R3b/R6 prove slow; fingerprints hidden from reviewers.

## Reads added for the web app (web phase 5)

These endpoints and field changes exist because the React app needs them. All are under `/api/v1`.

### Admin member directory: `GET /admin/users`

Admins only (a reviewer or member gets 403 before anything is validated). Ordered `created_at desc, id desc` (served by `users_created_at_id_index`), keyset cursor, reads the query string only.

| Param | Rule |
|---|---|
| `q` | optional. A leading `@` (as in a handle) is stripped first, **then** the length rules apply: 2 to 100 characters, no control characters (a lone `@` is therefore null and fails, not "empty"). Matches the **start of the handle**, the **start of the name** or the **start of any word of the name**, case-insensitively, with `%`, `_` and `\` escaped and the pattern bound. **The e-mail is never searched.** |
| `role` | `member`, `reviewer` or `admin` |
| `status` | `active` (not suspended), `suspended`, or `deleted`. **Deleted accounts are hidden by default**; `status=deleted` lists only them |
| `per_page` | 1 to 50, default 20 |
| `cursor` | optional; shape-checked; empty is page one |

A row is the admin user card plus account state: `{id, handle, name, email, role, headline, avatar_url, ..., is_suspended, suspended_at, suspended_reason, deleted_at, email_verified_at, risk_score}`. **The e-mail and `risk_score` are visible to admins only** (this is why the list is admin only); never a hash, token or avatar path.

### Staff product list: `GET /staff/products`

Reviewers and admins (role gate first). Every product that has left the draft stage, latest change first (`updated_at desc, id desc`, `products_staff_list_index`, or `products_staff_status_index` with a status filter); soft-deleted rows are excluded.

| Param | Rule |
|---|---|
| `status` | any product status **except `draft`**: `draft` is 422 (drafts are the founder's private work and never listed) |
| `q` | optional, 2 to 100 characters, no control characters. Matches **inside the name** (case-insensitive) or at the **start of the slug**; wildcards escaped, pattern bound |
| `per_page` | 1 to 50, default 20 |
| `cursor` | optional, shape `updated_at, id` |

A row: `{id, slug, name, tagline, logo_url, status, launch_date, votes_count, comments_count, submitted_at, launched_at, taken_down_at, takedown_reason, maker: {id, handle, name}, topics, created_at, updated_at}`; no description, links or screenshots (the detail read has them) and no e-mail.

### Public config: `GET /config`

Public, the same for everyone: `{"data": {"max_topics", "max_screenshots", "max_launches_per_day"}}` (the three product limits the forms show; nothing else may be added because every key is world readable). The answer is `Cache-Control: private, max-age=60` with `Vary: Origin`. **It is `private`, not `public`, on purpose:** every API response may carry a `Set-Cookie: lp_did` device cookie (and a stateful origin a session cookie), so a shared cache must never store one; the browser may still reuse it for a minute.

### Changed reads

- **`GET /users/{handle}/products`** now returns cards with `votes_count`, `comments_count` and, for a known viewer (token or SPA session; a suspended or deleted caller is a guest), `has_voted` (one query for the page). It uses the shared optional-viewer headers: `Vary: Authorization, Cookie` always, `no-store, private` for a known viewer.
- **`GET /products/{slug}`** gains `rank` and `rank_date` (both `null` when the product has no standing): the **live position on today's whole list** (not capped at 100) for a product launched today, the **frozen rank** (`daily_rankings`) of its launch day for a past day, and `null` for a past day that is not frozen yet or a future launch. The embedded `maker` card gains `followers_count` and, for a known viewer, `is_following`.
- **`GET /users/{handle}`** gains `products_count` (the member's publicly listed products: visible, launched, the same filter as the products list). Maker cards on other reads do not carry it.
- **`GET /me/products`** now eager-loads `topics` and `screenshots`, adds `votes_count` and `comments_count` for **live and taken-down** products (omitted in other statuses), and, for `changes_requested` and `rejected` products, `latest_review: {decision, note, decided_at}` (the latest review whose decision matches the status, `null` if none). The query count is constant in the page size.
- **`PUT /products/{id}/launch`** refusals stay `422 {message, errors: {launch_date: [...]}}` and now also carry a stable top-level `code`: `launch_date_invalid` (not a real `YYYY-MM-DD` date), `launch_date_today` (today is too late), `launch_date_out_of_window` (beyond `launch.max_days_ahead` or in the past), `launch_cap_reached` (the per-day cap `launch.max_per_maker_per_day`). The web app switches on `code`; older clients keep reading `errors.launch_date`.
- **CORS** now exposes `Content-Disposition` (besides `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`), so the web app can read the export download's filename.

### Known limits

- The `LIKE` searches of the member directory and the staff product list (`lower(name) like ...`, `slug like ...`) have **no trigram index**; the status/order is index-served but the text filter scans the matching rows. Fine up to roughly 100,000 rows; add `pg_trgm` indexes after that (the product search uses `name_search`, not these lists).
- The migration `2026_10_22_000003` creates `users_created_at_id_index`, `products_staff_list_index` and `products_staff_status_index` with plain `CREATE INDEX IF NOT EXISTS`, which blocks writes to `users` and `products` while it builds. On a big table run it in a maintenance window, or create the three indexes by hand first with `CREATE INDEX CONCURRENTLY` under the same names (the migration then skips them).
- The rank on the product read adds a day-wide scan (`TodayRanking::positionOf`) to every read of a product launched today, including unauthenticated reads. Cache it if that page gets hot.

## Security notes and accepted trade-offs

- **Login lockout by email:** the 20/hour per-email bucket is global, so anyone can block password sign-in for an account for up to an hour with 20 bad attempts. It counts `auth/login` and `auth/token` together, and invalid (422) attempts too, because throttling runs before validation. Accepted.
- **Login per-IP cap:** 30 attempts/min per IP across all emails limits credential stuffing, but it is shared by everyone behind one IP (office or carrier NAT): a busy NAT can briefly block sign-in for its neighbours. 30 is high enough for normal use. Raise it only if monitoring shows real users hitting it, and keep `TRUSTED_PROXIES` correct or all users share one IP.
- **Bearer-token `api` buckets:** each token has its own 60/min bucket, so one account with many tokens gets more than 60/min in total; the 300/min per-IP backstop bounds that and token rotation.
- **Registration enumeration:** a duplicate email returns a plain 422 and is faster than a successful sign-up, so registered emails can be enumerated. Accepted (the spec asks for plain duplicate-email errors). Forgot-password, login and resend are equalised.
- **Recent-login window:** `recent.login` (10 minutes) is per account (`users.last_login_at`), not per session or token. For a Google-only account any session passes inside the window. A missing or old `last_login_at` fails closed.
- **Audit meta holds no personal data:** `audit_logs.meta` is returned as stored by the admin API; put only ids, enum values and short reasons in it, never emails, names or tokens.
- **Device id is a weak signal:** it is client-controlled (header or cookie). Always combine it with `ip_hash`, account age and rate limits.
- **Audit-log cursor** is validated by shape only (keys, date, ULID, bool); a tampered cursor is a 422, but a well-formed forged one just pages from that point.
- **Sign-out everywhere** also rotates `remember_token`. Change-password keeps the current session or token and signs out remember-me cookies elsewhere.
- **Responses** carry `nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`; auth, `me`, `staff`, admin and broadcasting responses are `no-store`, and so is every request under `email/*` and every non-GET/HEAD request under `products`, `comments`, `users` and `topics` (public product reads stay cacheable by headers, but they currently send Laravel's default `no-cache, private` plus the `lp_did` cookie, so they are not CDN-ready). Add any new authenticated prefix to `SecurityHeaders`.
- **Device names** are user text; render them as plain text if a devices list is ever built.

## Ideas for a later hardening pass

- **Handle-change cooldown:** an old handle is free the moment its owner changes it, so someone else can take it and impersonate. Consider a cooldown or reserving changed-away handles.
- **Display-name confusables:** `CleanText` strips invisible and bidi characters but does no NFKC or mixed-script detection. Consider `Spoofchecker` (ext-intl) for names and handles.
