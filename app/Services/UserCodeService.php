<?php

namespace App\Services;

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class UserCodeService
{
    public const MAX_ATTEMPTS = 5;

    /**
     * A real bcrypt hash of a value nobody knows, burned in consume() so the
     * no-active-code branch costs what a real comparison costs. Same value as
     * LoginUser::DUMMY_HASH and AcceptInvite::DUMMY_HASH.
     */
    private const DUMMY_HASH = '$2y$12$Pj/5N/Ebkm2yTUup94tKZ.tP6xd8sEJxcnnTOnq5relN9n3Z7pPpS';

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
            // Without this the branch returns in ~1ms against Hash::check's ~150ms,
            // disclosing whether a live code is currently outstanding for an address.
            Hash::check($code, self::DUMMY_HASH);

            return false;
        }

        if (! Hash::check($code, $row->code_hash)) {
            $row->increment('attempts');

            return false;
        }

        // One atomic statement, so a second caller racing the same correct code
        // updates zero rows. The affected-row count is the only trustworthy
        // signal of which caller won.
        $consumed = UserCode::whereKey($row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $consumed === 1;
    }
}
