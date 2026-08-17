<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('login', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('returns a token for valid credentials', function () {
        User::factory()->reviewer()->create(['email' => 'maya@example.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'maya@example.com', 'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('user.role', 'reviewer');
        expect($response->json('token'))->toBeString()->not->toBeEmpty();
    });

    it('rejects a wrong password with 422 and no user enumeration', function () {
        User::factory()->speaker()->create(['email' => 'dana@example.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'dana@example.com', 'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('returns the same error for an unknown email as for a wrong password', function () {
        User::factory()->speaker()->create(['email' => 'dana@example.com']);

        // Both branches captured in the same test, not two separate ones,
        // so a divergence between them fails a single assertion instead of two
        // individually-passing tests that never get compared to each other.
        $wrongPassword = $this->postJson('/api/login', [
            'email' => 'dana@example.com', 'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/api/login', [
            'email' => 'nobody@example.com', 'password' => 'password',
        ]);

        // This is the assertion that actually exercises LoginUser::DUMMY_HASH:
        // asserting each response's status/field in isolation would stay green even
        // if the two branches returned different messages.
        $wrongPassword->assertStatus(422)->assertJsonValidationErrors('email');
        $unknownEmail->assertStatus(422)->assertJsonValidationErrors('email');
        expect($wrongPassword->json('errors.email'))->toBe($unknownEmail->json('errors.email'));
    });

    it('revokes the current token on logout', function () {
        $user = User::factory()->speaker()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/logout');

        $response->assertNoContent();
        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    it('refuses /me without a token', function () {
        $this->getJson('/api/me')->assertUnauthorized();
    });

    it('returns 401, not a 500 stack trace, for a browser-style unauthenticated request', function () {
        $response = $this->get('/api/me');

        $response->assertStatus(401);
        expect($response->getContent())->not->toContain('vendor/laravel/framework');
    });
});
