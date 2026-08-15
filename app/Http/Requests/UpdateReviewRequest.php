<?php

// app/Http/Requests/UpdateReviewRequest.php

namespace App\Http\Requests;

use App\Data\UpdateReviewData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'required', 'integer', 'between:1,'.config('review.max_rating')],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function toData(): UpdateReviewData
    {
        return new UpdateReviewData(
            rating: $this->has('rating') ? $this->integer('rating') : null,
            comment: $this->has('comment') ? $this->string('comment')->trim()->value() : null,
        );
    }
}
