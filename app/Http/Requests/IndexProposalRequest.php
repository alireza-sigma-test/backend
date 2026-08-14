<?php
// app/Http/Requests/IndexProposalRequest.php
namespace App\Http\Requests;

use App\Data\ProposalFilterData;
use App\Enums\{ProposalStatus, UserRole};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProposalRequest extends FormRequest
{
    public function rules(): array
    {
        // exists:users,id is skipped for speakers: their author_id is discarded
        // in toData() below, so validating it against the users table would only
        // serve as a user-id enumeration oracle for a value that is never used.
        $authorId = ['sometimes', 'nullable', 'integer'];
        if (! $this->user()->hasRole(UserRole::Speaker->value)) {
            $authorId[] = 'exists:users,id';
        }

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tags' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(ProposalStatus::values())],
            'author_id' => $authorId,
            'sort' => ['sometimes', Rule::in(['newest', 'oldest', 'rating'])],
            // Deliberately no `max:50` here: the repository's
            // max(1, min($perPage, 50)) is the single enforcement point, so
            // per_page=500 clamps to 200 with meta.per_page=50 rather than 422.
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function toData(): ProposalFilterData
    {
        // author_id is a reviewer/admin affordance; a speaker is already
        // scoped to their own proposals, so it is ignored for them.
        $authorId = $this->user()->hasRole(UserRole::Speaker->value)
            ? null
            : ($this->integer('author_id') ?: null);

        return new ProposalFilterData(
            search: $this->string('search')->trim()->value() ?: null,
            tags: array_values(array_filter(
                array_map('trim', explode(',', (string) $this->input('tags', '')))
            )),
            status: $this->filled('status') ? ProposalStatus::from($this->string('status')->value()) : null,
            authorId: $authorId,
            sort: $this->string('sort', 'newest')->value(),
            perPage: (int) $this->integer('per_page', 15),
        );
    }
}
