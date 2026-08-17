<?php

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
        $user = User::factory()->speaker()->unverified()->create();
        $code = app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);

        $response = $this->actingAs($user)->postJson('/api/email/verify', ['code' => $code]);

        $response->assertOk()->assertJsonPath('is_verified', true);
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('refuses a wrong code and leaves the user unverified', function () {
        $user = User::factory()->speaker()->unverified()->create();
        app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);

        $this->actingAs($user)->postJson('/api/email/verify', ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('is a no-op for someone already verified', function () {
        $user = User::factory()->speaker()->create();
        app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);
        $row = UserCode::where('user_id', $user->id)->where('purpose', CodePurpose::EmailVerification)->sole();

        $this->actingAs($user)->postJson('/api/email/verify', ['code' => '000000'])
            ->assertOk()->assertJsonPath('is_verified', true);

        // The guard short-circuits before the code service is ever
        // touched: no attempt recorded against the outstanding code, and it
        // is not consumed either.
        expect($row->fresh())
            ->attempts->toBe(0)
            ->consumed_at->toBeNull();
    });

    it('resends a code that works and kills the previous one', function () {
        Notification::fake();
        $user = User::factory()->speaker()->unverified()->create();
        $first = app(UserCodeService::class)->issue($user, CodePurpose::EmailVerification);

        $this->actingAs($user)->postJson('/api/email/resend')->assertNoContent();

        Notification::assertSentTo($user, EmailVerificationCode::class);
        $this->actingAs($user)->postJson('/api/email/verify', ['code' => $first])
            ->assertStatus(422);

        // The plaintext code off the captured notification, confirming it verifies the
        // account for real rather than only that a notification fired.
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
        Notification::fake();
        $user = User::factory()->speaker()->create();

        $this->actingAs($user)->postJson('/api/email/resend')->assertNoContent();

        Notification::assertNothingSent();
        expect(UserCode::where('user_id', $user->id)->where('purpose', CodePurpose::EmailVerification)->exists())
            ->toBeFalse();
    });

    it('requires authentication', function () {
        $this->postJson('/api/email/verify', ['code' => '123456'])->assertUnauthorized();
        $this->postJson('/api/email/resend')->assertUnauthorized();
    });
});
