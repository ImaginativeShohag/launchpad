# Repository Guidelines

## Coding Rules

- At the end of every task, or after making an error, update `.agents/lessons/_learned.md` following the curation policy in its header: add only durable, reusable gotchas (not feature-specific narration), and promote standing conventions to this file.

## API conventions (`api/`, Laravel 13 + PostgreSQL)

- Run PHP, Artisan, Pest, Pint and Larastan inside the Sail container (`docker compose exec -T laravel.test …` from `api/`), never on the host.
- Controllers stay thin: Form Request validates, one Action in `app/Actions` does the work, an API Resource shapes the response. Business logic never lives in models or controllers.
- Never `(string)`-cast raw request input, never decide anything by parsing an exception message, and never call `Hash::check` directly (use `PasswordCheck`).
- Swallowed DB errors and request-path audit writes run in a savepoint (`AuthLog::tryRecord` for auth events).
- Every endpoint test file includes array/object/null payload cases that expect 422, never 500. Tests use Pest on PostgreSQL, with `// Given`, `// When`, `// Then` comments.
- Account-existence must not be observable: same response and same cost for every account state (dummy hash or queued job); production needs an async queue and a running worker.
- Product state changes go through one Action each, which re-reads the row under `lockForUpdate()` and re-checks owner and status there. Lock order everywhere: the member's product rows (id order), the active-admin set (id order), then a user row. Events fire `ShouldDispatchAfterCommit`, files are deleted only after the commit, a hidden or foreign product is the same 404 as a missing one.
- Every new authenticated route prefix goes into `SecurityHeaders` (`no-store`), every write route carries a named limiter below the group's 60/min, and `ProductRoutesTest` pins both.
- Chunked jobs and purges that pick rows with `LIMIT` + `FOR UPDATE SKIP LOCKED` do it in a `WITH picked AS MATERIALIZED (...)` CTE and join it (a plain `IN (subselect LIMIT n)` can be re-run by the planner and touch more rows).
- Event-driven mail goes through `RecordNotification` (bell row + outbox row in one transaction); never send it directly. Only the three auth mails keep their own queued path.
- A `ShouldBeUnique` job is dispatched through a helper that releases the unique lock when the push fails (`VoidMemberVotes::dispatchSafely`); a bare `dispatch()` in a try/catch leaves the lock for the whole `$uniqueFor`.
- Every new public or staff route updates the pins in the same change: the route audit, the named limiter (below 60/min) and its numbers, `SecurityHeaders` for a new authenticated prefix, and the exact schedule total when a command is added.
- Settings are read only through the typed `Settings` helpers (`integer`, `days`, `decimal`, `timeOfDay`, `domains`), which re-check type and range on every read and fall back to the default with a logged warning; a setting is added in the phase that enforces it.
- Parallel agents in one tree use plain edits only: never `git stash`, `git mv` or `git restore --staged`; the controller does all git and never runs two test suites at once.
- API docs come from Scramble (`config/scramble.php`) at `/docs/api` and `/docs/api.json`, served in the `local` environment only (no `viewApiDocs` gate is defined, so everything else is 403); share the spec elsewhere with `php artisan scramble:export`. The docs routes carry `throttle:api-docs` (30/min) and `no-store`, pinned in `ApiDocsTest`.
- Resuming mid-project: read `docs/superpowers/HANDOFF.md` first (current state, repo map, how to run, schedule, production checklist, open decisions), then the spec and plan of the area you touch (listed in `PROGRESS.md`).
