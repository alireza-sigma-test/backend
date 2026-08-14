<?php
// app/Actions/Auth/RegisterUser.php
namespace App\Actions\Auth;

use App\Data\RegisterData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUser
{
    /** @return array{token: string, user: User} */
    public function handle(RegisterData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
            ]);

            $user->assignRole($data->role->value);

            return ['token' => $user->createToken('api')->plainTextToken, 'user' => $user->load('roles')];
        });
    }
}
