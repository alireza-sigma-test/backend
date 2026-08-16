<?php

// app/Enums/CodePurpose.php

namespace App\Enums;

enum CodePurpose: string
{
    case EmailVerification = 'email_verification';
    case Invite = 'invite';

    /**
     * Six digits for verification: low value, high frequency, retyped often.
     * Twelve characters for an invite: it is the only credential for claiming
     * an account, so it earns a far larger search space — but it still has to
     * be readable out of an email, which is why it is not longer than that.
     */
    public function length(): int
    {
        return match ($this) {
            self::EmailVerification => 6,
            self::Invite => 12,
        };
    }

    /** An invite must survive a weekend; a verification code must not. */
    public function ttlMinutes(): int
    {
        return match ($this) {
            self::EmailVerification => 15,
            self::Invite => 60 * 48,
        };
    }
}
