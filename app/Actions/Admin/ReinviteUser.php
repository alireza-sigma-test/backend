<?php

// app/Actions/Admin/ReinviteUser.php

namespace App\Actions\Admin;

use App\Enums\CodePurpose;
use App\Exceptions\UserNotReinvitableException;
use App\Models\User;
use App\Models\UserCode;
use App\Notifications\AccountInvitation;
use App\Services\UserCodeService;

/**
 * Recovers the account finding 2 of the final review found permanently dead:
 * an admin-created user whose only credential — the 12-character invite code
 * — expired or exhausted its attempt cap. Before this action, nothing could
 * ever reissue it: re-inviting failed on `unique:users,email`, self-registration
 * failed the same way, login failed against the random unusable password
 * CreateUserByAdmin set, and `/email/resend` sits behind `auth:sanctum`, which
 * a user with no working credential can never reach. The address was burnt
 * forever.
 *
 * Deliberately its own route (`POST /api/admin/users/{user}/reinvite`) rather
 * than overloading `POST /api/admin/users` to reissue on a matching email:
 * that would mean relaxing `unique:users,email` conditionally inside the
 * create path and re-deriving, on every create request, whether a matched
 * row is "safe to reissue" — the exact kind of validate-then-branch shape
 * that made finding 1 an oracle in the first place, just moved rather than
 * removed. A dedicated action, bound to a specific user id the admin already
 * saw via GET /api/admin/users, keeps create and reissue as two decisions
 * instead of one endpoint doing both.
 */
final class ReinviteUser
{
    public function __construct(private UserCodeService $codes) {}

    public function handle(User $target): User
    {
        // Eligible only for an account this flow itself put in this state:
        // never claimed (no password of their own ever set — accepting an
        // invite is what verifies the email, so this is the same check as
        // "has this invite ever been accepted"), and only ever reachable
        // through an admin invite in the first place. See
        // UserNotReinvitableException for why both branches matter.
        $wasInvited = UserCode::where('user_id', $target->id)
            ->where('purpose', CodePurpose::Invite)
            ->exists();

        throw_unless($wasInvited && ! $target->hasVerifiedEmail(), UserNotReinvitableException::class);

        $target->notify(new AccountInvitation($this->codes->issue($target, CodePurpose::Invite)));

        return $target->fresh()->load('roles');
    }
}
