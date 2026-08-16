<?php

// app/Http/Requests/AdminCreateUserRequest.php

namespace App\Http\Requests;

use App\Data\AdminCreateUserData;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // All three roles, unlike RegisterRequest — admins are exactly how
            // an administrator comes to exist now that self-registration is
            // refused.
            'role' => ['required', Rule::in(UserRole::values())],
        ];
    }

    public function toData(): AdminCreateUserData
    {
        return new AdminCreateUserData(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->lower()->trim()->value(),
            role: UserRole::from($this->string('role')->value()),
        );
    }
}
