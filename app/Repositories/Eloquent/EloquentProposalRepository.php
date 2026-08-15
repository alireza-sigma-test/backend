<?php

// app/Repositories/Eloquent/EloquentProposalRepository.php

namespace App\Repositories\Eloquent;

use App\Data\ProposalFilterData;
use App\Enums\ProposalStatus;
use App\Enums\UserRole;
use App\Models\Proposal;
use App\Models\User;
use App\Repositories\Contracts\ProposalRepository;
use App\Repositories\Filters\ApplySort;
use App\Repositories\Filters\FilterAuthor;
use App\Repositories\Filters\FilterStatus;
use App\Repositories\Filters\FilterTags;
use App\Repositories\Filters\SearchTitle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pipeline\Pipeline;

final class EloquentProposalRepository implements ProposalRepository
{
    public function paginate(ProposalFilterData $filters, User $viewer): LengthAwarePaginator
    {
        return app(Pipeline::class)
            ->send($this->base($viewer))
            ->through([
                new SearchTitle($filters->search),
                new FilterTags($filters->tags),
                new FilterStatus($filters->status),
                new FilterAuthor($filters->authorId),
                new ApplySort($filters->sort),
            ])
            ->thenReturn()
            ->paginate(max(1, min($filters->perPage, 50)))
            ->withQueryString();
    }

    public function counts(User $viewer): array
    {
        // Deliberately unfiltered by search/tags: the sidebar tallies must stay
        // stable while the user narrows the list.
        $rows = $this->scope(Proposal::query(), $viewer)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'all' => (int) $rows->sum(),
            'pending' => (int) $rows->get(ProposalStatus::Pending->value, 0),
            'approved' => (int) $rows->get(ProposalStatus::Approved->value, 0),
            'rejected' => (int) $rows->get(ProposalStatus::Rejected->value, 0),
        ];
    }

    public function findForViewer(int $id, User $viewer): Proposal
    {
        return $this->base($viewer)
            ->with(['reviews.reviewer.roles', 'statusChanges.changedBy'])
            ->findOrFail($id);
    }

    public function ratingDistribution(Proposal $proposal): array
    {
        $tallied = $proposal->reviews()
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $buckets = [];

        // Zero-fill across the configured scale rather than returning only the
        // ratings that occur — the client renders one bar per point, and a
        // sparse map would make it branch on missing keys.
        //
        // $tallied may hold ratings above the current max_rating (recorded
        // before the scale was lowered); those are simply never read below,
        // so they drop out of the histogram instead of being clamped into the
        // top bucket. See the interface docblock for why.
        foreach (range(1, (int) config('review.max_rating')) as $point) {
            $buckets[$point] = (int) ($tallied[$point] ?? 0);
        }

        return $buckets;
    }

    private function base(User $viewer): Builder
    {
        return $this->scope(Proposal::query(), $viewer)
            ->with([
                'author.roles',
                'tags',
                'media',
                // Constrained by the passed-in viewer, never by auth(). The
                // resource renders this as `my_review`, so a mismatch here
                // attributes another reviewer's rating to the caller.
                'myReview' => fn ($query) => $query
                    ->where('reviews.user_id', $viewer->id)
                    ->with('reviewer'),
            ])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating');
    }

    /** Speakers see only their own proposals; reviewers and admins see all. */
    private function scope(Builder $query, User $viewer): Builder
    {
        if ($viewer->hasRole(UserRole::Speaker->value)) {
            $query->where('user_id', $viewer->id);
        }

        return $query;
    }
}
