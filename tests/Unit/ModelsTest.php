<?php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\Tag;
use App\Models\User;

describe('model contracts', function () {

    it('derives two-character initials from a name', function () {
        $user = new User(['name' => 'Dana Roth']);

        expect($user->initials())->toBe('DR');
    });

    it('derives a display ref from the proposal id', function () {
        $proposal = new Proposal;
        $proposal->id = 42;

        expect($proposal->ref())->toBe('#PR-1042');
    });

    it('casts status to the ProposalStatus enum', function () {
        $proposal = new Proposal(['status' => 'approved']);

        expect($proposal->status)->toBe(ProposalStatus::Approved);
    });

    it('slugifies a tag name on save', function () {
        $tag = Tag::create(['name' => 'Machine Learning']);

        expect($tag->slug)->toBe('machine-learning');
    });
});
