<?php
// app/Http/Resources/UserResource.php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
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
        ];
    }
}
