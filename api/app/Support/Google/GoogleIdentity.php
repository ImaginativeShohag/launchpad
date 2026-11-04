<?php

namespace App\Support\Google;

final readonly class GoogleIdentity
{
    public function __construct(
        public string $sub,
        public string $email,
        public bool $emailVerified,
        public string $name,
    ) {}
}
