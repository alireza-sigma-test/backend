<?php

// UserPolicy's mutating abilities must carry the same `hasVerifiedEmail() &&`
// conjunct every other mutating policy leads with. The `verified` middleware already
// covers both admin writes, so this is defence in depth — which is why these
// exercise the policy directly via can(), independent of the routes.

use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('user policy', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('requires a verified email to create a user, on top of the admin role', function () {
        $verified = User::factory()->admin()->create();
        $unverified = User::factory()->admin()->unverified()->create();

        expect($verified->can('create', User::class))->toBeTrue()
            ->and($unverified->can('create', User::class))->toBeFalse();
    });

    it('requires a verified email to change a role, on top of the admin role and not acting on yourself', function () {
        $verified = User::factory()->admin()->create();
        $unverified = User::factory()->admin()->unverified()->create();
        $target = User::factory()->speaker()->create();

        expect($verified->can('updateRole', $target))->toBeTrue()
            ->and($unverified->can('updateRole', $target))->toBeFalse();
    });

    it('requires a verified email to reinvite, on top of the admin role', function () {
        $verified = User::factory()->admin()->create();
        $unverified = User::factory()->admin()->unverified()->create();
        $target = User::factory()->speaker()->unverified()->create();

        expect($verified->can('reinvite', $target))->toBeTrue()
            ->and($unverified->can('reinvite', $target))->toBeFalse();
    });

    it('refuses create, updateRole and reinvite to non-admins regardless of verification', function () {
        $speaker = User::factory()->speaker()->create();
        $reviewer = User::factory()->reviewer()->create();
        $target = User::factory()->speaker()->unverified()->create();

        foreach ([$speaker, $reviewer] as $nonAdmin) {
            expect($nonAdmin->can('create', User::class))->toBeFalse()
                ->and($nonAdmin->can('updateRole', $target))->toBeFalse()
                ->and($nonAdmin->can('reinvite', $target))->toBeFalse();
        }
    });
});
