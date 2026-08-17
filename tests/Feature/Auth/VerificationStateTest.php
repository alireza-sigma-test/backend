<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;

describe('verification state', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('exposes verification on the user resource', function () {
        $user = User::factory()->speaker()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk()->assertJsonPath('is_verified', true);
        expect($response->json('email_verified_at'))->not->toBeNull();
    });

    it('reports an unverified user honestly', function () {
        $user = User::factory()->speaker()->unverified()->create();

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('is_verified', false)
            ->assertJsonPath('email_verified_at', null);
    });

    it('seeds every demo user verified', function () {
        // The demo must not be read-only once policies require
        // verification. This is the guard on that.
        $this->seed(DemoSeeder::class);

        expect(User::whereNull('email_verified_at')->count())->toBe(0);
    });
});
