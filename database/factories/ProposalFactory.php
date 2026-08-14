<?php

// database/factories/ProposalFactory.php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->speaker(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraphs(3, true),
            'status' => ProposalStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => ProposalStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ProposalStatus::Rejected]);
    }
}
