<?php

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use App\Services\UserCodeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

describe('single-use codes', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        $this->service = app(UserCodeService::class);
    });

    it('stores only a hash, never the code itself', function () {
        $user = User::factory()->speaker()->create();

        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        $row = UserCode::where('user_id', $user->id)->sole();
        expect($row->code_hash)->not->toBe($code)
            ->and(str_contains($row->code_hash, $code))->toBeFalse();
    });

    it('issues a six-digit code for verification and twelve characters for an invite', function () {
        $user = User::factory()->speaker()->create();

        expect($this->service->issue($user, CodePurpose::EmailVerification))->toMatch('/^\d{6}$/')
            ->and($this->service->issue($user, CodePurpose::Invite))->toHaveLength(12);
    });

    it('accepts the right code exactly once', function () {
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        expect($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeTrue()
            ->and($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeFalse();
    });

    it('rejects a code that has expired', function () {
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);
        $this->travel(CodePurpose::EmailVerification->ttlMinutes() + 1)->minutes();

        expect($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeFalse();
    });

    it('kills the code after five wrong attempts, even if the sixth is right', function () {
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        foreach (range(1, UserCodeService::MAX_ATTEMPTS) as $ignored) {
            $this->service->consume($user, CodePurpose::EmailVerification, '000000');
        }

        // A correct code past the cap must still be refused, or the cap
        // only delays a brute force instead of stopping it.
        expect($this->service->consume($user, CodePurpose::EmailVerification, $code))->toBeFalse();
    });

    it('replaces an unconsumed code when a new one is issued', function () {
        $user = User::factory()->speaker()->create();
        $first = $this->service->issue($user, CodePurpose::EmailVerification);

        $second = $this->service->issue($user, CodePurpose::EmailVerification);

        expect($this->service->consume($user, CodePurpose::EmailVerification, $first))->toBeFalse()
            ->and($this->service->consume($user, CodePurpose::EmailVerification, $second))->toBeTrue();
    });

    it('keeps the two purposes independent', function () {
        $user = User::factory()->speaker()->create();
        $verification = $this->service->issue($user, CodePurpose::EmailVerification);
        $this->service->issue($user, CodePurpose::Invite);

        expect($this->service->consume($user, CodePurpose::EmailVerification, $verification))->toBeTrue();
    });

    it('does not let two racing callers both consume the same correct code', function () {
        // A single-process suite cannot run two callers at once, so the interleaving is
        // simulated: when this call's SELECT hydrates the row, a hook runs a complete
        // consume() for the same code before this one writes. Both then act on a row
        // they each believe is unconsumed.
        $user = User::factory()->speaker()->create();
        $code = $this->service->issue($user, CodePurpose::EmailVerification);

        $racerConsumed = null;
        UserCode::retrieved(function () use (&$racerConsumed, $user, $code): void {
            // Fire once — the racer's own SELECT below must not recurse.
            UserCode::flushEventListeners();
            $racerConsumed = $this->service->consume($user, CodePurpose::EmailVerification, $code);
        });

        try {
            $firstConsumed = $this->service->consume($user, CodePurpose::EmailVerification, $code);
        } finally {
            UserCode::flushEventListeners();
        }

        // The racer's write lands first and must win; this call reads
        // the row before that write but must still lose once it reaches its
        // own write, or "set once, never reused" breaks under concurrency.
        expect($racerConsumed)->toBeTrue()
            ->and($firstConsumed)->toBeFalse();
    });

    it('burns comparable time whether or not an active code exists, for both purposes', function () {
        // Hashed at the app's real cost (12), not the suite's BCRYPT_ROUNDS=4: bcrypt
        // reads its work factor from the hash itself, so a cost-4 row would manufacture
        // the very timing gap this test rules out.
        foreach ([CodePurpose::Invite, CodePurpose::EmailVerification] as $purpose) {
            $userWithLiveCode = User::factory()->speaker()->create();
            UserCode::create([
                'user_id' => $userWithLiveCode->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make('REALCODE1234', ['rounds' => 12]),
                'expires_at' => now()->addMinutes(15),
            ]);
            $userWithNoCode = User::factory()->speaker()->create();

            $start = hrtime(true);
            $this->service->consume($userWithLiveCode, $purpose, 'WRONGWRONG12');
            $wrongCodeElapsed = hrtime(true) - $start;

            $start = hrtime(true);
            $this->service->consume($userWithNoCode, $purpose, 'WRONGWRONG12');
            $noRowElapsed = hrtime(true) - $start;

            $start = hrtime(true);
            $this->service->consume($userWithLiveCode, $purpose, 'REALCODE1234');
            $successElapsed = hrtime(true) - $start;

            // Coarse on purpose: ~1ms for a bare lookup against ~150ms+ for real
            // bcrypt, so an order of magnitude catches the regression without
            // making this flaky under CI jitter.
            expect($noRowElapsed)->toBeGreaterThan($wrongCodeElapsed * 0.3);

            // And the success path — which already does one real hash
            // check plus a write — must not have picked up a second one.
            expect($successElapsed)->toBeLessThan($wrongCodeElapsed * 2);
        }
    });
});
