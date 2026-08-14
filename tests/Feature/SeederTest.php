<?php

// tests/Feature/SeederTest.php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
            ->and(User::count())->toBe(8)
            // 3 on the flagship proposal + 2 on the other approved one.
            ->and(Review::count())->toBe(5)
            ->and(Media::count())->toBe(1)
            ->and(ProposalStatusChange::count())->toBe(2);
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

    it('attaches a PDF to the flagship proposal, so the attachment feature is visible', function () {
        // When
        $this->seed();

        // Then
        $proposal = Proposal::where('title', 'Observability at scale without the bill')->firstOrFail();

        expect($proposal->attachment())->not->toBeNull()
            ->and($proposal->attachment()->mime_type)->toBe('application/pdf');
    });

    it('backs the approved decision on a second proposal with reviews and an audit row', function () {
        // When
        $this->seed();

        // Then — REVIEW_MIN_REVIEWS_TO_DECIDE=2, so this proposal's decision
        // is now data the app's own workflow could actually have produced.
        $proposal = Proposal::where('title', 'Designing for slow networks')->firstOrFail();

        expect($proposal->reviews()->count())->toBe(2);

        $change = ProposalStatusChange::where('proposal_id', $proposal->id)->sole();

        expect($change->from)->toBe(ProposalStatus::Pending)
            ->and($change->to)->toBe(ProposalStatus::Approved)
            ->and($change->changed_by)->toBe(User::where('email', 'alex@example.com')->value('id'));
    });

    it('writes an audit row explaining the rejected proposal, with a note', function () {
        // When
        $this->seed();

        // Then
        $proposal = Proposal::where('title', 'Why we left microservices')->firstOrFail();
        $change = ProposalStatusChange::where('proposal_id', $proposal->id)->sole();

        expect($change->from)->toBe(ProposalStatus::Pending)
            ->and($change->to)->toBe(ProposalStatus::Rejected)
            ->and($change->note)->not->toBeNull();
    });
});
