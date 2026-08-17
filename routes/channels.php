<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Every callback below is an authorization check, not plumbing: a private channel is
| authorized once at subscribe time, and everything broadcast to it afterwards reaches
| every subscriber unchecked. Gating on mere authentication is a data leak, so
| `return true` here would hand every signed-in browser the whole feed.
|
| Names are fixed in docs/design/API.md §06; Laravel adds the `private-` prefix on the
| wire. These run under `auth:sanctum`, named explicitly in bootstrap/app.php, because
| the framework default is the `web` group this application does not use.
*/

// Both sides cast: the route segment arrives as a string.
Broadcast::channel('user.{id}', fn (User $user, string $id) => (int) $user->id === (int) $id);

// Two literal channels, not a `role.{name}` wildcard, which would authorize any role
// the subscriber holds — including one added later for an unrelated purpose. A third
// channel needs a line here.
//
// Not a hierarchy: an admin is refused on the reviewer channel.
Broadcast::channel('role.reviewer', fn (User $user) => $user->hasRole(UserRole::Reviewer->value));
Broadcast::channel('role.admin', fn (User $user) => $user->hasRole(UserRole::Admin->value));
