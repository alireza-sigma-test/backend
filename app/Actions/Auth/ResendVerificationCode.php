<?php

namespace App\Actions\Auth;

use App\Enums\CodePurpose;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Services\UserCodeService;

final class ResendVerificationCode
{
    public function __construct(private UserCodeService $codes) {}

    public function handle(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->notify(new EmailVerificationCode($this->codes->issue($user, CodePurpose::EmailVerification)));
    }
}
