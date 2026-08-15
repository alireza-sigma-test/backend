# Proposal Review — API

Laravel 13 API for a talk-proposal review app: speakers submit proposals, reviewers
rate and comment, administrators decide status.

Frontend: <https://github.com/alireza-sigma-test/frontend> — **start this API first.**

## Run it

```bash
git clone https://github.com/alireza-sigma-test/backend.git && cd backend
make up
```

That builds the images, boots nginx / php-fpm 8.4 / MySQL 8.4 / Redis / phpMyAdmin /
Mailpit, generates the app key, migrates, and seeds.

| Service | URL |
|---|---|
| API | <http://localhost:8000> |
| phpMyAdmin | <http://localhost:8081> (`proposal` / `secret`) |
| Mailpit | <http://localhost:8025> |

| Command | What it does |
|---|---|
| `make up` | Build, start, migrate and seed |
| `make fresh` | Drop, re-migrate and re-seed |
| `make test` | Run the Pest suite |
| `make down` | Stop the stack |
| `make shell` | Shell into the PHP container |

The seeder is idempotent by design, so running `make up` again against an existing
volume is a no-op — it will not reset demo data. **Use `make fresh` to get back to a
clean seeded state.**

## Configuration

`MEDIA_DISK` must stay `local`. Setting it to `public` silently makes every uploaded
PDF world-readable, with no signed URL and no access check — attachments are meant to
be served only through the temporary, signed URL the API returns.

### Production

`.env.example` ships `APP_ENV=local` and `APP_DEBUG=true`, which is right for the
Docker stack this README asks you to run, not for a public deployment. Before
deploying, set:

```
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` renders unhandled exceptions as a full Whoops page — stack trace,
source snippets, request payload, and every `.env` value the config layer has
resolved, including `DB_PASSWORD`. That is not hypothetical here: the `description`
field's `max:20000` rule (`docs/API.md`) exists precisely because an over-long value
once reached an unhandled path and the debug page echoed back the SQL, the DB host,
and the database name. `APP_ENV=production` additionally disables a handful of
local-only conveniences (route/config caching behaves differently, and Scramble's own
default `viewApiDocs` gate — see below — would otherwise assume `local`).

## Seeded accounts

All passwords are `password`.

| Email | Role |
|---|---|
| `dana@example.com` | speaker |
| `maya@example.com` | reviewer |
| `alex@example.com` | admin |

Plus Ilya Petrov and Nia Okafor (speakers), Jonas Adeyemi, Sofia Lindqvist and
Theo Nakamura (reviewers) — 6 proposals across all three statuses.

## Architecture

`Form Request` → `readonly DTO` → `Action` (owns the transaction) → `API Resource`.
Controllers stay under 20 lines.

- **Actions** — one use case, one caller, owns the DB transaction.
- **Services** — stateless collaborators reused across actions (`TagSynchronizer`,
  `AttachmentStore`).
- **Repository** — read surface for `Proposal` only, the one model with non-trivial
  queries. Filters compose through `Illuminate\Pipeline`. Writes use Eloquent directly
  inside Actions, which keeps the interface honest rather than leaking a `save()`.
- **Policies are the single source of per-record authorization** — `view`, `update`,
  `review`, `changeStatus`, etc. List scoping is a separate concern and lives in
  `EloquentProposalRepository::scope()` instead: `ProposalPolicy::viewAny()` returns
  `true` for every role deliberately, and the speaker-vs-everyone-else narrowing
  happens in the repository's query, not the policy. The `can{}` object on every
  proposal is generated from the per-record policies so the client never infers
  permission from a role string — but it is for rendering only; every mutating route
  still calls `Gate::authorize`.

Pest arch tests (`tests/Arch/LayeringTest.php`) enforce six rules: enums are
string-backed, no debug statements (`dd`/`dump`/`ray`/`var_dump`) ship, Actions are
`final` and never touch `Illuminate\Http\Request`, DTOs are `readonly` and never hold
models, controllers never run a query directly (`DB` facade / `Eloquent\Builder`), and
Services/Repositories are `final`. None of them assert the under-20-line guideline,
`Proposal` being the only model with a repository, Form Request/Resource discipline, or
policies as the per-record authorization site — those four are followed by convention
and reviewed by hand, not enforced by a test.

## Notable decisions

- **Bearer tokens, not cookie sessions.** [`docs/API.md`](docs/API.md) specifies Sanctum
  bearer tokens. Laravel's own guidance prefers stateful cookies for first-party SPAs;
  the contract wins, and the SPA-mode frontend means no server-to-server hop needs them.
- **No `role` column.** Roles come from `spatie/laravel-permission`; `UserResource`
  derives `role` from the pivot so the API shape is unchanged.
- **`average_rating` is never denormalised** — `withAvg` on the query, so no
  stale-aggregate bug class exists.
- **Reviewer attribution is never disclosed to speakers.** On their own proposal a speaker
  sees each review's comment and date, never the score or its author — enforced server-side
  in the resource, covered by a test. Aggregates (`average_rating`, `reviews_count`) are
  visible to them, as [`docs/API.md`](docs/API.md) specifies; but because reviews arrive
  one at a time, per-review scores are recoverable by differencing successive averages
  (verified live: `[4, 5, 3, 1]` recovered exactly from observed averages
  `4 → 4.5 → 4 → 3.3`), so the guarantee is reviewer anonymity, not score secrecy.
- **Anyone can register as an administrator.** This is a deliberate demo affordance so
  a reviewer can reach every role without seeded credentials. In production, admin
  creation would be by invitation or console command only.

## Tests

`make test` — Pest, `describe`/`it` with Given-When-Then bodies, run against real
MySQL so collation-dependent `LIKE` searches behave exactly as in production. Coverage is deliberately
curated rather than exhaustive: every policy denial has a test.

Tests are backend-only by deliberate scope; the frontend ships without a test suite.

## API contract

[`docs/API.md`](docs/API.md) is the contract this implementation follows — resource shapes,
every endpoint, validation rules and status codes. Endpoints marked there but absent here are
listed under *Not built yet*.

This tier added seven endpoints — edit/delete for proposals and reviews, attachment
removal, and the two admin-only read endpoints:

| Endpoint | Role | Status |
|---|---|---|
| `PATCH /api/proposals/{id}` | owning speaker, only while `pending` | `200` |
| `DELETE /api/proposals/{id}` | owning speaker while `pending`, or `admin` | `204` |
| `DELETE /api/proposals/{id}/attachment` | owning speaker while `pending`, or `admin` | `204` |
| `PATCH /api/reviews/{id}` | review author | `200` |
| `DELETE /api/reviews/{id}` | review author | `204` |
| `GET /api/proposals/{id}/history` | `admin` | `200` |
| `GET /api/stats` | `admin` | `200` |

Every one of the six proposal-scoped routes above (all but `/stats`) returns an
identical `404` body for a real id the caller can't see and a fake id, closing the
enumeration oracle described in `tests/Feature/Security/NotFoundEnumerationTest.php`.
`PATCH`/`DELETE /api/reviews/{id}` are deliberately not in that guard: a review id is
only reachable through a proposal the caller can already see (there is no separate
review index endpoint), and any reviewer or admin can already read every review on a
proposal via `GET /api/proposals/{id}` — so there is nothing to enumerate. Those two
routes deny with a plain policy `403` instead.

## Generated API docs

`/docs/api` (human-readable, Stoplight Elements) and `/docs/api.json` (the raw OpenAPI
document) are generated by `dedoc/scramble` from the Form Request rule arrays and API
Resource/DTO shapes already in the codebase — nothing here is hand-written, so the
document can't drift from the validation it describes the way a hand-maintained spec
can.

Two things worth knowing before you rely on it:

- **It's deliberately public in every environment, not just `local`.** Scramble ships a
  gate that restricts `/docs/api` to `APP_ENV=local` by default;
  `AppServiceProvider::boot()` overrides it with
  `Gate::define('viewApiDocs', fn (?User $user) => true)` so a reviewer running this
  in Docker (`APP_ENV=local` per `.env.example`) — or anyone else — can read it without
  a seeded login. This changes nothing about authorization: every documented route
  still enforces Sanctum and its policy; the document is a map, not a key. It does list
  the two admin-only routes above (`/api/stats`, `/api/proposals/{id}/history`) by
  path and method, same as any API map would. To restore the `local`-only default,
  delete that one `Gate::define` line.
- **Comments next to validation rules are lifted into the published document.**
  Scramble reads the PHPDoc/inline comments adjacent to a Form Request's rules and
  publishes them as the field's description — e.g. the note above
  `StoreProposalRequest`'s `attachment` rule about a renamed `payload.exe` surviving
  the extension check but failing the sniffed-MIME one is live, verbatim, in
  `/docs/api.json` today. Nothing currently published discloses a weakness, so none of
  it has been redacted — but the next comment written next to a rule in a Form Request
  is public API documentation the moment it ships, not an internal note.
- **`/docs/api` needs outbound internet; `/docs/api.json` does not.** The HTML page
  loads Stoplight Elements from `unpkg.com`. Behind a firewall or offline, that page is
  blank — fetch `/docs/api.json` directly instead; it's static JSON with no CDN
  dependency.

## Not built yet

Deliberately out of scope for this submission, in planned order:

| | |
|---|---|
| Real-time updates over Laravel Reverb | private channels per user and per role; also why `PATCH /api/proposals/{id}/status` writes its audit record but doesn't yet dispatch the broadcast event `docs/API.md` describes |
| AI proposal summarisation | Laravel AI SDK agent, PDF as native document input |
| Persisted notifications and the activity feed | no schema for either exists yet |

Edit/delete for proposals and reviews, attachment removal, `/stats`, `/history` and
OpenAPI generation — all previously listed here — are built this tier; see
*API contract* and *Generated API docs* above.

The build was tiered so that every stopping point is coherent: migrations are additive per
tier, so there are no unused columns for features that were never built.
