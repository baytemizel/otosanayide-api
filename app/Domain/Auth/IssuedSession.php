<?php

namespace App\Domain\Auth;

final class IssuedSession
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly string $refreshToken,
        public readonly int $refreshMaxAge,
        public readonly string $uid,
        public readonly string $email,
        public readonly bool $emailVerified,
    ) {}
}
