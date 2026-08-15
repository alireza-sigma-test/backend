# API surface — Proposal Review

> **Implementation status.** Sections `01`–`05` below are fully built and live as of
> this submission: every endpoint through `GET /api/stats` exists, is authorized, and
> is covered by tests. One exception within that range: `PATCH /api/proposals/{id}/status`
> writes the status and its audit record, but the broadcast event this document says it
> fires is not dispatched — that requires Reverb, which is not wired up yet. Section
> `06 · Live updates` (notifications, the activity feed, and broadcast channels) is not
> built at all; it belongs to a later tier (T3/T5). See the root [`README.md`](../README.md)
> for this submission's full built/not-built breakdown.

Laravel API consumed by the Vue front end. Every screen in `App Screens.dc.html` is mapped to the endpoints it calls, with the fields each one accepts and returns.

- Base path: `/api`
- Auth: Laravel Sanctum bearer token (`Authorization: Bearer <token>`)
- Content type: `application/json`, except uploads which use `multipart/form-data`
- Errors: `422` with `{ message, errors: { field: [string] } }`, `401` unauthenticated, `403` role/policy denied, `404` not found, `413` file too large

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

### `POST /api/register`
Body: `name` (required, max 80), `email` (required, email, unique), `password` (required, min 8, confirmed), `password_confirmation`, `role` (required, in `speaker,reviewer,admin`).

Returns `201` → `{ token, user: User }`.

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

### `DELETE /api/proposals/{id}/attachment`
Removes the PDF without touching the rest of the proposal. `204`.

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
Role: `admin`. Returns `[{ from, to, note, changed_by: User, changed_at }]` for the audit trail.

### `GET /api/stats`
Role: `admin`. `{ total, pending, approved, rejected, ready_to_decide }` for the header counters.

---

## 06 · Live updates

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
