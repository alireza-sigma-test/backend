<?php
// tests/Feature/Policies/ProposalPolicyTest.php

use App\Enums\ProposalStatus;
use App\Models\{Proposal, User};

describe('proposal policy', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

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
});
