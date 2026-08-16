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

`GET /api/public-stats` — the signed-out screen's two counters, and the only
route that returns application data without a token — reads this same seed:
`{"proposals_this_year": 6, "reviewers": 4}`. The design mockup's landing page
shows `248` and `31`; a freshly seeded database showing small honest numbers
instead is expected, not a bug — nothing inflates this endpoint's counts to
match a marketing screenshot.

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
- **Deleting a proposal is a soft delete — done to protect other rows, not to
  enable a restore feature.** `reviews`, `proposal_tag` and
  `proposal_status_changes` all declare `cascadeOnDelete` against `proposals`,
  so a hard `DELETE` would destroy every reviewer's rating and comment and the
  whole audit trail as a side effect of one speaker withdrawing a talk;
  `SoftDeletes` on `App\Models\Proposal` keeps that data intact instead.
  **Deletion is still one-way from the API's point of view** — there is no
  restore endpoint, no `withTrashed` surface, and no admin listing of deleted
  proposals. The attachment is the tell: `DeleteProposal` removes the media
  from storage inside the same transaction, before the row is even
  soft-deleted, so a later tier adding a restore feature has to solve for the
  file already being gone, not just for undeleting a row.
- **No self-registered administrators.** `POST /api/register` refuses `role: admin`
  with `422` — it only ever hands out `speaker` or `reviewer`. The seeded
  `alex@example.com` account (above) is the only admin that wasn't invited; it exists
  purely so a reviewer of this submission can still reach the admin role without
  waiting on an invitation email. Every further admin is created by an existing admin
  and invited by mail — see *Email verification & admin-managed accounts* below.

## Email verification & admin-managed accounts

Registering mails a 6-digit code, valid for 15 minutes and capped at 5 attempts.
Confirm it with `POST /api/email/verify`; if it expires or never arrives, get a new
one with `POST /api/email/resend` — reissuing replaces any unconsumed code rather
than accumulating live ones. **An unverified user may sign in and read everything
their role allows, but any write is refused** with `403` and
`{"code": "email_unverified"}` — a stable marker so the client can prompt for the
code instead of showing a generic permission error. Verifying an already-verified
account is a no-op `200`, not an error, so a client retrying after a dropped
response is never punished for it.

**Every code this app sends — verification and invitation alike — lands in Mailpit
at <http://localhost:8025>, never in a real inbox.** That's the fastest way to
unblock yourself while running this project locally: no code this API mails ever
leaves the machine.

Administrators are never self-registered (see *Notable decisions* above). Instead,
an existing admin creates the account with `POST /api/admin/users` — name, email,
role, **no password field**; the user is created with a random, unusable password
nobody ever sees. That mails a 12-character invitation code, valid for 48 hours, and
the invitee calls `POST /api/invites/accept` with their chosen password to claim the
account — which also verifies their email and returns a token, the same shape as
`POST /api/login`. The code is normalised (upper-cased, trimmed) before comparison,
so retyping it in lowercase from a copy-paste-resistant mail client doesn't silently
burn one of the five attempts.

**A lapsed invite is not a dead end.** If the 48 hours pass, or all five attempts are
spent, an admin can reissue it — `POST /api/admin/users/{user}/reinvite` — which mails
a fresh code and replaces whatever one was still outstanding. It refuses (`422`,
`{"code": "not_reinvitable"}`) for anyone who already claimed their account, since
reissuing would silently overwrite a real password only they know, and for anyone who
was never invited through this flow at all — a self-registered user who simply hasn't
verified yet also has a real password of their own that this route must never touch.
Every route under `/api/admin` is behind an admin gate that runs before route-model
binding or any request validation, the same shape as the verification gate described
above; see *API contract* for what that closes.

Two guardrails keep the last admin from locking everyone out: **an admin can never
change their own role**, and the system **refuses any role change that would leave
zero administrators**, no matter who initiates it or how many admins act
concurrently. Between them, nobody — including an admin acting on themselves — can
ever demote the system down to zero admins with no recovery short of the database.

## Tests

`make test` — Pest, `describe`/`it` with Given-When-Then bodies, run against real
MySQL so collation-dependent `LIKE` searches behave exactly as in production —
254 tests, 765 assertions, as of this branch. Coverage is deliberately
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
| `DELETE /api/proposals/{id}/attachment` | owning speaker, only while `pending` | `204` |
| `PATCH /api/reviews/{id}` | review author | `200` |
| `DELETE /api/reviews/{id}` | review author | `204` |
| `GET /api/proposals/{id}/history` | `admin` | `200` |
| `GET /api/stats` | `admin` | `200` |

This tier added seven more — email verification/resend and admin-managed accounts,
detailed in *Email verification & admin-managed accounts* above:

| Endpoint | Role | Status |
|---|---|---|
| `POST /api/email/verify` | any authenticated user | `200` |
| `POST /api/email/resend` | any authenticated user | `204` |
| `GET /api/admin/users` | `admin` | `200` |
| `POST /api/admin/users` | `admin` | `201` |
| `PATCH /api/admin/users/{user}/role` | `admin` | `200` |
| `POST /api/admin/users/{user}/reinvite` | `admin` | `200` |
| `POST /api/invites/accept` | none — public | `201` |

Every route under `/api/admin` sits behind an `admin` gate that runs before
route-model binding and before any Form Request is resolved — closing an
email-existence oracle on the create route (a taken address and a free one used to
`422` vs `403` differently for a verified non-admin, before this route ever checked
who was asking) and a user-id oracle on the role-change route (a real id and a fake
one used to `403` vs `404` differently, for the same reason). The group carries its
own rate limit — 30/min per authenticated user — on top of the per-route limiters
already listed above.

The 404-enumeration guard covers exactly six proposal-scoped routes: `PATCH /api/proposals/{id}`,
`DELETE /api/proposals/{id}`, `DELETE /api/proposals/{id}/attachment`,
`POST /api/proposals/{id}/reviews`, `PATCH /api/proposals/{id}/status`, and
`GET /api/proposals/{id}/history`. Every one of them returns an identical `404` body for
a real id the caller can't see and a fake id, closing the enumeration oracle described in
`tests/Feature/Security/NotFoundEnumerationTest.php`. `PATCH`/`DELETE /api/reviews/{id}`
are deliberately **not** in that guard: a review id is only reachable through a proposal
the caller can already see (there is no separate review index endpoint), and any reviewer
or admin can already read every review on a proposal via `GET /api/proposals/{id}` — so
there is nothing to enumerate. Those two routes deny with a plain policy `403` instead.

One further route sits outside both tables above: `GET /api/public-stats`, the
only route that returns application data without a token — added for the
signed-out screen's two marketing counters and rate-limited to 30/min per IP,
separately from every limiter listed above. It is *not* the only route outside
`auth:sanctum`: `POST /api/register`, `POST /api/login` and
`POST /api/invites/accept` sit outside it too — all three exchange credentials
for a token rather than serving data to an anonymous caller — and so do the
non-API routes `/`, `/up`, `/sanctum/csrf-cookie`, the generated docs at
`/docs/api` and `/docs/api.json`, and the signed `storage/{path}` route. Its
shape, and why it doesn't just reuse `GET /api/stats`, are in
[`docs/API.md`](docs/API.md) §01.

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
