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

## Not built yet

Deliberately out of scope for this submission, in planned order:

| | |
|---|---|
| `PATCH /proposals/{id}`, `DELETE /proposals/{id}`, `DELETE /proposals/{id}/attachment`, `PATCH /reviews/{id}`, `DELETE /reviews/{id}` | edit / delete proposal, remove attachment, edit / delete review — policies, services and tests already in place, routes deferred to the next tier |
| `GET /proposals/{id}/history`, `GET /stats`, `rating_distribution` | endpoints designed, schema already in place |
| Real-time updates over Laravel Reverb | private channels per user and per role |
| AI proposal summarisation | Laravel AI SDK agent, PDF as native document input |
| Persisted notifications and the activity feed | |
| OpenAPI generation via `dedoc/scramble` | |

Because the edit route doesn't exist yet, `can.edit` on a `Proposal` can be `true` with
no route to act on it — this is scope control, not a bug. It's also why
`AttachmentStore::remove()`, `ProposalPolicy::delete()` / `viewHistory()` and the whole
of `ReviewPolicy` have tests but no route calls them: they were built for this tier's
policies and services layer and are ready for the routes above, not dead code the tests
happen to be padding coverage for.

The build was tiered so that every stopping point is coherent: migrations are additive per
tier, so there are no unused columns for features that were never built.
