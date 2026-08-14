<?php
// tests/Feature/Auth/LoginTest.php

use App\Models\User;

describe('login', function () {

    beforeEach(fn () => $this->seed(Database\Seeders\RoleSeeder::class));

    it('returns a token for valid credentials', function () {
        // Given
        User::factory()->reviewer()->create(['email' => 'maya@example.com']);

        // When
        $response = $this->postJson('/api/login', [
            'email' => 'maya@example.com', 'password' => 'password',
        ]);

        // Then
        $response->assertOk()->assertJsonPath('user.role', 'reviewer');
        expect($response->json('token'))->toBeString()->not->toBeEmpty();
    });

    it('rejects a wrong password with 422 and no user enumeration', function () {
        // Given
        User::factory()->speaker()->create(['email' => 'dana@example.com']);

        // When
        $response = $this->postJson('/api/login', [
            'email' => 'dana@example.com', 'password' => 'wrong-password',
        ]);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('returns the same error for an unknown email', function () {
        // When
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com', 'password' => 'password',
        ]);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('revokes the current token on logout', function () {
        // Given
        $user = User::factory()->speaker()->create();
        $token = $user->createToken('api')->plainTextToken;

        // When
        $response = $this->withToken($token)->postJson('/api/logout');

        // Then
        $response->assertNoContent();
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    it('refuses /me without a token', function () {
        $this->getJson('/api/me')->assertUnauthorized();
    });

    it('returns 401, not a 500 stack trace, for a browser-style unauthenticated request', function () {
        // When — deliberately no Accept: application/json, unlike getJson()
        $response = $this->get('/api/me');

        // Then
        $response->assertStatus(401);
        expect($response->getContent())->not->toContain('vendor/laravel/framework');
    });
});
