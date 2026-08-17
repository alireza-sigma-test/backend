<?php

namespace App\Actions\Auth;

use App\Data\AcceptInviteData;
use App\Enums\CodePurpose;
use App\Models\User;
use App\Services\UserCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AcceptInvite
{
    /**
     * Same constant-time defence as LoginUser: without the dummy work an unknown
     * address returns measurably faster, and the clock becomes the oracle.
     */
    private const DUMMY_HASH = '$2y$12$Pj/5N/Ebkm2yTUup94tKZ.tP6xd8sEJxcnnTOnq5relN9n3Z7pPpS';

    public function __construct(private UserCodeService $codes) {}

    /** @return array{token: string, user: User}|null */
    public function handle(AcceptInviteData $data): ?array
    {
        $user = User::where('email', $data->email)->first();

        if ($user === null) {
            // Burn comparable time, then fail exactly as a wrong code does.
            Hash::check($data->code, self::DUMMY_HASH);

            return null;
        }

        // consume() must run inside the same transaction as the password write: if the
        // code were burned and committed first and the write then failed, the
        // invitation would be gone with no account claimed and no way to retry.
        return DB::transaction(function () use ($user, $data): ?array {
            if (! $this->codes->consume($user, CodePurpose::Invite, $data->code)) {
                return null;
            }

            $user->forceFill(['password' => Hash::make($data->password)])->save();
            $user->markEmailAsVerified();

            return ['token' => $user->createToken('api')->plainTextToken, 'user' => $user->fresh()->load('roles')];
        });
    }
}
