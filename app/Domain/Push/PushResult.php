<?php

namespace App\Domain\Push;

/** Yalniz iki kesin sonuc vardir; digerleri PushUncertain istisnasidir ve yeniden gonderilmez. */
final class PushResult
{
    private function __construct(public readonly string $status, public readonly ?string $messageId) {}

    public static function accepted(string $messageId): self
    {
        return new self('accepted', substr($messageId, 0, 500));
    }

    /** Saglayici jetonun gecersiz/kayitsiz oldugunu kesin bildirdi: cihaz kaydi silinir. */
    public static function invalidToken(): self
    {
        return new self('invalid_token', null);
    }
}
