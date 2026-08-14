<?php
// tests/Unit/ModelsTest.php

use App\Enums\ProposalStatus;
use App\Models\{Proposal, Tag, User};

describe('model contracts', function () {

    it('derives two-character initials from a name', function () {
        // Given
        $user = new User(['name' => 'Dana Roth']);

        // Then
        expect($user->initials())->toBe('DR');
    });

    it('derives a display ref from the proposal id', function () {
        // Given
        $proposal = new Proposal;
        $proposal->id = 42;

        // Then
        expect($proposal->ref())->toBe('#PR-1042');
    });

    it('casts status to the ProposalStatus enum', function () {
        // Given
        $proposal = new Proposal(['status' => 'approved']);

        // Then
        expect($proposal->status)->toBe(ProposalStatus::Approved);
    });

    it('slugifies a tag name on save', function () {
        // When
        $tag = Tag::create(['name' => 'Machine Learning']);

        // Then
        expect($tag->slug)->toBe('machine-learning');
    });
});
