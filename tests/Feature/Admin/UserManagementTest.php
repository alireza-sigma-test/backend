<?php

use App\Actions\Admin\ChangeUserRole;
use App\Enums\UserRole;
use App\Exceptions\LastAdminException;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

describe('admin user management', function () {

    beforeEach(fn () => $this->seed(RoleSeeder::class));

    it('lists users for an admin', function () {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->speaker()->create();

        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertOk()->assertJsonCount(4, 'data');
    });

    it('creates an admin by invitation, with no password field', function () {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Nadia', 'email' => 'nadia@example.com', 'role' => 'admin',
        ]);

        $response->assertCreated()->assertJsonPath('role', 'admin')->assertJsonPath('is_verified', false)
            // Asserted outright rather than relying on UserResource happening not to
            // select it.
            ->assertJsonMissingPath('password');
        $created = User::where('email', 'nadia@example.com')->sole();
        Notification::assertSentTo($created, AccountInvitation::class);
    });

    it('changes a role', function () {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->create();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'reviewer'])
            ->assertOk()->assertJsonPath('role', 'reviewer');
        expect($target->fresh()->hasRole('reviewer'))->toBeTrue()
            ->and($target->fresh()->hasRole('speaker'))->toBeFalse();
    });

    it('refuses to let an admin change their own role', function () {
        // Otherwise the last admin can lock every admin function out of
        // the system with no recovery short of the database.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}/role", ['role' => 'speaker'])
            ->assertForbidden();
        expect($admin->fresh()->hasRole('admin'))->toBeTrue();
    });

    it('refuses every endpoint to non-admins', function () {
        $target = User::factory()->speaker()->create();

        foreach ([User::factory()->speaker()->create(), User::factory()->reviewer()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/users', [
                'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin',
            ])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
                ->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/users/{$target->id}/reinvite")->assertForbidden();
        }
    });

    // `unique:users,email` used to run before store()'s authorize(), letting a
    // verified non-admin tell a taken email (422) from a free one (403). EnsureAdmin,
    // prepended before SubstituteBindings, refuses before the Form Request resolves.
    it('refuses a duplicate email and a fresh one identically for a verified non-admin, closing the fifth enumeration oracle', function () {
        $existing = User::factory()->speaker()->create(['email' => 'alex@example.com']);
        $nonAdmin = User::factory()->speaker()->create();

        $onExisting = $this->actingAs($nonAdmin)->postJson('/api/admin/users', [
            'name' => 'X', 'email' => $existing->email, 'role' => 'admin',
        ]);
        $onFresh = $this->actingAs($nonAdmin)->postJson('/api/admin/users', [
            'name' => 'X', 'email' => 'nobody-fresh@example.com', 'role' => 'admin',
        ]);

        $onExisting->assertForbidden();
        $onFresh->assertForbidden()->assertExactJson($onExisting->json());
        expect(User::where('email', 'nobody-fresh@example.com')->exists())->toBeFalse();
    });

    // Route-model binding used to resolve {user} before authorize(), so a real id
    // 403'd while a fake one 404'd. The same EnsureAdmin prepend closes it.
    it('refuses a role change for a real user id and a nonexistent one identically, so a verified non-admin learns nothing about which ids exist', function () {
        $nonAdmin = User::factory()->speaker()->create();
        $real = User::factory()->speaker()->create();
        $fakeId = $real->id + 999_000;

        $onReal = $this->actingAs($nonAdmin)->patchJson("/api/admin/users/{$real->id}/role", ['role' => 'reviewer']);
        $onFake = $this->actingAs($nonAdmin)->patchJson("/api/admin/users/{$fakeId}/role", ['role' => 'reviewer']);

        $onReal->assertForbidden();
        $onFake->assertForbidden()->assertExactJson($onReal->json());
    });

    it('requires authentication', function () {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    });

    it('lets an unverified admin list, but gates every write behind verification', function () {
        // The split — 200 on the read, 403 on every write — is what a later routing
        // change could silently flip.
        $admin = User::factory()->admin()->unverified()->create();
        $target = User::factory()->speaker()->create();

        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'X', 'email' => 'unverified-admin@example.com', 'role' => 'admin',
        ])->assertForbidden()->assertJsonPath('code', 'email_unverified');

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'reviewer'])
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reinvite")
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');
    });

    it('refuses to demote the last admin outright — the invariant in its simplest form', function () {
        // Driven at the Action layer, because UserPolicy::updateRole's self-demotion
        // check happens to block this case over HTTP too. This shows the invariant
        // holds at the point of the write, not only in the policy above it.
        $admin = User::factory()->admin()->create();

        expect(fn () => app(ChangeUserRole::class)->handle($admin, UserRole::Speaker))
            ->toThrow(LastAdminException::class);
        expect($admin->fresh()->hasRole('admin'))->toBeTrue();
    });

    it('refuses a concurrent double demotion that would zero out every admin, though each half looks safe alone', function () {
        // Two admins each demoting the other: neither targets themselves, so the
        // self-check passes for both and each in isolation leaves one admin standing.
        // Only the combination reaches zero.
        //
        // A single-process suite cannot run two callers at once, so the interleaving is
        // simulated: when this call takes its serialization lock, a hook runs a
        // complete handle() for the other admin before this one decides.
        $adminOne = User::factory()->admin()->create();
        $adminTwo = User::factory()->admin()->create();

        $racerThrew = null;
        Role::retrieved(function () use (&$racerThrew, $adminOne): void {
            // Fire once — the racer's own lock-read below must not recurse.
            Role::flushEventListeners();

            try {
                app(ChangeUserRole::class)->handle($adminOne, UserRole::Speaker);
                $racerThrew = false;
            } catch (LastAdminException) {
                $racerThrew = true;
            }
        });

        try {
            app(ChangeUserRole::class)->handle($adminTwo, UserRole::Speaker);
            $outerThrew = false;
        } catch (LastAdminException) {
            $outerThrew = true;
        } finally {
            Role::flushEventListeners();
        }

        // The racer sees two admins and is correctly let through. This call must then
        // decide against the count *after* that write, or both succeed and reach zero.
        expect($racerThrew)->toBeFalse()
            ->and($outerThrew)->toBeTrue();

        // The throw rolls this transaction back, and on one shared connection that
        // unwinds the racer's nested write too — an artifact of the simulation.
        // Remove the guard and nothing throws, and this line finds zero.
        expect(User::whereHas('roles', fn ($q) => $q->where('name', UserRole::Admin->value))->count())->toBe(2);
    });
});
