# Launchpad: Project Progress Tracker

A product-launch site (daily launches, voting, ranking) built from `Client requirement.md`: a Laravel 13 JSON API (`api/`) and a React web app (`web/`) that runs on it. The project began as a front-end-only prototype (mock data in `localStorage`); every screen now runs on the API and the prototype's mock store is gone.

**Status: feature complete.** All nine sub-projects are built. For setup and operations, read `README.md`, `api/README.md`, `web/README.md` and `docs/SETUP-CHECKLIST.md`. The optional local handoff is `docs/superpowers/HANDOFF.md`. Last verified: API about 6,139 Pest tests, web 586 vitest tests.

## Stack

- API: Laravel 13, PostgreSQL 18, Redis, Sanctum (cookie session for the web, tokens for mobile), Reverb (websockets), Pest, Larastan level 8. Run everything inside Sail.
- Web: Vite 8, React 19, TypeScript, Tailwind CSS 4, React Router 7, TanStack Query 5, `laravel-echo`, Motion, lucide-react; self-hosted Inter and Bricolage Grotesque; the Ember design (light and dark).

Run: see `api/README.md` and `web/README.md`.

## Sub-projects

Legend: [x] built and committed. Specs and plans in `docs/superpowers/` are local, ignored notes (not included in clones); each spec ends with a "Clarifications made while planning and building" section (the code wins where they differ).

| # | Sub-project | Status | Commits (oldest first) | Spec / plan |
|---|---|---|---|---|
| 1 | Foundation + accounts | [x] | `f5b657f` (requirement, prototype, designs), `76f60c2` to `9f9b122`, `bbeacc7` | `specs/2026-10-15-foundation-accounts-design.md`, `plans/2026-10-15-foundation-accounts.md` |
| 2 | Products + review | [x] | `6fc35a5`, `828758f`, `b890d4b` to `cc1b9d2` (tasks 1 to 14), `7cb95fe`, `3f1c7dd` | `specs/2026-10-18-products-review-design.md`, `plans/2026-10-18-products-review.md` |
| 3 | Voting + ranking + live updates | [x] | `6c50dd9`, `a1367d6`, `930c72f` to `9dc4a56` (tasks 1 to 11), `f160de3`, `403943b` | `specs/2026-10-19-voting-ranking-design.md`, `plans/2026-10-19-voting-ranking.md` |
| 4 | Community (comments, follows, feed, reports, suspension) | [x] | `b2bd2e5` (A), `a37c114` (B), `6eee4d8` (C) | `specs/2026-10-21-community-design.md`, `plans/2026-10-21-community.md` |
| 5 | Notifications + email | [x] | `ad928da` (A), `007114c` (B), `7125e14` (C) | `specs/2026-10-21-notifications-design.md`, `plans/2026-10-21-notifications.md` |
| 6 | Search | [x] | `806a747` (A), `3ccea94` (B) | `specs/2026-10-21-search-design.md`, `plans/2026-10-21-search.md` |
| 7 | Founder dashboard + export | [x] | `7a370b3` (A), `5e4dc90` (B), `675b190` (C) | `specs/2026-10-21-founder-dashboard-design.md`, `plans/2026-10-21-founder-dashboard.md` |
| 8 | Anti-cheat | [x] | `cefa3e0` (A), `2155e25` (B), `cf65902` (C) | `specs/2026-10-21-anti-cheat-design.md`, `plans/2026-10-21-anti-cheat.md` |
| 9 | Web integration | [x] | see the phases below | `specs/2026-10-21-web-integration-design.md`, `plans/2026-10-21-web-integration.md` |

Schema: `docs/database/launchpad.dbml` (viewer `launchpad.html`, regenerated from it) and the numbered decision log `docs/database/DECISIONS.md`.

### Web integration phases

| Phase | Scope | Commit |
|---|---|---|
| 0 | API client dependencies (TanStack Query, Echo, test tooling) | `4d2bb1c` |
| 1 | API client, session, auth and account, self-hosted fonts | `b43d9e8` |
| 2 | Discovery: Today, days, leaderboards, topics, search, the following feed, live ranking | `0020ac1` |
| 3 | Product page, comments, follows, reports, profiles | `c56e688` |
| 4 | Founder area: wizard with uploads, my products, scheduling, dashboard, exports | `a3d43e9` |
| 5 | Backend reads for the staff area (admin member directory, staff product list, public config, richer cards) and their docs | `1e3f58f`, `deef183`, `cbe31a9` |
| 6 | Staff area: reviews, reports, products, members, risk, audit log | `ab4ac96` |
| 7 | Notifications and cleanup: the bell and notification pages, removal of the mock store and the legacy storage keys, final end-to-end pass | [x] committed (dbe83f1) |

## What was cut compared with the prototype

The prototype ran on a local store with fake data (`src/data/mock.ts`, `src/store/store.tsx`). These parts have no API counterpart and are gone or reduced (details: `web/README.md` "What is cut compared with the prototype" and `web integration spec` section 9):

- Role switcher, demo reset, simulated live votes, client-side ranking, fake screenshot art and the mock data and store.
- Staff "feature a product" and the featured spotlight (the Today hero is rank 1).
- Product views and the vote timeline on public cards (views live only in the founder dashboard); "Why this order?" is now a static explanation.
- Topic product counts, the logo emoji and hue picker (logos are uploads with a generated gradient fallback), review-queue automatic risk flags (wait-time escalation stays).
- The audit log is admin-only (reviewers do not see it); its search is the action filter, and the CSV export runs over the loaded pages (capped at 1,000 rows).
- Notification titles and bodies are written by the client from `message_key`; there are no digest or follow entries in the bell (digests are email only).

Kept from the prototype: the design tokens and UI kit, `ProductRow`, `VoteButton`, layout shell, page transitions, the wizard UI, the charts and the route structure.

## Design

**Ember** is the only design (final, 2026-10-08): clean cards, warm orange brand, Inter + Bricolage Grotesque, light and dark.

## Where things live

- Resuming or deploying: `README.md` and `docs/SETUP-CHECKLIST.md`. Optional local handoff: `docs/superpowers/HANDOFF.md`.
- Standing rules: `AGENTS.md` and `.agents/lessons/_learned.md`. API reference and operations: `api/README.md`. Web reference: `web/README.md`.
- Client brief: `Client requirement.md`.
