<?php

// Three independent copies of DUMMY_HASH exist — LoginUser, AcceptInvite and
// UserCodeService — each documented as the same value. Rotate one and not the others
// and the timing-equalisation defence silently breaks in whichever path was missed.

use App\Actions\Auth\AcceptInvite;
use App\Actions\Auth\LoginUser;
use App\Services\UserCodeService;

describe('the shared DUMMY_HASH constant', function () {

    it('is byte-identical across all three copies and a valid bcrypt hash at the production cost', function () {
        // Reflection reaches each private constant without changing
        // its visibility just to make it testable.
        $hashes = [
            'LoginUser' => (new ReflectionClass(LoginUser::class))->getConstant('DUMMY_HASH'),
            'AcceptInvite' => (new ReflectionClass(AcceptInvite::class))->getConstant('DUMMY_HASH'),
            'UserCodeService' => (new ReflectionClass(UserCodeService::class))->getConstant('DUMMY_HASH'),
        ];

        expect($hashes['LoginUser'])
            ->toBe($hashes['AcceptInvite'])
            ->toBe($hashes['UserCodeService']);

        foreach ($hashes as $owner => $hash) {
            // Each is a real bcrypt hash at cost 12, the app's default.
            //
            // Hardcoded to 12 rather than read from config: phpunit.xml forces
            // BCRYPT_ROUNDS=4 for speed, so comparing against the live test config
            // would fail for a reason unrelated to the drift this catches — a
            // deployed .env moving real hashes while these three stay at 12.
            $info = password_get_info($hash);

            expect($info['algoName'])->toBe('bcrypt', "DUMMY_HASH in {$owner} is not a bcrypt hash")
                ->and($info['options']['cost'] ?? null)->toBe(12, "DUMMY_HASH in {$owner} is not cost 12");
        }
    });
});
