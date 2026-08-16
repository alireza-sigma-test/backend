<?php

// app/Actions/Admin/CreateUserByAdmin.php

namespace App\Actions\Admin;

use App\Data\AdminCreateUserData;
use App\Enums\CodePurpose;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\UserCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateUserByAdmin
{
    public function __construct(private UserCodeService $codes) {}

    public function handle(AdminCreateUserData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                // A random unusable password rather than null: `password` is
                // NOT NULL, and no code path should have to reason about a null
                // one. This value never matches, and the constant-time login
                // defence keeps behaving identically.
                'password' => Str::random(64),
            ]);

            $user->assignRole($data->role->value);

            $user->notify(new AccountInvitation($this->codes->issue($user, CodePurpose::Invite)));

            return $user->load('roles');
        });
    }
}
