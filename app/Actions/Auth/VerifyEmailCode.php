<?php

// app/Actions/Auth/VerifyEmailCode.php

namespace App\Actions\Auth;

use App\Enums\CodePurpose;
use App\Models\User;
use App\Services\UserCodeService;

final class VerifyEmailCode
{
    public function __construct(private UserCodeService $codes) {}

    public function handle(User $user, string $code): bool
    {
        // Already verified is success, not failure — the desired state holds.
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        if (! $this->codes->consume($user, CodePurpose::EmailVerification, $code)) {
            return false;
        }

        $user->markEmailAsVerified();

        return true;
    }
}
