<?php

// app/Actions/Admin/ChangeUserRole.php

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
            // Every role change locks this one row — the admin role itself,
            // always the same row regardless of who is involved — before
            // reading or writing anything else. The invariant being
            // defended ("at least one admin remains") is a property of the
            // whole admin set, not of $target alone: two concurrent admins
            // each demoting a *different* admin would otherwise each read
            // "the other one is still admin" and both proceed, landing on
            // zero. UserPolicy::updateRole cannot express this — a policy
            // returns a boolean and has nowhere to hold a lock across the
            // read-then-write gap — so it lives here, in the transaction
            // that actually performs the write.
            //
            // Locking one fixed sentinel row, rather than the varying set
            // of current admins, also rules out a deadlock: two concurrent
            // callers who first wrote to two *different* admin rows before
            // locking the rest of the set could each end up waiting on a
            // row the other already holds. Every caller here contends for
            // the same single row, in the same order, so the second caller
            // simply waits — and once unblocked, re-reads the admin set
            // fresh, reflecting whatever the first caller already
            // committed, rather than the snapshot that was true when it
            // started waiting.
            Role::where('name', UserRole::Admin->value)->lockForUpdate()->firstOrFail();

            // Not User::role() — User defines its own instance method of that
            // exact name (the single role this user holds, for UserResource),
            // which shadows Spatie's scopeRole and turns a static
            // User::role(...) call into a hard "cannot be called statically"
            // error. Querying the roles relation directly sidesteps the clash.
            $adminIds = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::Admin->value))->pluck('id');

            $wouldZeroOutAdmins = $role !== UserRole::Admin
                && $adminIds->contains($target->id)
                && $adminIds->count() <= 1;

            throw_if($wouldZeroOutAdmins, LastAdminException::class);

            // syncRoles, not assignRole — assignRole adds a role without
            // removing the old one, leaving the user holding two, and
            // UserResource::role() derives a single value from the pivot,
            // so the result would be silently arbitrary.
            $target->syncRoles([$role->value]);

            return $target->fresh()->load('roles');
        });
    }
}
