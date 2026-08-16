<?php

// routes/channels.php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Every callback below is an authorization check, not plumbing. A private
| channel is authorized once, when the client subscribes; from then on every
| event broadcast to it reaches every subscriber, with nothing re-checking who
| they are. A channel gated on mere authentication is therefore a data leak,
| not a lenient permission — `return true` here would hand every signed-in
| browser the whole feed.
|
| The channel names are the ones fixed in docs/design/API.md §06. Laravel adds
| the `private-` prefix on the wire, so `user.{id}` here is `private-user.{id}`
| in the browser.
|
| What reaches each of these is deliberately thin — see the Event classes in
| app/Events, whose payload tests pin the exact key set.
|
| These run under `auth:sanctum`, named explicitly in bootstrap/app.php. The
| framework's default for broadcasting routes is the `web` group, which this
| application does not use; see that file for why.
*/

// The only membership test that matters here: the subscriber IS the user whose
// channel this is. Cast both sides — the route segment arrives as a string,
// and `0 == 'anything'` style surprises are exactly what this guards.
Broadcast::channel('user.{id}', fn (User $user, string $id) => (int) $user->id === (int) $id);

// Two literal channels rather than one `role.{name}` wildcard. A wildcard would
// authorize any role the subscriber happens to hold, including one added later
// for an unrelated purpose, and it would silently create a channel for every
// string anyone ever broadcasts to. These two exist because API.md says they
// do; a third needs a line here.
//
// Not a hierarchy: an admin does not hold the reviewer role and is refused on
// the reviewer channel. Admins have their own.
Broadcast::channel('role.reviewer', fn (User $user) => $user->hasRole(UserRole::Reviewer->value));
Broadcast::channel('role.admin', fn (User $user) => $user->hasRole(UserRole::Admin->value));
