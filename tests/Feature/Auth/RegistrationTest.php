<?php

use App\Models\User;
use App\Notifications\EmailVerificationCode;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

describe('registration', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('creates a speaker and returns a bearer token', function () {
        $response = $this->postJson('/api/register', [
            'name' => 'Dana Roth',
            'email' => 'dana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'speaker',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'dana@example.com')
            ->assertJsonPath('user.role', 'speaker')
            ->assertJsonPath('user.initials', 'DR')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'initials']]);

        expect(User::whereEmail('dana@example.com')->firstOrFail()->hasRole('speaker'))->toBeTrue();
    });

    it('rejects a role outside the three known values', function () {
        $response = $this->postJson('/api/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'superadmin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
    });

    it('rejects a duplicate email', function () {
        User::factory()->speaker()->create(['email' => 'dana@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Someone Else',
            'email' => 'dana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'speaker',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('refuses to register an administrator', function () {
        $response = $this->postJson('/api/register', [
            'name' => 'Mallory', 'email' => 'mallory@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        expect(User::where('email', 'mallory@example.com')->exists())->toBeFalse();
    });

    it('still allows speaker and reviewer', function () {
        foreach (['speaker', 'reviewer'] as $role) {
            $this->postJson('/api/register', [
                'name' => 'Sam', 'email' => "sam-{$role}@example.com",
                'password' => 'password', 'password_confirmation' => 'password',
                'role' => $role,
            ])->assertCreated();
        }
    });

    it('creates the account unverified and mails a code', function () {
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Dana', 'email' => 'newdana@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'role' => 'speaker',
        ]);

        $response->assertCreated()->assertJsonPath('user.is_verified', false);
        expect($response->json('token'))->not->toBeNull();

        $user = User::where('email', 'newdana@example.com')->sole();
        Notification::assertSentTo($user, EmailVerificationCode::class);
    });
});
