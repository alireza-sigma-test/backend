<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Proposal extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

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
