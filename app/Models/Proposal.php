<?php

namespace App\Models;

use App\Enums\ProposalStatus;
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
     * Soft-deleted, and not so a proposal can be restored — deletion is one-way
     * by decision. `reviews`, `proposal_tag` and `proposal_status_changes` all
     * declare cascadeOnDelete, so a hard DELETE would destroy every reviewer's
     * rating and comment and the whole audit trail as a side effect of one
     * speaker withdrawing a talk. Soft-deleting keeps that work intact.
     */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const ATTACHMENT_COLLECTION = 'attachment';

    protected $fillable = ['user_id', 'title', 'description', 'status'];

    protected function casts(): array
    {
        return ['status' => ProposalStatus::class];
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
     * Deliberately UNCONSTRAINED. The viewer is a parameter everywhere else in the
     * read path, and resolving it here from global auth() state diverges the moment
     * the caller's $viewer is not the authenticated user — a queued job, a console
     * command, or a "view as" feature would attach the wrong reviewer's review.
     *
     * Callers MUST constrain it at eager-load time; EloquentProposalRepository does.
     * Never lazy-load this relation — unconstrained, it returns an arbitrary review.
     * ProposalResource only reads it behind relationLoaded(), so it cannot.
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
