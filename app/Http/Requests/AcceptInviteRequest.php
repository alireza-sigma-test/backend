<?php

// app/Http/Requests/AcceptInviteRequest.php

namespace App\Http\Requests;

use App\Data\AcceptInviteData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInviteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function toData(): AcceptInviteData
    {
        return new AcceptInviteData(
            email: $this->string('email')->lower()->trim()->value(),
            // UserCodeService::issue() upper-cases every invite code
            // (Str::upper(Str::random(12))), but Hash::check is
            // case-sensitive. Left unnormalised, a code copied out of the
            // invitation email and retyped in lowercase failed and burned
            // one of only five attempts — compounding directly into finding
            // 2's permanent lockout. Trimmed too: codes are copied out of an
            // email client, where trailing whitespace is easy to pick up.
            code: $this->string('code')->upper()->trim()->value(),
            password: $this->string('password')->value(),
        );
    }
}
