<?php

namespace App\Http\Requests;

use App\Data\RegisterData;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            // Administrators are created by administrators. Self-registration
            // offers only the two roles a stranger may legitimately claim.
            'role' => ['required', Rule::in([UserRole::Speaker->value, UserRole::Reviewer->value])],
        ];
    }

    public function toData(): RegisterData
    {
        return new RegisterData(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->lower()->trim()->value(),
            password: $this->string('password')->value(),
            role: UserRole::from($this->string('role')->value()),
        );
    }
}
