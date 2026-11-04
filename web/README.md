# Launchpad web

React 19 + TypeScript + Vite + Tailwind 4 + React Router 7 + TanStack Query 5. It talks to the Laravel API in `../api` with a Sanctum cookie session and, optionally, Laravel Reverb (websockets) through `laravel-echo`. Every screen runs on the API: there is no mock data and no local store. Design and plan: `../docs/superpowers/specs/2026-10-21-web-integration-design.md` and `../docs/superpowers/plans/2026-10-21-web-integration.md`.

Going live? Follow the [setup checklist](../docs/SETUP-CHECKLIST.md).

## Run both

1. API (from `api/`):

       ./vendor/bin/sail up -d
       ./vendor/bin/sail artisan migrate --seed
       ./vendor/bin/sail artisan queue:work        # sends the verification and reset mails and records notifications; required
       ./vendor/bin/sail artisan schedule:work     # scheduled jobs (launch day, reminders, digests)

   `api/.env` needs `WEB_URL=http://localhost:5173`, `SANCTUM_STATEFUL_DOMAINS=localhost:5173` and a blank `SESSION_DOMAIN` (all are the `.env.example` defaults). Mail goes to the log (`MAIL_MAILER=log`, `storage/logs/laravel.log`) or to Mailpit at http://localhost:8025 when configured.

2. Web (from `web/`):

       cp .env.example .env.local
       npm install
       npm run dev            # http://localhost:5173 (the port is fixed: CORS trusts exactly this origin)

Use `http://localhost:5173` and `VITE_API_URL=http://localhost`, never `127.0.0.1`: the session cookie, the CORS origin and the Sanctum stateful list must all name the same host. Production with `app.` and `api.` subdomains needs `SESSION_DOMAIN=.example.com` on the API.

## Environment variables

Read once in `src/config.ts`. Vite inlines them at build time, so they are public: never put a secret here.

| Variable | Default | Meaning |
|---|---|---|
| `VITE_API_URL` | none (required; the app throws at boot without it, except under vitest which uses `http://api.test`) | Origin of the API, no trailing slash and no `/api/v1`. |
| `VITE_REVERB_APP_KEY` | empty | The API's `REVERB_APP_KEY`. Empty turns live updates **off**. |
| `VITE_REVERB_HOST` | `localhost` | Where the browser reaches Reverb (not the in-container host the API publishes to). |
| `VITE_REVERB_PORT` | `8080` | Reverb port. |
| `VITE_REVERB_SCHEME` | `http` | `https` for `wss`. |

## Accounts to try

The seeder creates topics and settings, no users. Sign up in the app, open the verification link from the log or Mailpit, then:

- admin: `./vendor/bin/sail artisan launchpad:make-admin you@example.com` (the member must be confirmed)
- reviewer: an admin sets it with `PATCH /api/v1/admin/users/{user}/role`
- Google sign-in needs `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` in `api/.env`; a failed or cancelled consent returns to `/signin?error=<code>`.

Fonts (Inter, Bricolage Grotesque) are self-hosted via `@fontsource-variable/*`, and `index.html` sets `referrer: same-origin`: the app makes no third-party requests.

## Commands

    npm run dev       # vite dev server on :5173
    npm test          # vitest + jsdom; the API is a route-table fetch mock (tests/helpers/mockApi.ts)
    npm run lint      # oxlint
    npm run build     # tsc -b && vite build (this is the typecheck of src/)

`tsconfig.app.json` covers `src/` only. To typecheck the tests too, run `tsc` with a config that extends it and includes `tests` (no script for it).

## Architecture

    src/
      api/          client, DTOs, mappers, query keys, endpoints/, hooks/
      session/      SessionProvider and useSession
      realtime/     lazy Echo wrapper and the live hooks
      pages/        one folder per area (auth, account, discover, product, founder, staff), lazy-loaded by routes.tsx
      components/   ui/ (design kit), layout/ (shell, bell, guards), product/ (cards, vote, follow)
      lib/          pure helpers (dates, CSV, forms, notification text, rank moves)
      theme/        light/dark
    tests/          helpers/, fixtures/, api/, lib/, pages/, realtime/, session/, smoke.test.tsx

### API client (`src/api/client.ts`)

All HTTP goes through `api.get/post/put/patch/delete/upload/download`; components never call `fetch`. It sends cookies (`credentials: include`), fetches the Sanctum cookie once before the first write and sends it as `X-XSRF-TOKEN`, retries a write once after a 419, sends `X-Device-Id` (a random id kept in `localStorage`, never an identity), and turns every failure into an `ApiError` (`status`, `code`, `message`, 422 `fields`, `retryAfter`). A non-JSON error body is never shown. A 401 (other than from `/me` and login) or a 403 `account_unavailable` ends the local session. No `Authorization` header is ever sent. Uploads that need a progress bar use XHR with the same headers and CSRF handling. Emailed links are forwarded verbatim (`rawQuery`), because their signature is order-sensitive.

### Session (`src/session`)

`SessionProvider` reads `GET /me` (a 401 means guest). `useSession()` gives `status` (`loading | guest | member`), `user`, `isStaff`, `isAdmin`, `isVerified`, `signIn`, `signOut`, `refresh`. Sign-out and a server-side session end call `dropSession`, which cancels in-flight reads and removes every cached query except `me`: nothing private survives. Route guards: `RequireRole` (role and, with `verified`, a confirmed email) and `AuthGate`.

### Query keys and hooks

Query keys come only from `src/api/keys.ts`; hooks (`src/api/hooks`) are the only place that touches the cache. DTO types live in `api/types.ts`, view models in `data/types.ts`, and `api/mappers.ts` converts between them, so pages never see raw API shapes. Lists use `useCursorList` (`api/pagination.ts`): keyset cursors from `meta.next_cursor`, "Load more" with `components/ui/LoadMore`. The query client (`lib/queryClient.ts`) retries a read once and never on a 4xx, never retries a mutation, and re-reads on window focus.

Mutations are optimistic where the user expects instant feedback (vote, follow, comment, email preference, mark read). Each one patches only the fields it owns and, on failure, puts back only those fields, so a live frame or another request that changed something in between is not overwritten.

All server text (product names, comments, bios, notification params) is rendered as React text, never as HTML.

### Realtime (`src/realtime`)

`echo.ts` wraps `laravel-echo` with Reverb. It is loaded lazily (dynamic import) and only when `VITE_REVERB_APP_KEY` is set, so a build or a test without it opens no socket. A channel is joined once however many listeners it has and left with the last one. Private channels authorise through the API client (`POST /broadcasting/auth` with the cookie session and CSRF), not Echo's default request. Event names carry the leading dot (`.vote.count`) because the API names them with `broadcastAs()`.

| Hook (`useLive.ts`) | Channel and event | Effect |
|---|---|---|
| `useProductLive(ids)` | public `product.{id}` `.vote.count` | patches the vote count on every cached copy of the card |
| `useTodayLive()` | public `today` `.rank.updated` | reorders Today, then a throttled verification read; also re-reads after a reconnect and at the UTC day change |
| `useUserChannel(userId)` | private `user.{id}` `.notification.count` | validates the frame, sets the bell's unread count, re-reads the notification lists (throttled to one per 5 s) |

Frames are hints from the server: anything that is not the exact shape is dropped, a count for a product with this browser's own vote in flight is ignored (the response wins), and the cache is re-read after a reconnect, on window focus and every 2 minutes (Today and the bell). The bell re-reads every minute instead while Reverb is configured but its socket is down. The private channel is joined only for a signed-in, verified member and left on sign-out. An unverified member still gets the bell, but it polls (focus and every 2 minutes) instead of listening: a deliberate choice, since the API only authorises the channel for confirmed accounts.

### Notifications

The bell (`components/layout/NotificationBell.tsx`) reads `GET /me/notifications/unread-count` (capped at 100 by the API, shown as `99+`), the popover lists the latest 5 and the page (`/notifications`) lists all with keyset "Load more" and an Unread tab (`unread=1`). Rows are marked read with `POST /me/notifications/read` (`ids` or `all`). The API sends no prose: `lib/notificationText.ts` turns `message_key` + `params` into the title and body (one catalog entry per `NotificationType`, a generic line for an unknown key) and turns `href` into a link only for the three paths the API emits (`/products/{slug}` to `/p/:slug`, `/me/products/{id}` to `/my/products`, `/me/products/{id}/dashboard`); anything else, including a query, a fragment or an absolute address, renders as a row without a link. The list keeps at most 5 pages (100 rows) so a live re-read is bounded, and "Load more" stops there. The bell is a labelled dialog (focus moves in on open and back on close, Esc or tabbing out closes it) with a polite live region for count changes.

### Where each area talks to the API

Auth, account and email preferences (Settings, including the `export_ready` mail), discovery (Today, days, leaderboards, topics, search, the following feed, voting), community (product page, gallery, comments and replies, reports, profiles, follows), the founder area (submit wizard with autosave and uploads, My products, scheduling, post-launch edit, dashboard, zip export), the staff area (review queue and decisions, reports, products, members, risk and vote integrity, admin-only audit log with a client-side CSV export) and notifications.

Details worth knowing:

- The founder dashboard has no timer polling: the API caches each read for about 30 s, so the page uses a 30 s `staleTime`, re-reads when the tab regains focus and has a Refresh button. Only the export poll runs on a timer (at the pace of `Retry-After`) while an export is queued or processing.
- Staff: the Members page suspends through `POST /admin/users/{handle}/suspend` (plain member management). A suspension for vote abuse, with the optional vote clean-up, is `POST /staff/risk/members/{id}/suspend` and lives on the member risk page (`/staff/risk/members/:id`); each member row links there.
- Emailed links (`/verify-email`, `/reset-password`, `/confirm-email-change`, `/cancel-email-change`, `/unsubscribe`) read their query once, forward a signed one verbatim, then remove it from the address bar and set `no-referrer`.
- Nothing private is written to `localStorage` (only the theme, recent searches and the device id). `lib/legacyStorage.ts` removes the old prototype keys once at boot and can be deleted after a few releases.

## Live updates (optional)

Live vote counts, re-ranking and the live bell use Reverb. They are **off** while `VITE_REVERB_APP_KEY` is empty: Today then re-reads every 2 minutes and on focus, and the bell does the same. To turn them on set `VITE_REVERB_APP_KEY` to the API's `REVERB_APP_KEY` (and `VITE_REVERB_HOST`/`PORT`/`SCHEME` if Reverb is not on `localhost:8080`) and run the queue worker and `reverb:start` (the `reverb` Sail service). The API's `REVERB_ALLOWED_ORIGINS` must include `http://localhost:5173`.

## Testing

`npm test` runs vitest 5 on jsdom with Testing Library. The helpers in `tests/helpers`:

- `mockApi.ts`: `mockApi({ 'METHOD /path/:param?query': response | handler })` replaces `fetch`, records calls (`api.calls`, `api.callsTo(key)`), answers the Sanctum cookie route, and **fails the test on any request without a route** (`tests/setup.ts` checks after every test, so a swallowed error cannot hide it). `json`, `apiError`, `validation`, `noContent` build responses; a handler may return a promise to hold a response open.
- `renderApp.tsx`: the real route table and real providers on a memory router under `StrictMode`; `user` is what `GET /me` answers (null is a guest). A signed-in render also answers the header bell's `GET /me/notifications/unread-count` with 0; override it to test the bell.
- `fakeEcho.ts`: `installFakeEcho()` injects a fake Echo through `setEchoFactory`, records who listens where (`count`, `channels`, `left`), and pushes frames (`emit`) and connection changes (`setStatus`). A private channel appears as `private-user.{id}`.
- `fakeXhr.ts` (upload progress), `consoleErrors.ts` (`failOnConsoleError()` fails a file's tests on any `console.error`), `productRoutes.ts` and `staffRoutes.ts` (route tables shared by page tests).
- `tests/fixtures/*`: typed builders that return API-shaped JSON with overrides.
- `tests/smoke.test.tsx` visits every route of `routes.tsx` through `mockApi` (plus the unknown-URL page) under `StrictMode`, and fails on a `console.error`, an unmatched request, or a route in the table that no visited address reached.

## What is cut compared with the prototype

The prototype ran on a local store with fake data. These parts have no API counterpart and are gone or reduced:

- Role switcher, demo reset, simulated live votes, client-side ranking, fake screenshot art and the `mock.ts` / `store.tsx` data.
- Staff "feature a product" and the featured spotlight (the Today hero is rank 1).
- Product views and the vote timeline on public cards (views live only in the founder dashboard); "Why this order?" is a static explanation.
- Topic product counts, the logo emoji and hue picker (logos are uploads, with a generated gradient fallback), review-queue automatic risk flags.
- The audit log is admin-only (reviewers do not see it); its search is the action filter, and the CSV export runs over the loaded pages (capped at 1000 rows).
- Notification titles and bodies are written by the client from `message_key`; there are no "digest" or "follow" notifications in the bell (digests are email only).
