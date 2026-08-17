<?php

namespace App\Data;

final readonly class AcceptInviteData
{
    public function __construct(
        public string $email,
        public string $code,
        public string $password,
    ) {}
}
