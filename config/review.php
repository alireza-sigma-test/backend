<?php
// config/review.php

return [
    /*
     | The maximum rating a reviewer may give. The API surfaces this as `max_rating`
     | so the client's RatingInput reads it rather than hard-coding a scale.
     */
    'max_rating' => (int) env('REVIEW_MAX_RATING', 5),

    /*
     | How many reviews a pending proposal needs before it counts toward the
     | admin dashboard's `ready_to_decide` tally.
     */
    'min_reviews_to_decide' => (int) env('REVIEW_MIN_REVIEWS_TO_DECIDE', 2),
];
