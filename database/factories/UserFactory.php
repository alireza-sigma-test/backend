<?php
// database/factories/UserFactory.php
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
}
