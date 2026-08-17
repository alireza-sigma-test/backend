<?php

namespace App\Data;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
