<?php

// tests/Feature/Admin/ReinviteUserTest.php

// Final review, finding 2: an admin-created account's only credential is the
// 48-hour, 5-attempt invite code. Once it lapsed — expired, or attempt-capped
// — nothing could ever revive it: accept 422'd, login failed against the
// random unusable password CreateUserByAdmin sets, re-inviting 422'd on
// `unique:users,email`, self-registration failed the same way, there was no
// re-invite endpoint, and `/email/resend` sits behind `auth:sanctum`, which a
// user with no working credential can never reach. The account was dead and
// the address was burnt forever, violating the invariant this whole change
// set out to hold: a user must always have a route out of unverified.
//
// `POST /api/admin/users/{user}/reinvite` is the fix. It must reissue for an
// account genuinely stuck in that state, and refuse — without touching
// anything — for any account where reissuing would silently replace a real
// password: one already claimed (the password-reset backdoor the review
// named explicitly), and one that was never invited through this flow at all
// (a self-registered user who simply hasn't verified yet, and who has a real
// password of their own the admin has no business overwriting).

use App\Enums\CodePurpose;
use App\Models\User;
use App\Models\UserCode;
use App\Notifications\AccountInvitation;
use App\Services\UserCodeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

describe('reinviting a user', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('reissues an invite for an account whose original code expired, and the new code actually works', function () {
        // Given — an admin-created account, its only credential now expired.
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        app(UserCodeService::class)->issue($target, CodePurpose::Invite);
        $this->travel(CodePurpose::Invite->ttlMinutes() + 1)->minutes();

        // When
        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        // Then
        $response->assertOk()->assertJsonPath('is_verified', false);
        Notification::assertSentTo($target, AccountInvitation::class);

        $code = null;
        Notification::assertSentTo($target, AccountInvitation::class, function (AccountInvitation $n) use (&$code): bool {
            $code = (fn () => $this->code)->call($n);

            return true;
        });

        $accepted = $this->postJson('/api/invites/accept', [
            'email' => $target->email, 'code' => $code,
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ]);
        $accepted->assertCreated()->assertJsonPath('user.is_verified', true);
    });

    it('reissues an invite for an account whose code burned all five attempts', function () {
        // Given — the other trigger finding 2 named: the attempt cap, not
        // just expiry, and nothing ever called issue() again for an invite.
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        $service = app(UserCodeService::class);
        $service->issue($target, CodePurpose::Invite);
        foreach (range(1, UserCodeService::MAX_ATTEMPTS) as $ignored) {
            $service->consume($target, CodePurpose::Invite, 'WRONGWRONG12');
        }

        // When
        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        // Then
        $response->assertOk();
        Notification::assertSentTo($target, AccountInvitation::class);
    });

    it('replaces the previous unconsumed code rather than leaving two live', function () {
        // Given
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        $first = app(UserCodeService::class)->issue($target, CodePurpose::Invite);

        // When
        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite")->assertOk();

        // Then — the old code no longer verifies, even though it hasn't expired.
        $stillWorks = app(UserCodeService::class)->consume($target, CodePurpose::Invite, $first);
        expect($stillWorks)->toBeFalse();
    });

    it('refuses to reinvite a user who already accepted, so this cannot become a password-reset backdoor', function () {
        // Given — a fully claimed account: real password, verified email.
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->create(['password' => Hash::make('their-real-password')]);
        $originalHash = $target->password;

        // When
        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        // Then
        $response->assertStatus(422)->assertJsonPath('code', 'not_reinvitable');
        Notification::assertNotSentTo($target, AccountInvitation::class);
        expect($target->fresh()->password)->toBe($originalHash);
    });

    it('refuses to reinvite a self-registered user who has not verified yet, so their real password is never overwritten', function () {
        // Given — never touched by CreateUserByAdmin or issued an invite code
        // at all; the only thing distinguishing them from an invited account
        // is that no invite UserCode row exists for them.
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        $originalHash = $target->password;
        expect(UserCode::where('user_id', $target->id)->where('purpose', CodePurpose::Invite)->exists())->toBeFalse();

        // When
        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        // Then
        $response->assertStatus(422)->assertJsonPath('code', 'not_reinvitable');
        Notification::assertNotSentTo($target, AccountInvitation::class);
        expect($target->fresh()->password)->toBe($originalHash);
    });

    it('refuses reinvite to non-admins and to an unverified admin', function () {
        // Given
        $target = User::factory()->speaker()->unverified()->create();
        app(UserCodeService::class)->issue($target, CodePurpose::Invite);

        // When / Then
        $this->actingAs(User::factory()->speaker()->create())
            ->postJson("/api/admin/users/{$target->id}/reinvite")->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->postJson("/api/admin/users/{$target->id}/reinvite")
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');
    });

    it('requires authentication', function () {
        // Given
        $target = User::factory()->speaker()->unverified()->create();

        // When / Then
        $this->postJson("/api/admin/users/{$target->id}/reinvite")->assertUnauthorized();
    });
});
