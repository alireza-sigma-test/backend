<?php

// tests/Feature/SeederTest.php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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

    it('gives every seeded user the documented demo password', function () {
        // When
        $this->seed();

        // Then — this is the credential the README hands a reviewer; if a factory
        // edit silently changes it, every demo login breaks with a green suite.
        User::all()->each(
            fn (User $u) => expect(Hash::check('password', $u->password))->toBeTrue()
        );
    });

    it('is safe to run twice, as `make up` does', function () {
        // Given
        $this->seed();

        // When / Then — no duplicate-key exception on the unique users.email index
        $this->seed();

        expect(User::count())->toBe(8)
            ->and(Proposal::count())->toBe(6);
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
