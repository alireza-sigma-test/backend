<?php

namespace App\Http\Requests;

use App\Data\ProposalFilterData;
use App\Enums\ProposalStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProposalRequest extends FormRequest
{
    public function rules(): array
    {
        // Skipped for speakers: toData() discards their author_id anyway, so
        // validating it would only be a user-id enumeration oracle.
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
            // No `max:50`: the repository's min($perPage, 50) is the single
            // enforcement point, so an oversized value clamps rather than 422s.
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function toData(): ProposalFilterData
    {
        // A reviewer/admin affordance; speakers are already scoped to their own.
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
