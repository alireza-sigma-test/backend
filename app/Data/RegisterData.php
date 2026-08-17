<?php

namespace App\Data;

use App\Enums\UserRole;

final readonly class RegisterData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public UserRole $role,
    ) {}
}
