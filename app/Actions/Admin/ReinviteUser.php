<?php

namespace App\Actions\Admin;

use App\Enums\CodePurpose;
use App\Exceptions\UserNotReinvitableException;
use App\Models\User;
use App\Models\UserCode;
use App\Notifications\AccountInvitation;
use App\Services\UserCodeService;

/**
 * Recovers an admin-created account whose only credential — the invite code —
 * expired or hit its attempt cap. Nothing else can: re-inviting and
 * self-registration both fail `unique:users,email`, login fails against the
 * unusable password CreateUserByAdmin sets, and `/email/resend` is behind
 * auth:sanctum.
 *
 * Its own route rather than overloading POST /api/admin/users, which would mean
 * relaxing `unique:users,email` conditionally and re-deriving "safe to reissue"
 * on every create — a validate-then-branch shape that reads as an oracle.
 */
final class ReinviteUser
{
    public function __construct(private UserCodeService $codes) {}

    public function handle(User $target): User
    {
        // Eligible only for an unclaimed account this flow itself created. Accepting
        // an invite is what verifies the email, so the two conditions together mean
        // "invited and never accepted".
        $wasInvited = UserCode::where('user_id', $target->id)
            ->where('purpose', CodePurpose::Invite)
            ->exists();

        throw_unless($wasInvited && ! $target->hasVerifiedEmail(), UserNotReinvitableException::class);

        $target->notify(new AccountInvitation($this->codes->issue($target, CodePurpose::Invite)));

        return $target->fresh()->load('roles');
    }
}
