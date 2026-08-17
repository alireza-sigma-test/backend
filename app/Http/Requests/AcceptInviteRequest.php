<?php

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
            // Invite codes are issued upper-case and Hash::check is case-sensitive, so
            // without this a code retyped in lowercase burns one of only five attempts.
            // Trimmed too — codes are copied out of an email client.
            code: $this->string('code')->upper()->trim()->value(),
            password: $this->string('password')->value(),
        );
    }
}
