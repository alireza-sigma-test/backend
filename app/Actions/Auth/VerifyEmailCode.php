<?php

// app/Actions/Auth/VerifyEmailCode.php

namespace App\Actions\Auth;

use App\Enums\CodePurpose;
use App\Models\User;
use App\Services\UserCodeService;
use Illuminate\Support\Facades\DB;

final class VerifyEmailCode
{
    public function __construct(private UserCodeService $codes) {}

    public function handle(User $user, string $code): bool
    {
        // Already verified is success, not failure — the desired state holds.
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        // Same shape as AcceptInvite: consuming the code and marking the
        // email verified are a two-write invariant that consume()'s own
        // atomic update — correct on its single column — cannot cover on
        // its own. Without the transaction, a failure between the two
        // leaves the code burned with the user still unverified. Benign
        // here (unlike the invite path) because /api/email/resend issues a
        // fresh code and recovers it, but the asymmetry with AcceptInvite
        // was undocumented and reads as an oversight rather than a
        // deliberate choice, so it is wrapped for consistency.
        return DB::transaction(function () use ($user, $code): bool {
            if (! $this->codes->consume($user, CodePurpose::EmailVerification, $code)) {
                return false;
            }

            $user->markEmailAsVerified();

            return true;
        });
    }
}
