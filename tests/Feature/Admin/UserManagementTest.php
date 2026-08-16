<?php

// tests/Feature/Admin/UserManagementTest.php

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
        // Given
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->speaker()->create();

        // When / Then
        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertOk()->assertJsonCount(4, 'data');
    });

    it('creates an admin by invitation, with no password field', function () {
        // Given
        Notification::fake();
        $admin = User::factory()->admin()->create();

        // When
        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Nadia', 'email' => 'nadia@example.com', 'role' => 'admin',
        ]);

        // Then
        $response->assertCreated()->assertJsonPath('role', 'admin')->assertJsonPath('is_verified', false)
            // The title promises "no password field" — assert it, not just
            // the surrounding shape. UserResource never selects it, but a
            // test with this name should not rely on that being true by
            // coincidence.
            ->assertJsonMissingPath('password');
        $created = User::where('email', 'nadia@example.com')->sole();
        Notification::assertSentTo($created, AccountInvitation::class);
    });

    it('changes a role', function () {
        // Given
        $admin = User::factory()->admin()->create();
        $target = User::factory()->speaker()->create();

        // When / Then
        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'reviewer'])
            ->assertOk()->assertJsonPath('role', 'reviewer');
        expect($target->fresh()->hasRole('reviewer'))->toBeTrue()
            ->and($target->fresh()->hasRole('speaker'))->toBeFalse();
    });

    it('refuses to let an admin change their own role', function () {
        // Given — otherwise the last admin can lock every admin function out of
        // the system with no recovery short of the database.
        $admin = User::factory()->admin()->create();

        // When / Then
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}/role", ['role' => 'speaker'])
            ->assertForbidden();
        expect($admin->fresh()->hasRole('admin'))->toBeTrue();
    });

    it('refuses every endpoint to non-admins', function () {
        // Given
        $target = User::factory()->speaker()->create();

        // When / Then
        foreach ([User::factory()->speaker()->create(), User::factory()->reviewer()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/users', [
                'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin',
            ])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
                ->assertForbidden();
        }
    });

    it('requires authentication', function () {
        // When / Then
        $this->getJson('/api/admin/users')->assertUnauthorized();
    });

    it('lets an unverified admin list, but gates both writes behind verification', function () {
        // Given — the brief calls for this case; nothing pinned it before,
        // and the split (200 on the read, 403 on both writes) is exactly
        // the kind of thing a later routing change could silently flip.
        $admin = User::factory()->admin()->unverified()->create();
        $target = User::factory()->speaker()->create();

        // When / Then
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'X', 'email' => 'unverified-admin@example.com', 'role' => 'admin',
        ])->assertForbidden()->assertJsonPath('code', 'email_unverified');

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}/role", ['role' => 'reviewer'])
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');
    });

    it('refuses to demote the last admin outright — the invariant in its simplest form', function () {
        // Given — proven at the Action layer directly, independent of
        // UserPolicy::updateRole's self-demotion check, which happens to
        // also block this exact case over HTTP (a lone admin is the only
        // actor who could ever reach this endpoint, and the target would
        // then be themselves). Calling the Action directly shows the
        // invariant is enforced at the point of the write, not only by the
        // policy layer sitting above it.
        $admin = User::factory()->admin()->create();

        // When / Then
        expect(fn () => app(ChangeUserRole::class)->handle($admin, UserRole::Speaker))
            ->toThrow(LastAdminException::class);
        expect($admin->fresh()->hasRole('admin'))->toBeTrue();
    });

    it('refuses a concurrent double demotion that would zero out every admin, though each half looks safe alone', function () {
        // Given — two admins, each about to demote the other. Neither
        // targets themselves, so UserPolicy::updateRole's self-check passes
        // for both, and each, read in isolation, leaves one admin standing.
        // Only the combination reaches zero — the bug the reviewer
        // reproduced live against two real concurrent requests.
        //
        // A single-process suite cannot drive two truly simultaneous
        // callers, so this simulates the interleaving directly instead of
        // claiming a real race — the same technique as
        // UserCodeServiceTest's "does not let two racing callers..." test:
        // the moment this call's own serialization read fires (ChangeUserRole
        // locks `Role::where('name', 'admin')` before deciding anything), a
        // hook runs a second, complete ChangeUserRole::handle() call for the
        // *other* admin before this call reaches its own decision — standing
        // in for a request that raced in during that gap and got there first.
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

        // When
        try {
            app(ChangeUserRole::class)->handle($adminTwo, UserRole::Speaker);
            $outerThrew = false;
        } catch (LastAdminException) {
            $outerThrew = true;
        } finally {
            Role::flushEventListeners();
        }

        // Then — the racer reads a system that, from its own point of view,
        // still has two admins, and is correctly let through. This call's
        // own decision has to be made against the count *after* that
        // racer's write, not the two-admin count that was true when this
        // call started — or both succeed and the system reaches zero.
        expect($racerThrew)->toBeFalse()
            ->and($outerThrew)->toBeTrue();

        // This call's own transaction rolls back once it throws, and in
        // this single-connection simulation — unlike a real second
        // connection — that unwinds the racer's nested write along with it.
        // That is a side effect of nesting the racer inside one shared
        // transaction, not a limitation of the fix: remove the guard above
        // and neither call throws, nothing rolls back, and this line finds
        // zero instead of two.
        expect(User::whereHas('roles', fn ($q) => $q->where('name', UserRole::Admin->value))->count())->toBe(2);
    });
});
