<?php

// tests/Unit/DummyHashConsistencyTest.php

// Final review, item 8 (recommended, cheap, closes a named risk): three
// independent copies of DUMMY_HASH exist — LoginUser, AcceptInvite and
// UserCodeService — each documented as "the same value and same rationale"
// as the others, but nothing pinned them actually identical. Rotate one and
// not the others and the timing-equalisation defence silently breaks in
// whichever path was missed, with no test failure to catch it.

use App\Actions\Auth\AcceptInvite;
use App\Actions\Auth\LoginUser;
use App\Services\UserCodeService;

describe('the shared DUMMY_HASH constant', function () {

    it('is byte-identical across all three copies and a valid bcrypt hash at the production cost', function () {
        // Given — reflection reaches each private constant without changing
        // its visibility just to make it testable.
        $hashes = [
            'LoginUser' => (new ReflectionClass(LoginUser::class))->getConstant('DUMMY_HASH'),
            'AcceptInvite' => (new ReflectionClass(AcceptInvite::class))->getConstant('DUMMY_HASH'),
            'UserCodeService' => (new ReflectionClass(UserCodeService::class))->getConstant('DUMMY_HASH'),
        ];

        // Then — all three are exactly the same string...
        expect($hashes['LoginUser'])
            ->toBe($hashes['AcceptInvite'])
            ->toBe($hashes['UserCodeService']);

        foreach ($hashes as $owner => $hash) {
            // ...and each one is a real bcrypt hash, not a lookalike string,
            // at cost 12 — the app's actual production/dev default
            // (config/hashing.php's `env('BCRYPT_ROUNDS', 12)`).
            //
            // Deliberately hardcoded to 12 rather than compared against
            // config('hashing.bcrypt.rounds'): phpunit.xml forces
            // BCRYPT_ROUNDS=4 in this very test run for speed (the same
            // reason UserCodeServiceTest's "burns comparable time" test
            // hashes at an explicit ['rounds' => 12] rather than the
            // ambient config). Comparing against the live test config would
            // make this test fail today for a reason that has nothing to do
            // with the drift it exists to catch — the real risk the review
            // named is a deployed .env setting BCRYPT_ROUNDS, which would
            // move real hashes while these three dummies silently stayed at
            // cost 12.
            $info = password_get_info($hash);

            expect($info['algoName'])->toBe('bcrypt', "DUMMY_HASH in {$owner} is not a bcrypt hash")
                ->and($info['options']['cost'] ?? null)->toBe(12, "DUMMY_HASH in {$owner} is not cost 12");
        }
    });
});
