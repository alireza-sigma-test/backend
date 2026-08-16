<?php

// app/Exceptions/UserNotReinvitableException.php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ReinviteUser when the target is not an admin-created account
 * still waiting to be claimed.
 *
 * Two states collapse into this one exception, deliberately: a user who
 * already accepted their invite (or verified any other way) has a real
 * password only they know, and reissuing an invite would silently replace
 * it — exactly the password-reset backdoor finding 2 of the final review
 * warned against, just reached through a different door. A user who was
 * never invited through this flow at all — a self-registered account that
 * simply hasn't verified yet — has a real password too, for the same
 * reason: reinviting them would overwrite it and strand them behind a
 * brand-new 48-hour clock they never asked for, reopening the exact
 * lockout this action exists to fix, from the other side.
 *
 * Rendered as 422, not 403: the caller already cleared UserPolicy::reinvite
 * (a genuine, verified admin), so this is not an authorization refusal —
 * the request just does not apply to this user's current state.
 */
final class UserNotReinvitableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This user cannot be re-invited.');
    }
}
