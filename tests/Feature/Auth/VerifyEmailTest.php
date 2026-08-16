<?php

// tests/Feature/Auth/VerifyEmailTest.php

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use App\Notifications\EmailVerificationCode;
use App\Services\UserCodeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

describe('email verification', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('verifies with the right code', function () {
        // Given
        $user = User::factory()->speaker()->unverified()->create();
        $code = app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);

        // When
        $response = $this->actingAs($user)->postJson('/api/email/verify', ['code' => $code]);

        // Then
        $response->assertOk()->assertJsonPath('is_verified', true);
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('refuses a wrong code and leaves the user unverified', function () {
        // Given
        $user = User::factory()->speaker()->unverified()->create();
        app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);

        // When / Then
        $this->actingAs($user)->postJson('/api/email/verify', ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('is a no-op for someone already verified', function () {
        // Given — a client retrying after a dropped response must not be punished.
        $user = User::factory()->speaker()->create();
        app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);
        $row = UserCode::where('user_id', $user->id)->where('purpose', CodePurpose::EmailVerification)->sole();

        // When
        $this->actingAs($user)->postJson('/api/email/verify', ['code' => '000000'])
            ->assertOk()->assertJsonPath('is_verified', true);

        // Then — the guard short-circuits before the code service is ever
        // touched: no attempt recorded against the outstanding code, and it
        // is not consumed either.
        expect($row->fresh())
            ->attempts->toBe(0)
            ->consumed_at->toBeNull();
    });

    it('resends a code that works and kills the previous one', function () {
        // Given
        Notification::fake();
        $user = User::factory()->speaker()->unverified()->create();
        $first = app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);

        // When
        $this->actingAs($user)->postJson('/api/email/resend')->assertNoContent();

        // Then — the old code is dead...
        Notification::assertSentTo($user, EmailVerificationCode::class);
        $this->actingAs($user)->postJson('/api/email/verify', ['code' => $first])
            ->assertStatus(422);

        // ...and the "works" half of this test's name, previously unasserted:
        // pull the plaintext code out of the notification that was actually
        // mailed (Notification::fake() captures the instance before it would
        // have been hashed) and confirm it verifies the account for real,
        // not just that some notification fired.
        $second = null;
        Notification::assertSentTo(
            $user,
            EmailVerificationCode::class,
            function (EmailVerificationCode $notification) use (&$second): bool {
                $second = (fn () => $this->code)->call($notification);

                return true;
            }
        );

        $this->actingAs($user)->postJson('/api/email/verify', ['code' => $second])
            ->assertOk()->assertJsonPath('is_verified', true);
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('sends nothing and mints no code for someone already verified', function () {
        // Given
        Notification::fake();
        $user = User::factory()->speaker()->create();

        // When
        $this->actingAs($user)->postJson('/api/email/resend')->assertNoContent();

        // Then
        Notification::assertNothingSent();
        expect(UserCode::where('user_id', $user->id)->where('purpose', CodePurpose::EmailVerification)->exists())
            ->toBeFalse();
    });

    it('requires authentication', function () {
        // When / Then
        $this->postJson('/api/email/verify', ['code' => '123456'])->assertUnauthorized();
        $this->postJson('/api/email/resend')->assertUnauthorized();
    });
});
