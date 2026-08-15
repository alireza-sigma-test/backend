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
        // has(), not filled() — filled() would fold an explicit null/"" back
        // into "absent" and the comment would never actually clear. has()
        // only tells us the key was sent; filled() on the raw value still
        // decides whether that send counts as content or a clear.
        $commentProvided = $this->has('comment');
        $rawComment = $this->input('comment');

        return new UpdateReviewData(
            rating: $this->has('rating') ? $this->integer('rating') : null,
            comment: $commentProvided && filled($rawComment) ? trim((string) $rawComment) : null,
            commentProvided: $commentProvided,
        );
    }
}
