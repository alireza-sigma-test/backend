<?php

// app/Actions/Auth/AcceptInvite.php

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
     * Same shape as LoginUser's constant-time defence. Without the dummy work,
     * an unknown address returns measurably faster than a known one and the
     * enumeration oracle answers by the clock instead of by the response.
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

        // consume() runs inside the same transaction as the password write —
        // not before it. UserCodeService::consume() is atomic on its own
        // column, but pairing "burn the code" with "set the password" is a
        // two-write invariant that a single-statement update cannot cover.
        // If the code were consumed first and committed, and the password
        // write then failed, the invitation would be gone with no account
        // ever claimed and no way to retry. Keeping both in one transaction
        // means a failed password write rolls consumption back with it.
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
