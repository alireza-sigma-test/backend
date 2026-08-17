<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use App\Enums\SummaryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Proposal extends Model implements HasMedia
{
    /**
     * SoftDeletes is not for restoring — deletion is one-way. It exists because
     * reviews, proposal_tag and proposal_status_changes all cascadeOnDelete, so a
     * hard DELETE would take every reviewer's work and the audit trail with it.
     * Deleting a `users` row still cascades past this.
     */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const ATTACHMENT_COLLECTION = 'attachment';

    // The summary_* columns are deliberately absent: written only by
    // GenerateProposalSummary, never mass-assigned from a request.
    protected $fillable = ['user_id', 'title', 'description', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'summary_status' => SummaryStatus::class,
            'summary_generated_at' => 'datetime',
        ];
    }

    /** Display id. Derived, not stored — a second sequence buys nothing. */
    public function ref(): string
    {
        return '#PR-'.(1000 + (int) $this->id);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(ProposalStatusChange::class);
    }

    /**
     * Deliberately unconstrained, so callers MUST constrain it to the viewer at
     * eager-load time (EloquentProposalRepository does). Never lazy-load it: it
     * would return an arbitrary reviewer's review.
     */
    public function myReview(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENT_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf']);
    }

    public function attachment(): ?Media
    {
        return $this->getFirstMedia(self::ATTACHMENT_COLLECTION);
    }
}
