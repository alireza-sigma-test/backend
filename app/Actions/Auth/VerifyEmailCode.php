<?php

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

        // Same two-write invariant as AcceptInvite: without the transaction a failure
        // between the two leaves the code burned and the user unverified. Recoverable
        // here via /api/email/resend, but wrapped for consistency.
        return DB::transaction(function () use ($user, $code): bool {
            if (! $this->codes->consume($user, CodePurpose::EmailVerification, $code)) {
                return false;
            }

            $user->markEmailAsVerified();

            return true;
        });
    }
}
