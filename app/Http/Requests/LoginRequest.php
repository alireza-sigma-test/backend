<?php
// app/Http/Requests/LoginRequest.php
namespace App\Http\Requests;

use App\Data\LoginData;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function toData(): LoginData
    {
        return new LoginData(
            email: $this->string('email')->lower()->trim()->value(),
            password: $this->string('password')->value(),
        );
    }
}
