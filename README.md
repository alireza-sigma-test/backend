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
Controllers stay at 5–15 lines.

- **Actions** — one use case, one caller, owns the DB transaction.
- **Services** — stateless collaborators reused across actions (`TagSynchronizer`,
  `AttachmentStore`).
- **Repository** — read surface for `Proposal` only, the one model with non-trivial
  queries. Filters compose through `Illuminate\Pipeline`. Writes use Eloquent directly
  inside Actions, which keeps the interface honest rather than leaking a `save()`.
- **Policies** are the single source of authorization. The `can{}` object on every
  proposal is generated from them so the client never infers permission from a role
  string — but it is for rendering only; every mutating route still calls
  `Gate::authorize`.

The layering is enforced by Pest arch tests, not just described here — see
`tests/Arch/LayeringTest.php`.

## Notable decisions

- **Bearer tokens, not cookie sessions.** `API.md` specifies Sanctum bearer tokens.
  Laravel's own guidance prefers stateful cookies for first-party SPAs; the contract
  wins, and the SPA-mode frontend means no server-to-server hop needs them.
- **No `role` column.** Roles come from `spatie/laravel-permission`; `UserResource`
  derives `role` from the pivot so the API shape is unchanged.
- **`average_rating` is never denormalised** — `withAvg` on the query, so no
  stale-aggregate bug class exists.
- **Reviewer attribution is never disclosed to speakers.** On their own proposal a speaker
  sees each review's comment and date, never the score or its author — enforced server-side
  in the resource, covered by a test. Aggregates (`average_rating`, `reviews_count`) are
  visible to them, as `API.md` specifies; with one review the average necessarily equals that
  score, so the guarantee is anonymity, not secrecy.
- **Anyone can register as an administrator.** This is a deliberate demo affordance so
  a reviewer can reach every role without seeded credentials. In production, admin
  creation would be by invitation or console command only.

## Tests

`make test` — Pest, `describe`/`it` with Given-When-Then bodies, run against real
MySQL so collation-dependent `LIKE` searches behave exactly as in production. Coverage is deliberately
curated rather than exhaustive: every policy denial has a test.

Tests are backend-only by deliberate scope; the frontend ships without a test suite.

## Not built yet

Real-time updates (Reverb), AI summarisation, persisted notifications, and the
activity feed are designed but not implemented. See
`docs/superpowers/specs/2026-08-14-proposal-review-design.md`.
