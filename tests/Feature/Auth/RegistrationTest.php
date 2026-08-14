<?php

// tests/Feature/Auth/RegistrationTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('registration', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('creates a speaker and returns a bearer token', function () {
        // When
        $response = $this->postJson('/api/register', [
            'name' => 'Dana Roth',
            'email' => 'dana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'speaker',
        ]);

        // Then
        $response->assertCreated()
            ->assertJsonPath('user.email', 'dana@example.com')
            ->assertJsonPath('user.role', 'speaker')
            ->assertJsonPath('user.initials', 'DR')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'initials']]);

        expect(User::whereEmail('dana@example.com')->firstOrFail()->hasRole('speaker'))->toBeTrue();
    });

    it('rejects a role outside the three known values', function () {
        // When
        $response = $this->postJson('/api/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'superadmin',
        ]);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('role');
    });

    it('rejects a duplicate email', function () {
        // Given
        User::factory()->speaker()->create(['email' => 'dana@example.com']);

        // When
        $response = $this->postJson('/api/register', [
            'name' => 'Someone Else',
            'email' => 'dana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'speaker',
        ]);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    });
});
