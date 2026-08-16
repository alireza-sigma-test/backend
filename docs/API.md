# API surface — Proposal Review

> **Implementation status.** Sections `01`–`07` below are fully built and live as of
> this submission: every endpoint through `POST /api/admin/users/{user}/reinvite` exists,
> is authorized, and is covered by tests. One exception within that range:
> `PATCH /api/proposals/{id}/status` writes the status and its audit record, but the
> broadcast event this document says it fires is not dispatched — that requires
> Reverb, which is not wired up yet. Section `08 · Live updates` (notifications, the
> activity feed, and broadcast channels) is not built at all; it belongs to a later
> tier (T3/T5). See the root [`README.md`](../README.md) for this submission's full
> built/not-built breakdown.

Laravel API consumed by the Vue front end. Every screen in `App Screens.dc.html` is mapped to the endpoints it calls, with the fields each one accepts and returns.

- Base path: `/api`
- Auth: Laravel Sanctum bearer token (`Authorization: Bearer <token>`)
- Content type: `application/json`, except uploads which use `multipart/form-data`
- Errors: `422` with `{ message, errors: { field: [string] } }`, `401` unauthenticated, `403` role/policy denied, `404` not found, `413` file too large
- Some `403`s carry a stable machine-readable `code` alongside `message`, for clients that need to react rather than just display it: `email_unverified` (§06) and `last_admin` (§07). A `422` in §07 carries one too: `not_reinvitable`.

Roles: `speaker`, `reviewer`, `admin`.

---

## Resource shapes

### `User`
| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `name` | string | |
| `email` | string | unique |
| `role` | enum | `speaker` \| `reviewer` \| `admin` |
| `initials` | string | derived, 2 chars — used by the avatar component |
| `created_at` | ISO 8601 | |
| `email_verified_at` | ISO 8601\|null | `null` until `POST /api/email/verify` or `POST /api/invites/accept` succeeds |
| `is_verified` | bool | `email_verified_at !== null`, denormalised so clients never re-derive it from the timestamp |

### `Tag`
| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `name` | string | unique, max 40 |
| `slug` | string | derived |
| `proposals_count` | int | only present on the tag index |

### `Proposal`
| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `ref` | string | display id, e.g. `#PR-1042` |
| `title` | string | max 120 |
| `description` | string | plain text |
| `status` | enum | `pending` \| `approved` \| `rejected` — defaults to `pending` |
| `tags` | `Tag[]` | may be empty |
| `author` | `User` | trimmed: `id`, `name`, `initials` |
| `attachment` | `Attachment \| null` | |
| `average_rating` | float\|null | 1 decimal, `null` with no reviews |
| `reviews_count` | int | |
| `my_review` | `Review \| null` | only for reviewers — the caller's own review |
| `can` | object | `{ edit, review, change_status }` — policy result, drives which controls the UI renders |
| `created_at` / `updated_at` | ISO 8601 | |

### `Attachment`
| Field | Type | Notes |
|---|---|---|
| `filename` | string | original name |
| `size_bytes` | int | ≤ 4 194 304 |
| `mime` | string | always `application/pdf` |
| `url` | string | temporary signed URL |

### `Review`
| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `rating` | int | 1…`max_rating` |
| `comment` | string\|null | max 2000 |
| `reviewer` | `User` | trimmed |
| `created_at` | ISO 8601 | |

---

## 01 · Sign in & register

### `GET /api/public-stats`
No auth — the only unauthenticated route in the app. Powers the two marketing
counters on the signed-out screen, shown before a visitor registers or logs in.

Returns `200` → `{ proposals_this_year: int, reviewers: int }` — e.g.
`{"proposals_this_year": 6, "reviewers": 4}` against the seeded database.
`reviewers` counts users holding the reviewer role rather than distinct review
authors, so the count can't disclose which proposals have been reviewed.

Deliberately not `GET /api/stats` (§05): that endpoint returns
`pending`/`approved`/`rejected`/`ready_to_decide` — the decision pipeline — which
is not public information, so it stays behind `auth:sanctum` and `admin`. This
route returns only the two integers above and nothing else.

Rate-limited to 30/min per IP.

### `POST /api/register`
Body: `name` (required, max 80), `email` (required, email, unique), `password` (required, min 8, confirmed), `password_confirmation`, `role` (required, in `speaker,reviewer` — **not** `admin`; see §07).

Returns `201` → `{ token, user: User }`. The user is unverified (`is_verified: false`);
registering also mails a 6-digit verification code — see §06.

### `POST /api/invites/accept`
No auth — this is how an admin-created account (§07) is claimed, before the invitee has any credential.

Body: `email` (required), `code` (required, string — the 12-character invite code from the invitation email), `password` (required, min 8, confirmed).

`code` is normalised (upper-cased, trimmed) before comparison, so pasting it exactly
as mailed is not required — a code retyped in lowercase, or with stray whitespace
picked up from an email client, still matches instead of silently burning one of the
five attempts.

Returns `201` → `{ token, user: User }`, same shape as login. Also verifies the user's
email and sets their password in one step. `422` on a wrong code, an expired/consumed
invite, or an unknown email — **all three produce the exact same response body**,
deliberately: `{"message": "That invitation is not valid or has expired.", "errors": {"code": [...]}}`.
Distinguishing them would let a caller enumerate which email addresses exist and which
invitations are still outstanding; the comparison is constant-time for the same reason,
so an unknown address can't be told apart from a wrong code by response time either.
Rate-limited to 6/min per IP.

If the code lapses — expired past 48 hours, or all five attempts spent — the account
is not lost: an admin can reissue it with `POST /api/admin/users/{user}/reinvite` (§07).

### `POST /api/login`
Body: `email` (required), `password` (required), `remember` (bool, optional).

Returns `200` → `{ token, user: User }`; `422` on bad credentials.

### `POST /api/logout`
No body. Revokes the current token. `204`.

### `GET /api/me`
Returns the authenticated `User` — used to restore the session and pick the initial route per role.

---

## 02 · Review list

### `GET /api/proposals`
All roles. Speakers receive only their own proposals; reviewers and admins receive all.

Query parameters:
| Param | Type | Notes |
|---|---|---|
| `search` | string | matches `title`, case-insensitive |
| `tags` | string | comma-separated tag slugs or ids — OR semantics |
| `status` | enum | `pending` \| `approved` \| `rejected` |
| `author_id` | int | admin/reviewer only |
| `sort` | enum | `newest` (default) \| `oldest` \| `rating` |
| `per_page` | int | default 15, max 50 |
| `page` | int | |

Returns a paginated envelope:

```
{
  "data": [Proposal],
  "meta": { "current_page", "last_page", "per_page", "total" },
  "counts": { "all", "pending", "approved", "rejected" }
}
```

`counts` feeds the sidebar status tallies and is unaffected by `search`/`tags` so the numbers stay stable while filtering.

### `GET /api/tags`
Returns `Tag[]` including `proposals_count`, for the sidebar filter list and the tag autocomplete.

---

## 03 · Submit a proposal

### `POST /api/proposals`
Role: `speaker`. `multipart/form-data` when a file is attached.

| Field | Type | Rules |
|---|---|---|
| `title` | string | required, min 8, max 120 |
| `description` | string | required, min 40, max 20000 |
| `tags[]` | array | optional; each item is an existing tag id **or** a new tag name (max 40). New names are created and attached in one transaction |
| `attachment` | file | optional, `mimes:pdf`, `max:4096` (KB) |

Returns `201` → `Proposal`. Status is always `pending` on create; a client-sent `status` is ignored.

### `PATCH /api/proposals/{id}`
Role: owning `speaker`, and only while `status = pending`. Same fields as create, all optional. `403` once a decision exists.

### `DELETE /api/proposals/{id}`
Role: owning `speaker` while pending, or `admin`. `204`.

Soft delete: the row gets a `deleted_at` timestamp rather than being removed.
Its reviews and status-change history are left in place, untouched; the
proposal itself disappears from `GET /api/proposals`, `GET /api/proposals/{id}`
and every aggregate (`GET /api/stats`, `GET /api/public-stats` above, and the
`counts` object on `GET /api/proposals`). The attachment is deleted from
storage in the same transaction, and there is no restore endpoint.

### `DELETE /api/proposals/{id}/attachment`
Role: owning `speaker`, only while `status = pending`. Removes the PDF without touching the rest
of the proposal. `204`. Gated on the same rule as `PATCH` above — an admin can delete the whole
proposal but cannot strip just its PDF; a decided proposal's attachment is as frozen as its text.

---

## 04 · Proposal detail

### `GET /api/proposals/{id}`
Returns a `Proposal` plus:

| Field | Type | Notes |
|---|---|---|
| `reviews` | `Review[]` | reviewers/admins see everything; the owning speaker sees `comment` and `created_at` but **not** `rating` or `reviewer` |
| `rating_distribution` | object | `{ "1": int, … "5": int }` — drives the aggregate bars |
| `max_rating` | int | 5 or 10, from app config |

### `POST /api/proposals/{id}/reviews`
Role: `reviewer` (and `admin` if you allow it). One review per reviewer per proposal — a second call updates the existing one.

| Field | Type | Rules |
|---|---|---|
| `rating` | int | required, `between:1,max_rating` |
| `comment` | string | optional, max 2000 |

Returns `201` → `{ review: Review, average_rating, reviews_count }`.

### `PATCH /api/reviews/{id}` · `DELETE /api/reviews/{id}`
Role: review author. Same fields; delete returns `204` and recomputes the average.

---

## 05 · Admin decisions

### `GET /api/proposals?status=pending&sort=rating`
The decision queue is the list endpoint with a preset filter — no separate route needed.

### `PATCH /api/proposals/{id}/status`
Role: `admin` only.

| Field | Type | Rules |
|---|---|---|
| `status` | enum | required, in `pending,approved,rejected` |
| `note` | string | optional, max 500 — the message shown to the speaker on rejection |

Returns `200` → `{ proposal: Proposal, changed_by: User, changed_at }`. Writes a status-change record and fires the broadcast event.

### `GET /api/proposals/{id}/history`
Role: `admin`. Returns `{ data: [{ id, from, to, note, changed_by: User, changed_at }] }` for the
audit trail, newest first — the same `data` envelope as `GET /api/proposals`.

### `GET /api/stats`
Role: `admin`. `{ total, pending, approved, rejected, ready_to_decide }` for the header counters.

---

## 06 · Email verification

### `POST /api/email/verify`
Auth: any authenticated user (verified or not — this is one of the two routes an
unverified caller must still be able to reach; see the gating note below).

Body: `code` (required, string — the 6-digit code mailed on registration or by resend, 15-minute expiry, 5-attempt cap per code).

Returns `200` → the caller's own `User`, `is_verified: true`. Calling this on an
already-verified account also returns `200`, unchanged, and consumes nothing — a
client retrying after a dropped response isn't punished for it. `422` on a wrong,
expired, or attempt-exhausted code — `{"code": ["That code is not valid or has expired."]}`.
Rate-limited to 6/min per user.

### `POST /api/email/resend`
Auth: any authenticated user. No body.

Issues a fresh code — replacing any unconsumed one for that user, so reissuing can
never leave two codes valid at once — and mails it. Returns `204`, even for an
already-verified user (who gets no new mail; the action is a silent no-op for them).
Rate-limited to 3 per 10 minutes per user.

### Write gating
**An unverified user may sign in and read everything their role allows, but any
write is refused.** Every mutating route in §03, §04 and §05
(`POST/PATCH/DELETE /api/proposals*`, `POST/PATCH/DELETE /api/reviews/{id}`,
`PATCH /api/proposals/{id}/status`) plus the three admin-write routes in §07 return
`403` with `{"code": "email_unverified"}` for an unverified caller — distinct from a
plain policy-denial `403` so the client can prompt for the verification code instead
of showing a generic permission error. `POST /api/email/verify` and
`POST /api/email/resend` themselves are deliberately exempt — they're the only way
out of "unverified", so gating them would make verification impossible.

**Every code this API mails — verification here and invitations in §07 — is caught
by Mailpit at `http://localhost:8025`, never a real inbox.** See the root
[`README.md`](../README.md).

## 07 · Admin user management & invitations

Every route below sits behind an admin gate that refuses a non-admin caller *before*
route-model binding or any request validation runs — so a fake user id and a real one
the caller isn't allowed to touch get the identical refusal, and a taken email address
and a free one on `POST /api/admin/users` do too (see the enumeration note on that
route below). The whole group is also rate-limited to 30/min per authenticated user,
alongside the per-route limiters documented elsewhere in this file.

### `GET /api/admin/users`
Role: `admin`. Returns a paginated envelope of `User` (same `data`/`meta` shape as
`GET /api/proposals`, no `counts`). This is the only endpoint that lists every user's
email address, which is why it's admin-only.

### `POST /api/admin/users`
Role: `admin`. This — not self-registration — is how every admin except the seeded
`alex@example.com` comes to exist.

Body: `name` (required, max 80), `email` (required, email, unique), `role` (required,
in `speaker,reviewer,admin`) — **no `password` field**. The account is created with a
random, unusable password nobody (not even the creating admin) ever sees.

Returns `201` → `User`, unverified. Mails a 12-character invitation code (see
`POST /api/invites/accept`, §01) valid for 48 hours and capped at 5 attempts.

A non-admin caller is refused before `email` is ever checked for uniqueness — a taken
address and a free one both `403` identically, so this route cannot be used to test
whether an email is already registered.

### `POST /api/admin/users/{user}/reinvite`
Role: `admin`. No body.

Recovers an admin-created account whose invite lapsed — the 48-hour expiry passed, or
all 5 attempts were spent — by reissuing a fresh 12-character code and re-mailing it,
replacing whatever code (if any) was still outstanding. Returns `200` → the `User`,
still unverified.

`422` with `{"code": "not_reinvitable"}` for a target that either already accepted
their invite (a real password only they know — reissuing would silently replace it,
the exact password-reset backdoor this route must not become) or was never invited
through this flow in the first place (a self-registered user who simply hasn't
verified yet, and who has a real password of their own). Only an account created by
`POST /api/admin/users` above, still unclaimed, is eligible.

A dedicated route rather than overloading `POST /api/admin/users` to reissue on a
matching email: that would mean conditionally relaxing `unique:users,email` inside
the create path itself, re-deriving on every request whether a match is safe to
reissue — reopening the exact validate-then-branch shape that made `POST
/api/admin/users` an enumeration oracle in the first place, just moved rather than
removed.

### `PATCH /api/admin/users/{user}/role`
Role: `admin`. Body: `role` (required, in `speaker,reviewer,admin`).

Returns `200` → the updated `User`. Two refusals, both `403`:
- **Self-demotion:** an admin can never change their *own* role, full stop — plain
  policy `403`, no `code`. Otherwise the last admin could lock every admin function
  out with no recovery short of the database.
- **Last admin:** any change — targeting someone else, by any admin — that would
  leave the system with zero administrators is refused with `{"code": "last_admin"}`.
  This holds even under two different admins concurrently demoting two different
  targets; the check is against the whole admin set, not just the one row being
  written.

Role changes take effect on the caller's *next* request — roles are checked live, so
no token revocation is needed.

A non-admin caller is refused before `{user}` is even resolved to a row, so a real
user id and one that was never real at all both `403` identically — this route
cannot be used to enumerate which user ids exist, the same guarantee §03/§04
already give the proposal and review ids.

## 08 · Live updates

### `GET /api/notifications`
Query: `unread_only` (bool), `per_page`. Returns paginated:

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `type` | enum | `proposal.created` \| `proposal.updated` \| `review.created` \| `proposal.status_changed` |
| `title` | string | e.g. "New proposal to review" |
| `body` | string | e.g. "\"Type-safe APIs end to end\" — Ilya Petrov" |
| `proposal_id` | int\|null | deep-link target |
| `read_at` | ISO 8601\|null | |
| `created_at` | ISO 8601 | |

Plus `meta.unread_count` for the badge.

### `POST /api/notifications/{id}/read` · `POST /api/notifications/read-all`
`204`. Both return the new `unread_count` in the response header `X-Unread-Count`.

### `GET /api/activity`
Query: `per_page`. The activity feed on screen 06 — same event `type` vocabulary, but scoped to everything the caller may see rather than to notifications addressed to them.

### Broadcast channels
| Channel | Who | Events |
|---|---|---|
| `private-user.{id}` | that user | `proposal.status_changed`, `review.created` on own proposals |
| `private-role.reviewer` | reviewers | `proposal.created`, `proposal.updated` |
| `private-role.admin` | admins | `proposal.created`, `review.created` |

Payload for every event: `{ type, proposal: { id, ref, title, status }, actor: { id, name, initials }, occurred_at }`. The client uses the payload to patch its store; it does not refetch the list.

Auth endpoint for the socket: `POST /broadcasting/auth` (standard Laravel).

---

## Notes for implementation

- Validation lives in Form Request classes so the same rules serve the API and any future server-rendered form; the front end mirrors only the cheap checks (required, length, PDF, 4 MB).
- `can` on each `Proposal` is generated from the policy, so the Vue components never infer permissions from `role` strings.
- Tag creation on proposal submit is idempotent by `slug` — two speakers typing "testing" at once end up on one tag.
- Rating scale is a single config value (`config('review.max_rating')`) surfaced as `max_rating`; the `RatingInput` component reads it rather than hard-coding 5.
