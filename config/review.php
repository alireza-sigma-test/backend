<?php

return [
    // Surfaced by the API as `max_rating` so the client never hard-codes the scale.
    'max_rating' => (int) env('REVIEW_MAX_RATING', 5),

    // Reviews a pending proposal needs to count toward `ready_to_decide`.
    'min_reviews_to_decide' => (int) env('REVIEW_MIN_REVIEWS_TO_DECIDE', 2),
];
