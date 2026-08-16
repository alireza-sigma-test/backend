<?php

// tests/Feature/Auth/UserCodeServiceTest.php

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use App\Services\UserCodeService;
use Database\Seeders\RoleSeeder;

describe('single-use codes', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        $this->service = app(UserCodeService::class);
    });

    it('stores only a hash, never the code itself', function () {
        // Given
        $user = User::factory()->speaker()->create();

        // When
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        // Then — the plaintext must not be recoverable from the row.
        $row = UserCode::where('user_id', $user->id)->sole();
        expect($row->code_hash)->not->toBe($code)
            ->and(str_contains($row->code_hash, $code))->toBeFalse();
    });

    it('issues a six-digit code for verification and twelve characters for an invite', function () {
        // Given
        $user = User::factory()->speaker()->create();

        // When / Then
        expect($this->service->issue($user, CodePurpose::EmailVerification))->toMatch('/^\d{6}$/')
            ->and($this->service->issue($user, CodePurpose::Invite))->toHaveLength(12);
    });

    it('accepts the right code exactly once', function () {
        // Given
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        // When / Then — the second attempt fails because the code is consumed.
        expect($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeTrue()
            ->and($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeFalse();
    });

    it('rejects a code that has expired', function () {
        // Given
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);
        $this->travel(CodePurpose::EmailVerification->ttlMinutes() + 1)->minutes();

        // When / Then
        expect($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeFalse();
    });

    it('kills the code after five wrong attempts, even if the sixth is right', function () {
        // Given
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        // When
        foreach (range(1, UserCodeService::MAX_ATTEMPTS) as $ignored) {
            $this->service->consume($user, CodePurpose::EmailVerification, '000000');
        }

        // Then — a correct code past the cap must still be refused, or the cap
        // only delays a brute force instead of stopping it.
        expect($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeFalse();
    });

    it('replaces an unconsumed code when a new one is issued', function () {
        // Given
        $user = User::factory()->speaker()->create();
        $first = $this->service->issue($user, CodePurpose::EmailVerification);

        // When
        $second = $this->service->issue($user, CodePurpose::EmailVerification);

        // Then — reissuing must not leave two live codes behind.
        expect($this->service->consume($user, CodePurpose::EmailVerification, $first))->toBeFalse()
            ->and($this->service->consume($user, CodePurpose::EmailVerification, $second))->toBeTrue();
    });

    it('keeps the two purposes independent', function () {
        // Given
        $user = User::factory()->speaker()->create();
        $verification = $this->service->issue($user, CodePurpose::EmailVerification);
        $this->service->issue($user, CodePurpose::Invite);

        // Then — issuing an invite must not invalidate a verification code.
        expect($this->service->consume($user, CodePurpose::EmailVerification, $verification))->toBeTrue();
    });

    it('does not let two racing callers both consume the same correct code', function () {
        // Given — a single-process suite cannot drive two truly simultaneous
        // callers, so this simulates the interleaving directly instead of
        // claiming a real race: the moment this call's SELECT hydrates the
        // row (its "read"), a hook runs a second, complete consume() call for
        // the same code before this call reaches its own write — standing in
        // for a request that raced in during that gap and won it. Both calls
        // therefore act on a row they each believe is still unconsumed.
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        $racerConsumed = null;
        UserCode::retrieved(function () use (&$racerConsumed, $user, $code): void {
            // Fire once — the racer's own SELECT below must not recurse.
            UserCode::flushEventListeners();
            $racerConsumed = $this->service->consume($user, CodePurpose::EmailVerification, $code);
        });

        // When
        try {
            $firstConsumed = $this->service->consume($user, CodePurpose::EmailVerification, $code);
        } finally {
            UserCode::flushEventListeners();
        }

        // Then — the racer's write lands first and must win; this call reads
        // the row before that write but must still lose once it reaches its
        // own write, or "set once, never reused" breaks under concurrency.
        expect($racerConsumed)->toBeTrue()
            ->and($firstConsumed)->toBeFalse();
    });
});
