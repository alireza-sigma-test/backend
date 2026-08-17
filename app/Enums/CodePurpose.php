<?php

namespace App\Enums;

enum CodePurpose: string
{
    case EmailVerification = 'email_verification';
    case Invite = 'invite';

    /**
     * Six digits for verification — low value, retyped often. Twelve for an invite,
     * the only credential for claiming an account, bounded by staying email-readable.
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
