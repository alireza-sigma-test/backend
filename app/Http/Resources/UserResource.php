<?php

// app/Http/Resources/UserResource.php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Derived from the roles pivot — there is no role column.
            'role' => $this->role(),
            'initials' => $this->initials(),
            'created_at' => $this->created_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // Denormalised deliberately: the client's only question is "may
            // this person write", and a boolean answers it without every call
            // site re-deriving it from a nullable timestamp.
            'is_verified' => $this->hasVerifiedEmail(),
        ];
    }
}
