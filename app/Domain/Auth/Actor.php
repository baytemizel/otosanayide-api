<?php

namespace App\Domain\Auth;

/** Kaynaktaki dogrulanmis jeton alanlari. */
final class Actor
{
    public function __construct(
        public readonly string $uid,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly bool $admin,
        public readonly bool $accountErasureOperator,
        public readonly bool $brandFinance,
        public readonly ?string $secondFactor,
        public readonly int $authTime,
        public readonly int $iat,
        public readonly string $sessionId,
    ) {}
}
