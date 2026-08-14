<?php
// app/Http/Requests/StoreReviewRequest.php
namespace App\Http\Requests;

use App\Data\ReviewData;
use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
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
