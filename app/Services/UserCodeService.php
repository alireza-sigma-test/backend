<?php

// app/Services/UserCodeService.php

namespace App\Services;

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class UserCodeService
{
    public const MAX_ATTEMPTS = 5;

    /** @return string the plaintext code — the caller mails it, we keep only the hash */
    public function issue(User $user, CodePurpose $purpose): string
    {
        $code = $purpose === CodePurpose::EmailVerification
            ? str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT)
            : Str::upper(Str::random($purpose->length()));

        // Reissuing kills the previous code rather than adding to it, so an
        // attacker cannot widen the guess space by spamming resend.
        UserCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->delete();

        UserCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($purpose->ttlMinutes()),
        ]);

        return $code;
    }

    public function consume(User $user, CodePurpose $purpose, string $code): bool
    {
        $row = UserCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')
            ->first();

        if ($row === null) {
            return false;
        }

        if (! Hash::check($code, $row->code_hash)) {
            $row->increment('attempts');

            return false;
        }

        // Atomic conditional update — the WHERE and the write happen as one
        // statement under the row's lock, so a second caller racing the same
        // correct code (one that already read this row as unconsumed before
        // this update committed) re-reads the committed consumed_at the
        // instant it acquires the lock and updates zero rows. The
        // affected-row count is the only trustworthy signal of which caller
        // actually won; no transaction is needed because the atomicity lives
        // inside this single statement, not across several.
        $consumed = UserCode::whereKey($row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $consumed === 1;
    }
}
