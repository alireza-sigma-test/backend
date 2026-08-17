<?php

namespace App\Http\Requests;

use App\Data\ReviewData;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreReviewRequest extends FormRequest
{
    // 404, not 403 — mirrors ProposalController::show's own existence-hiding
    // scope. Must run in authorize(), which fires before the validator (see
    // ValidatesWhenResolvedTrait::validateResolved()) — a controller-body guard
    // never executes for a payload that fails validation first.
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('proposal'));
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,'.config('review.max_rating')],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function toData(): ReviewData
    {
        return new ReviewData(
            rating: $this->integer('rating'),
            comment: $this->filled('comment') ? $this->string('comment')->trim()->value() : null,
        );
    }
}
