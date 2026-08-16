<?php

// tests/Feature/Auth/RegistrationTest.php

use App\Models\User;
use App\Notifications\EmailVerificationCode;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

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

    it('refuses to register an administrator', function () {
        // Given / When
        $response = $this->postJson('/api/register', [
            'name' => 'Mallory', 'email' => 'mallory@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        // Then — admins exist only by invitation.
        $response->assertStatus(422)->assertJsonValidationErrors('role');
        expect(User::where('email', 'mallory@example.com')->exists())->toBeFalse();
    });

    it('still allows speaker and reviewer', function () {
        // Given / When / Then
        foreach (['speaker', 'reviewer'] as $role) {
            $this->postJson('/api/register', [
                'name' => 'Sam', 'email' => "sam-{$role}@example.com",
                'password' => 'password', 'password_confirmation' => 'password',
                'role' => $role,
            ])->assertCreated();
        }
    });

    it('creates the account unverified and mails a code', function () {
        // Given
        Notification::fake();

        // When
        $response = $this->postJson('/api/register', [
            'name' => 'Dana', 'email' => 'newdana@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'role' => 'speaker',
        ]);

        // Then — a token is still issued: an unverified user may sign in and read.
        $response->assertCreated()->assertJsonPath('user.is_verified', false);
        expect($response->json('token'))->not->toBeNull();

        $user = User::where('email', 'newdana@example.com')->sole();
        Notification::assertSentTo($user, EmailVerificationCode::class);
    });
});
