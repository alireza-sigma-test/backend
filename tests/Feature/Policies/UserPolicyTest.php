<?php

// tests/Feature/Policies/UserPolicyTest.php

// Final review, finding 5: UserPolicy::create/updateRole checked only the
// admin role, unlike every other mutating policy in the change
// (ProposalPolicy::create/update/delete/review/changeStatus,
// ReviewPolicy::update/delete), which all lead with
// `$user->hasVerifiedEmail() &&`. No HTTP-visible bug — routes/api.php
// already wraps both admin writes in the `verified` group — but it left the
// middleware as the only guard instead of defence in depth, the opposite of
// the reasoning the design gives for putting verification in the policies at
// all. These tests exercise the policy directly via `can()`, independent of
// the route middleware, the same way ProposalPolicyTest does.

use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('user policy', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('requires a verified email to create a user, on top of the admin role', function () {
        // Given
        $verified = User::factory()->admin()->create();
        $unverified = User::factory()->admin()->unverified()->create();

        // Then
        expect($verified->can('create', User::class))->toBeTrue()
            ->and($unverified->can('create', User::class))->toBeFalse();
    });

    it('requires a verified email to change a role, on top of the admin role and not acting on yourself', function () {
        // Given
        $verified = User::factory()->admin()->create();
        $unverified = User::factory()->admin()->unverified()->create();
        $target = User::factory()->speaker()->create();

        // Then
        expect($verified->can('updateRole', $target))->toBeTrue()
            ->and($unverified->can('updateRole', $target))->toBeFalse();
    });

    it('requires a verified email to reinvite, on top of the admin role', function () {
        // Given
        $verified = User::factory()->admin()->create();
        $unverified = User::factory()->admin()->unverified()->create();
        $target = User::factory()->speaker()->unverified()->create();

        // Then
        expect($verified->can('reinvite', $target))->toBeTrue()
            ->and($unverified->can('reinvite', $target))->toBeFalse();
    });

    it('refuses create, updateRole and reinvite to non-admins regardless of verification', function () {
        // Given
        $speaker = User::factory()->speaker()->create();
        $reviewer = User::factory()->reviewer()->create();
        $target = User::factory()->speaker()->unverified()->create();

        // Then
        foreach ([$speaker, $reviewer] as $nonAdmin) {
            expect($nonAdmin->can('create', User::class))->toBeFalse()
                ->and($nonAdmin->can('updateRole', $target))->toBeFalse()
                ->and($nonAdmin->can('reinvite', $target))->toBeFalse();
        }
    });
});
