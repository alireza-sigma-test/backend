<?php

// tests/Feature/Admin/UserManagementTest.php

use App\Models\User;
use App\Notifications\AccountInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

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
        $response->assertCreated()->assertJsonPath('role', 'admin')->assertJsonPath('is_verified', false);
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
});
