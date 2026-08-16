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

    /**
     * A real bcrypt hash, at the app's configured cost, of a value nobody
     * knows. Burned on the no-active-row branch of consume() below so that
     * branch costs what a real comparison costs.
     *
     * Same value and same rationale as LoginUser::DUMMY_HASH and
     * AcceptInvite::DUMMY_HASH — kept as its own copy here rather than a
     * shared constant. consume() is called by both AcceptInvite and
     * VerifyEmailCode, so a shared home would have to sit above both
     * without dragging in either action's namespace, and LoginUser is
     * older code with its own tests that this task has no reason to touch.
     * Three small, independently readable copies cost less than one new
     * shared abstraction whose only two willing tenants are this file and
     * AcceptInvite.
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
            // No active code — never issued, already consumed, expired, or
            // attempt-capped. Left as a bare return, this branch answers in
            // ~1ms against the ~150ms+ a real Hash::check below costs, and
            // registration already discloses whether an email exists — so
            // the gap discloses something registration cannot: whether a
            // live code is *currently* outstanding for a known address.
            Hash::check($code, self::DUMMY_HASH);

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
