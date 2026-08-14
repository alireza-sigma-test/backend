<?php
// database/factories/ReviewFactory.php
namespace Database\Factories;

use App\Models\{Proposal, Review, User};
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'user_id' => User::factory()->reviewer(),
            'rating' => fake()->numberBetween(1, config('review.max_rating')),
            'comment' => fake()->paragraph(),
        ];
    }
}
