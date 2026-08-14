<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['proposal_id', 'from', 'to', 'note', 'changed_by'];

    protected function casts(): array
    {
        return ['from' => ProposalStatus::class, 'to' => ProposalStatus::class];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
