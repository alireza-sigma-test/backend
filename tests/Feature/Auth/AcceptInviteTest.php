<?php

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use App\Services\UserCodeService;
use Database\Seeders\RoleSeeder;

describe('accepting an invitation', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('sets the password, verifies the address and signs the user in', function () {
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        $response = $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => $code,
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertCreated()->assertJsonPath('user.is_verified', true);
        expect($response->json('token'))->not->toBeNull();

        // And the new password actually works.
        $this->postJson('/api/login', ['email' => 'nadia@example.com', 'password' => 'a-strong-password'])
            ->assertOk();
    });

    // Invite codes are issued upper-case and Hash::check is case-sensitive, so an
    // unnormalised lowercase retype used to burn one of only five attempts.
    it('accepts an invite code typed in lowercase or with stray whitespace', function () {
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        $response = $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => '  '.strtolower($code).'  ',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertCreated()->assertJsonPath('user.is_verified', true);
    });

    it('does not increment the attempt counter for a code that only differs by case', function () {
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);
        $row = UserCode::where('user_id', $user->id)->where('purpose', CodePurpose::Invite)->sole();

        $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => strtolower($code),
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ])->assertCreated();

        expect($row->fresh())->attempts->toBe(0)->consumed_at->not->toBeNull();
    });

    it('answers identically for a wrong code and an unknown address', function () {
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        $wrongCode = $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => 'WRONGWRONG12',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);
        $unknownEmail = $this->postJson('/api/invites/accept', [
            'email' => 'nobody@example.com', 'code' => 'WRONGWRONG12',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);

        expect($wrongCode->status())->toBe($unknownEmail->status())
            ->and($wrongCode->json())->toBe($unknownEmail->json());
    });

    it('cannot be replayed', function () {
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);
        $body = [
            'email' => 'nadia@example.com', 'code' => $code,
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ];
        $this->postJson('/api/invites/accept', $body)->assertCreated();

        $this->postJson('/api/invites/accept', [...$body, 'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'])
            ->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'nadia@example.com', 'password' => 'attacker-password'])
            ->assertStatus(422);
    });

    it('enforces the password rules', function () {
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => $code,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });
});
