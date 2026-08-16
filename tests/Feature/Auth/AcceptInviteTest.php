<?php

// tests/Feature/Auth/AcceptInviteTest.php

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use App\Services\UserCodeService;
use Database\Seeders\RoleSeeder;

describe('accepting an invitation', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('sets the password, verifies the address and signs the user in', function () {
        // Given
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        // When
        $response = $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => $code,
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);

        // Then
        $response->assertCreated()->assertJsonPath('user.is_verified', true);
        expect($response->json('token'))->not->toBeNull();

        // And the new password actually works.
        $this->postJson('/api/login', ['email' => 'nadia@example.com', 'password' => 'a-strong-password'])
            ->assertOk();
    });

    // Final review, finding 3: UserCodeService::issue() upper-cases every
    // invite code, but AcceptInviteRequest used to pass the submitted code
    // through unchanged, and Hash::check is case-sensitive. A code retyped
    // in lowercase — rather than pasted — failed and burned one of only
    // five attempts, directly feeding finding 2's permanent lockout.
    it('accepts an invite code typed in lowercase or with stray whitespace', function () {
        // Given
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        // When
        $response = $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => '  '.strtolower($code).'  ',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);

        // Then
        $response->assertCreated()->assertJsonPath('user.is_verified', true);
    });

    it('does not increment the attempt counter for a code that only differs by case', function () {
        // Given
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);
        $row = UserCode::where('user_id', $user->id)->where('purpose', CodePurpose::Invite)->sole();

        // When — submitted lowercase, previously indistinguishable from a wrong code.
        $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => strtolower($code),
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ])->assertCreated();

        // Then — consumed as a match, not counted as one of five failed attempts.
        expect($row->fresh())->attempts->toBe(0)->consumed_at->not->toBeNull();
    });

    it('answers identically for a wrong code and an unknown address', function () {
        // Given — the enumeration oracle T2 closed twice must not reopen here.
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        // When
        $wrongCode = $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => 'WRONGWRONG12',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);
        $unknownEmail = $this->postJson('/api/invites/accept', [
            'email' => 'nobody@example.com', 'code' => 'WRONGWRONG12',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ]);

        // Then — same status AND same body, or the difference is the oracle.
        expect($wrongCode->status())->toBe($unknownEmail->status())
            ->and($wrongCode->json())->toBe($unknownEmail->json());
    });

    it('cannot be replayed', function () {
        // Given
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);
        $body = [
            'email' => 'nadia@example.com', 'code' => $code,
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ];
        $this->postJson('/api/invites/accept', $body)->assertCreated();

        // When / Then — a consumed code must not set a second password.
        $this->postJson('/api/invites/accept', [...$body, 'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'])
            ->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'nadia@example.com', 'password' => 'attacker-password'])
            ->assertStatus(422);
    });

    it('enforces the password rules', function () {
        // Given
        $user = User::factory()->admin()->unverified()->create(['email' => 'nadia@example.com']);
        $code = app(UserCodeService::class)->issue($user, CodePurpose::Invite);

        // When / Then
        $this->postJson('/api/invites/accept', [
            'email' => 'nadia@example.com', 'code' => $code,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });
});
