# Launchpad

This is a project for learning.

A site where founders launch a product on a chosen day and members vote on it. The best products of the day sit at the top of the front page, ranked by how fast votes arrive, not just by the total. The brief is in [Client requirement.md](Client%20requirement.md).

It is two apps in one repository:

| Folder | What it is |
|---|---|
| [`api/`](api) | Laravel 13 JSON API (`/api/v1`) on PostgreSQL, with Redis, Sanctum, Reverb (websockets) and Pest tests. Also serves the future phone app. |
| [`web/`](web) | React 19 + TypeScript + Vite + Tailwind web app that runs on the API (cookie session, live updates through Reverb). |

## What it does

- Accounts: email and password or Google, email confirmation, public profiles.
- Products: draft, review by staff (approve, reject, request changes), a chosen launch day that publishes by itself, limited edits after launch.
- Front page: live ranking by vote velocity, frozen daily pages, top of week, month and all time per topic.
- Voting, comments and replies, reports, follows and a feed, with live vote counts.
- Notifications: a live bell and email (with per-type opt-out and a safe outbox if the provider is down).
- Search by name and description, with topic filters.
- Founder dashboard: views, votes over the day, referrers, rank history, and a downloadable export prepared in the background.
- Staff tools: review queue, reports, takedowns, member suspension, anti-cheat risk views and a full audit log.

## Run it locally

You need Docker and Node. Full instructions are in [api/README.md](api/README.md) and [web/README.md](web/README.md); the short version:

```bash
# API (from api/)
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan queue:work        # keep running: mail, notifications, exports
./vendor/bin/sail artisan schedule:work     # keep running: launches, rankings, outbox
```

```bash
# Web (from web/)
cp .env.example .env.local
npm install
npm run dev                                  # http://localhost:5173
```

Use `localhost`, never `127.0.0.1`: the session cookie and CORS origin must match. Run all PHP inside the Sail container.

## Tests

```bash
cd api && docker compose exec -T laravel.test php artisan test    # about 6,139 tests
cd web && npm test                                                # 587 tests
```

## Going live

Everything you have to configure yourself (domains, env vars, database, Redis, worker and scheduler, Reverb, mail, storage, Google, monitoring) is in the **[setup checklist](docs/SETUP-CHECKLIST.md)**.

## Where to read next

| Document | Purpose |
|---|---|
| [PROGRESS.md](PROGRESS.md) | Project tracker: sub-projects, commits, specs and plans |
| [api/README.md](api/README.md) | API reference: endpoints, settings, operations |
| [web/README.md](web/README.md) | Web app architecture |
| [docs/database/](docs/database) | Schema (`launchpad.dbml`, with an HTML viewer) and the numbered decision log (`DECISIONS.md`) |
| [.agents/lessons/_learned.md](.agents/lessons/_learned.md) | Engineering rules learned while building |
| [AGENTS.md](AGENTS.md) | Standing conventions for anyone (or any agent) changing the code |

Design specs, build plans and the handoff in `docs/superpowers/` are local, ignored notes and are not included in clones.

## Status

Feature complete: all nine sub-projects are built and committed. What is left is setup (see the checklist) and optional follow-ups listed in the handoff.
