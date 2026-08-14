<?php

// app/Actions/Auth/LoginUser.php

namespace App\Actions\Auth;

use App\Data\LoginData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginUser
{
    /**
     * A real bcrypt hash at the app's configured cost, of a value nobody knows.
     *
     * An identical error message is not enough on its own: short-circuiting on
     * `! $user` would skip bcrypt entirely and answer unknown emails ~14x faster
     * than real ones, which is a trivially measurable enumeration oracle. Every
     * login therefore pays exactly one hash comparison.
     */
    private const DUMMY_HASH = '$2y$12$Pj/5N/Ebkm2yTUup94tKZ.tP6xd8sEJxcnnTOnq5relN9n3Z7pPpS';

    /** @return array{token: string, user: User} */
    public function handle(LoginData $data): array
    {
        $user = User::with('roles')->where('email', $data->email)->first();

        // Runs on both branches — do not fold this into the condition below.
        $passwordMatches = Hash::check($data->password, $user?->password ?? self::DUMMY_HASH);

        // One generic message for both branches — never reveal whether the
        // email exists.
        if (! $user || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return ['token' => $user->createToken('api')->plainTextToken, 'user' => $user];
    }
}
