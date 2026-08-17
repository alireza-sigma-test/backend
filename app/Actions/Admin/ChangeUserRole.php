<?php

namespace App\Actions\Admin;

use App\Enums\UserRole;
use App\Exceptions\LastAdminException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class ChangeUserRole
{
    public function handle(User $target, UserRole $role): User
    {
        return DB::transaction(function () use ($target, $role): User {
            // "At least one admin remains" is a property of the whole admin set, not
            // of $target, so a policy cannot express it. Locking one fixed sentinel
            // row serialises every role change: two admins each demoting a different
            // admin would otherwise both read "the other one is still admin" and land
            // on zero. A fixed row rather than the varying admin set also rules out
            // deadlock — every caller contends for the same row, in the same order.
            Role::where('name', UserRole::Admin->value)->lockForUpdate()->firstOrFail();

            // Not User::role() — User's own instance method of that name shadows
            // Spatie's scopeRole, making the static call a fatal.
            $adminIds = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::Admin->value))->pluck('id');

            $wouldZeroOutAdmins = $role !== UserRole::Admin
                && $adminIds->contains($target->id)
                && $adminIds->count() <= 1;

            throw_if($wouldZeroOutAdmins, LastAdminException::class);

            // syncRoles, not assignRole: assignRole would leave the user holding two
            // roles, and UserResource::role() would then pick one arbitrarily.
            $target->syncRoles([$role->value]);

            return $target->fresh()->load('roles');
        });
    }
}
