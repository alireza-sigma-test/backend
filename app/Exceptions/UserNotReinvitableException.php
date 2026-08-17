<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ReinviteUser when the target is not an admin-created account still
 * waiting to be claimed. Both refused states — already accepted, or never invited
 * through this flow — mean the user has a real password that reissuing an invite
 * would silently replace, which would make this a password-reset backdoor.
 *
 * Rendered as 422, not 403: the caller already cleared UserPolicy::reinvite, so the
 * request simply does not apply to this user's state.
 */
final class UserNotReinvitableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This user cannot be re-invited.');
    }
}
