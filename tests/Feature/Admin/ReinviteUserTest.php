<?php

// The invariant: a user must always have a route out of unverified. An
// admin-created account's only credential is its invite code, and once that lapses
// nothing else can revive it — accept, login, re-invite and self-registration all
// fail, and /email/resend sits behind auth:sanctum.
//
// So reinvite must reissue for an account genuinely stuck, and refuse without side
// effects wherever reissuing would replace a real password: one already claimed, and
// one never invited through this flow at all.

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
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        app(UserCodeService::class)->issue($target, CodePurpose::Invite);
        $this->travel(CodePurpose::Invite->ttlMinutes() + 1)->minutes();

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

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
        // The other trigger finding 2 named: the attempt cap, not
        // just expiry, and nothing ever called issue() again for an invite.
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        $service = app(UserCodeService::class);
        $service->issue($target, CodePurpose::Invite);
        foreach (range(1, UserCodeService::MAX_ATTEMPTS) as $ignored) {
            $service->consume($target, CodePurpose::Invite, 'WRONGWRONG12');
        }

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        $response->assertOk();
        Notification::assertSentTo($target, AccountInvitation::class);
    });

    it('replaces the previous unconsumed code rather than leaving two live', function () {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        $first = app(UserCodeService::class)->issue($target, CodePurpose::Invite);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite")->assertOk();

        $stillWorks = app(UserCodeService::class)->consume($target, CodePurpose::Invite, $first);
        expect($stillWorks)->toBeFalse();
    });

    it('refuses to reinvite a user who already accepted, so this cannot become a password-reset backdoor', function () {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->create(['password' => Hash::make('their-real-password')]);
        $originalHash = $target->password;

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        $response->assertStatus(422)->assertJsonPath('code', 'not_reinvitable');
        Notification::assertNotSentTo($target, AccountInvitation::class);
        expect($target->fresh()->password)->toBe($originalHash);
    });

    it('refuses to reinvite a self-registered user who has not verified yet, so their real password is never overwritten', function () {
        // Never touched by CreateUserByAdmin or issued an invite code
        // at all; the only thing distinguishing them from an invited account
        // is that no invite UserCode row exists for them.
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->unverified()->create();
        $originalHash = $target->password;
        expect(UserCode::where('user_id', $target->id)->where('purpose', CodePurpose::Invite)->exists())->toBeFalse();

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite");

        $response->assertStatus(422)->assertJsonPath('code', 'not_reinvitable');
        Notification::assertNotSentTo($target, AccountInvitation::class);
        expect($target->fresh()->password)->toBe($originalHash);
    });

    it('refuses reinvite to non-admins and to an unverified admin', function () {
        $target = User::factory()->speaker()->unverified()->create();
        app(UserCodeService::class)->issue($target, CodePurpose::Invite);

        $this->actingAs(User::factory()->speaker()->create())
            ->postJson("/api/admin/users/{$target->id}/reinvite")->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->postJson("/api/admin/users/{$target->id}/reinvite")
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');
    });

    it('requires authentication', function () {
        $target = User::factory()->speaker()->unverified()->create();

        $this->postJson("/api/admin/users/{$target->id}/reinvite")->assertUnauthorized();
    });
});
