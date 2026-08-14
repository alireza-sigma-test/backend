<?php
// app/Repositories/Contracts/ProposalRepository.php
namespace App\Repositories\Contracts;

use App\Data\ProposalFilterData;
use App\Models\{Proposal, User};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read surface for Proposal. Queries only — writes go through Actions using
 * Eloquent directly, which keeps this interface honest.
 */
interface ProposalRepository
{
    public function paginate(ProposalFilterData $filters, User $viewer): LengthAwarePaginator;

    /** @return array{all:int, pending:int, approved:int, rejected:int} */
    public function counts(User $viewer): array;

    public function findForViewer(int $id, User $viewer): Proposal;
}
