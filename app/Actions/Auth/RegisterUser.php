<?php

namespace App\Actions\Auth;

use App\Data\RegisterData;
use App\Enums\CodePurpose;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Services\UserCodeService;
use Illuminate\Support\Facades\DB;

final class RegisterUser
{
    public function __construct(private readonly UserCodeService $codes) {}

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

            $code = $this->codes->issue($user, CodePurpose::EmailVerification);
            $user->notify(new EmailVerificationCode($code));

            return ['token' => $user->createToken('api')->plainTextToken, 'user' => $user->load('roles')];
        });
    }
}
