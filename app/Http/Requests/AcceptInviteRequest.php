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
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function toData(): AcceptInviteData
    {
        return new AcceptInviteData(
            email: $this->string('email')->lower()->trim()->value(),
            code: $this->string('code')->value(),
            password: $this->string('password')->value(),
        );
    }
}
