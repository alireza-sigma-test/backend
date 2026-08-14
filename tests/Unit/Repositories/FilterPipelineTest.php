<?php
// tests/Unit/Repositories/FilterPipelineTest.php

use App\Data\ProposalFilterData;
use App\Enums\ProposalStatus;
use App\Models\{Proposal, Review, Tag, User};
use App\Repositories\Contracts\ProposalRepository;
use Illuminate\Support\Facades\DB;

describe('proposal repository', function () {

    beforeEach(function () {
        // Role states on User::factory() call assignRole(), which needs the
        // role to already exist — same pattern as every other test file that
        // uses role-state factories.
        $this->seed(Database\Seeders\RoleSeeder::class);

        $this->reviewer = User::factory()->reviewer()->create();
        $this->dana = User::factory()->speaker()->create();
        $this->ilya = User::factory()->speaker()->create();

        $this->tech = Tag::create(['name' => 'Technology']);
        $this->design = Tag::create(['name' => 'Design']);

        // for()'s default relationship guess is 'user' (from User::class), but
        // Proposal's belongsTo is named author() — must be named explicitly.
        $this->observability = Proposal::factory()->for($this->dana, 'author')->create(['title' => 'Observability at scale']);
        $this->observability->tags()->attach($this->tech);

        $this->networks = Proposal::factory()->for($this->ilya, 'author')->approved()->create(['title' => 'Designing for slow networks']);
        $this->networks->tags()->attach($this->design);

        $this->repo = app(ProposalRepository::class);
    });

    it('matches titles case-insensitively', function () {
        // When
        $result = $this->repo->paginate(
            new ProposalFilterData(search: 'OBSERVABILITY'), $this->reviewer
        );

        // Then
        expect($result->pluck('title')->all())->toBe(['Observability at scale']);
    });

    it('applies OR semantics across tags', function () {
        // When
        $result = $this->repo->paginate(
            new ProposalFilterData(tags: ['technology', 'design']), $this->reviewer
        );

        // Then
        expect($result->total())->toBe(2);
    });

    it('filters by status', function () {
        // When
        $result = $this->repo->paginate(
            new ProposalFilterData(status: ProposalStatus::Approved), $this->reviewer
        );

        // Then
        expect($result->pluck('title')->all())->toBe(['Designing for slow networks']);
    });

    it('shows a speaker only their own proposals', function () {
        // When
        $result = $this->repo->paginate(new ProposalFilterData, $this->dana);

        // Then
        expect($result->total())->toBe(1)
            ->and($result->first()->title)->toBe('Observability at scale');
    });

    it('sorts by rating descending, nulls last', function () {
        // Given
        Review::factory()->create(['proposal_id' => $this->networks->id, 'rating' => 5]);

        // When
        $result = $this->repo->paginate(new ProposalFilterData(sort: 'rating'), $this->reviewer);

        // Then
        expect($result->first()->title)->toBe('Designing for slow networks');
    });

    it('keeps counts stable while search and tag filters are applied', function () {
        // When
        $counts = $this->repo->counts($this->reviewer);
        $filtered = $this->repo->paginate(new ProposalFilterData(search: 'nothing matches'), $this->reviewer);

        // Then
        expect($filtered->total())->toBe(0)
            ->and($counts)->toBe(['all' => 2, 'pending' => 1, 'approved' => 1, 'rejected' => 0]);
    });

    it('scopes counts to a speakers own proposals', function () {
        // When
        $counts = $this->repo->counts($this->dana);

        // Then
        expect($counts['all'])->toBe(1);
    });

    it('keeps counts stable while a tag filter is applied', function () {
        // When
        $counts = $this->repo->counts($this->reviewer);
        $filtered = $this->repo->paginate(new ProposalFilterData(tags: ['design']), $this->reviewer);

        // Then — the tag filter narrows the list to one proposal, but the
        // sidebar tallies from counts() must not move.
        expect($filtered->total())->toBe(1)
            ->and($counts)->toBe(['all' => 2, 'pending' => 1, 'approved' => 1, 'rejected' => 0]);
    });

    it('escapes a literal percent sign in the search term instead of treating it as a wildcard', function () {
        // Given — only the first title contains a literal '%'; an unescaped
        // pattern would let '%' match "X uptime" too and return both.
        Proposal::factory()->for($this->dana, 'author')->create(['title' => 'Scaling 100% uptime']);
        Proposal::factory()->for($this->ilya, 'author')->create(['title' => 'Scaling 100X uptime']);

        // When
        $result = $this->repo->paginate(new ProposalFilterData(search: '100%'), $this->reviewer);

        // Then
        expect($result->pluck('title')->all())->toBe(['Scaling 100% uptime']);
    });

    it('escapes a literal underscore in the search term instead of treating it as a single-char wildcard', function () {
        // Given — only the first title contains a literal underscore; an
        // unescaped '_' would match any single character and return both.
        Proposal::factory()->for($this->dana, 'author')->create(['title' => 'Async_processing pipeline']);
        Proposal::factory()->for($this->ilya, 'author')->create(['title' => 'AsyncXprocessing pipeline']);

        // When
        $result = $this->repo->paginate(new ProposalFilterData(search: 'Async_processing'), $this->reviewer);

        // Then
        expect($result->pluck('title')->all())->toBe(['Async_processing pipeline']);
    });

    it('lets a reviewer find any proposal by id', function () {
        // When
        $found = $this->repo->findForViewer($this->observability->id, $this->reviewer);

        // Then
        expect($found->id)->toBe($this->observability->id);
    });

    it('lets a speaker find their own proposal by id', function () {
        // When
        $found = $this->repo->findForViewer($this->observability->id, $this->dana);

        // Then
        expect($found->id)->toBe($this->observability->id);
    });

    it('hides another speakers proposal from findForViewer as if it does not exist', function () {
        // When / Then — dana may not look up ilya's proposal by id.
        expect(fn () => $this->repo->findForViewer($this->networks->id, $this->dana))
            ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('eager-loads author, tags, media and myReview for every paginated row', function () {
        // ProposalResource (Task 10, reused by Task 12) reads author, tags and
        // media unguarded — without eager-loading here, every row becomes a
        // fresh N+1 query the moment a paginated list is rendered.

        // When
        $result = $this->repo->paginate(new ProposalFilterData, $this->reviewer);

        DB::enableQueryLog();
        foreach ($result as $proposal) {
            expect($proposal->relationLoaded('author'))->toBeTrue()
                ->and($proposal->author->relationLoaded('roles'))->toBeTrue()
                ->and($proposal->relationLoaded('tags'))->toBeTrue()
                ->and($proposal->relationLoaded('media'))->toBeTrue()
                ->and($proposal->relationLoaded('myReview'))->toBeTrue();

            // Touching the already-loaded relations must not trigger a query.
            $proposal->author->roles;
            $proposal->tags;
            $proposal->media;
            $proposal->myReview;
        }

        // Then
        expect(DB::getQueryLog())->toBeEmpty();
        DB::disableQueryLog();
    });
});
