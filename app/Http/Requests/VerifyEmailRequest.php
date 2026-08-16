<?php

// app/Http/Requests/VerifyEmailRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string']];
    }
}
