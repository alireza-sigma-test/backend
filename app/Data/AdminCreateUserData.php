<?php

// app/Data/AdminCreateUserData.php

namespace App\Data;

use App\Enums\UserRole;

final readonly class AdminCreateUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public UserRole $role,
    ) {}
}
