<?php

// tests/Feature/Policies/ProposalPolicyTest.php

use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('proposal policy', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lets the owning speaker edit only while pending', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $pending = Proposal::factory()->for($dana, 'author')->create();
        $decided = Proposal::factory()->for($dana, 'author')->approved()->create();

        // Then
        expect($dana->can('update', $pending))->toBeTrue()
            ->and($dana->can('update', $decided))->toBeFalse();
    });

    it('refuses edits from a speaker who does not own the proposal', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $ilya = User::factory()->speaker()->create();
        $proposal = Proposal::factory()->for($ilya, 'author')->create();

        // Then
        expect($dana->can('update', $proposal))->toBeFalse();
    });

    it('hides other speakers proposals from a speaker', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $mine = Proposal::factory()->for($dana, 'author')->create();
        $theirs = Proposal::factory()->create();

        // Then
        expect($dana->can('view', $mine))->toBeTrue()
            ->and($dana->can('view', $theirs))->toBeFalse();
    });

    it('allows reviewers to review but never to change status', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create();

        // Then
        expect($maya->can('review', $proposal))->toBeTrue()
            ->and($maya->can('changeStatus', $proposal))->toBeFalse();
    });

    it('allows admins to change status but never to review', function () {
        // Given
        $alex = User::factory()->admin()->create();
        $proposal = Proposal::factory()->create();

        // Then
        expect($alex->can('changeStatus', $proposal))->toBeTrue()
            ->and($alex->can('review', $proposal))->toBeFalse();
    });

    it('refuses a reviewer reviewing their own proposal', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $own = Proposal::factory()->for($maya, 'author')->create();

        // Then
        expect($maya->can('review', $own))->toBeFalse();
    });

    it('refuses a review on a proposal that already has a decision', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $decided = Proposal::factory()->rejected()->create();

        // Then
        expect($maya->can('review', $decided))->toBeFalse();
    });

    // `delete` is the only two-branch OR in the policy (admin at any time, OR
    // owner while pending). A wrong `true` here is a silent permission hole,
    // so every combination gets an assertion.
    it('lets an admin delete any proposal regardless of status', function () {
        // Given
        $alex = User::factory()->admin()->create();

        // Then
        expect($alex->can('delete', Proposal::factory()->create()))->toBeTrue()
            ->and($alex->can('delete', Proposal::factory()->approved()->create()))->toBeTrue()
            ->and($alex->can('delete', Proposal::factory()->rejected()->create()))->toBeTrue();
    });

    it('lets the owning speaker delete only while pending', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $pending = Proposal::factory()->for($dana, 'author')->create();
        $approved = Proposal::factory()->for($dana, 'author')->approved()->create();

        // Then
        expect($dana->can('delete', $pending))->toBeTrue()
            ->and($dana->can('delete', $approved))->toBeFalse();
    });

    it('refuses deletion by a non-owning speaker or any reviewer', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();
        $theirs = Proposal::factory()->create();

        // Then
        expect($dana->can('delete', $theirs))->toBeFalse()
            ->and($maya->can('delete', $theirs))->toBeFalse();
    });

    // The `isStaff()` half of view(): a typo in the role name would pass CI
    // unnoticed without these.
    it('lets reviewers and admins view any proposal', function () {
        // Given
        $maya = User::factory()->reviewer()->create();
        $alex = User::factory()->admin()->create();
        $theirs = Proposal::factory()->create();

        // Then
        expect($maya->can('view', $theirs))->toBeTrue()
            ->and($alex->can('view', $theirs))->toBeTrue();
    });

    it('allows only speakers to create proposals', function () {
        // Given
        $roles = [
            'speaker' => User::factory()->speaker()->create(),
            'reviewer' => User::factory()->reviewer()->create(),
            'admin' => User::factory()->admin()->create(),
        ];

        // Then
        expect($roles['speaker']->can('create', Proposal::class))->toBeTrue()
            ->and($roles['reviewer']->can('create', Proposal::class))->toBeFalse()
            ->and($roles['admin']->can('create', Proposal::class))->toBeFalse();
    });

    it('allows only admins to view the status history', function () {
        // Given
        $proposal = Proposal::factory()->create();

        // Then
        expect(User::factory()->admin()->create()->can('viewHistory', $proposal))->toBeTrue()
            ->and(User::factory()->reviewer()->create()->can('viewHistory', $proposal))->toBeFalse()
            ->and(User::factory()->speaker()->create()->can('viewHistory', $proposal))->toBeFalse();
    });

    it('allows every role to list proposals, narrowing happens in the repository', function () {
        // Then
        expect(User::factory()->speaker()->create()->can('viewAny', Proposal::class))->toBeTrue()
            ->and(User::factory()->reviewer()->create()->can('viewAny', Proposal::class))->toBeTrue()
            ->and(User::factory()->admin()->create()->can('viewAny', Proposal::class))->toBeTrue();
    });
});
