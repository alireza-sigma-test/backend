<?php

// app/Data/StatusChangeData.php

namespace App\Data;

use App\Enums\ProposalStatus;

final readonly class StatusChangeData
{
    public function __construct(
        public ProposalStatus $status,
        public ?string $note = null,
    ) {}
}
