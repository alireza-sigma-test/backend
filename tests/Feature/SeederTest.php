<?php
// tests/Feature/SeederTest.php

use App\Enums\ProposalStatus;
use App\Models\{Proposal, Tag, User};

describe('demo seed data', function () {

    it('matches the tallies shown in the design mockups', function () {
        // When
        $this->seed();

        // Then
        expect(Proposal::count())->toBe(6)
            ->and(Proposal::where('status', ProposalStatus::Pending)->count())->toBe(3)
            ->and(Proposal::where('status', ProposalStatus::Approved)->count())->toBe(2)
            ->and(Proposal::where('status', ProposalStatus::Rejected)->count())->toBe(1)
            ->and(Tag::count())->toBe(6)
            ->and(User::count())->toBe(8);
    });

    it('gives every seeded user exactly one role', function () {
        // When
        $this->seed();

        // Then
        User::with('roles')->get()->each(
            fn (User $u) => expect($u->getRoleNames())->toHaveCount(1)
        );
    });

    it('seeds a proposal with three reviews averaging 4.0', function () {
        // When
        $this->seed();

        // Then
        $proposal = Proposal::where('title', 'Observability at scale without the bill')->firstOrFail();

        expect($proposal->reviews()->count())->toBe(3)
            ->and(round($proposal->reviews()->avg('rating'), 1))->toBe(4.0);
    });
});
