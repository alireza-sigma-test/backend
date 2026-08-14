<?php
// app/Actions/Auth/LoginUser.php
namespace App\Actions\Auth;

use App\Data\LoginData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginUser
{
    /** @return array{token: string, user: User} */
    public function handle(LoginData $data): array
    {
        $user = User::with('roles')->where('email', $data->email)->first();

        // One generic message for both branches — never reveal whether the
        // email exists.
        if (! $user || ! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return ['token' => $user->createToken('api')->plainTextToken, 'user' => $user];
    }
}
