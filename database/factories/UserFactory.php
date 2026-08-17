<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            // Verified by default: almost every test needs a user who can act, and
            // making the exception explicit keeps those tests readable.
            'email_verified_at' => now(),
        ];
    }

    public function speaker(): static
    {
        return $this->afterCreating(fn (User $u) => $u->assignRole(UserRole::Speaker->value));
    }

    public function reviewer(): static
    {
        return $this->afterCreating(fn (User $u) => $u->assignRole(UserRole::Reviewer->value));
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $u) => $u->assignRole(UserRole::Admin->value));
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
