<?php

namespace App\Domain\Push;

/** Saglayiciya giden tek cihaz bildirimi. Govde sabittir: arac, plaka, ozel baslik veya UID tasimaz. */
final class PushMessage
{
    public function __construct(
        public readonly string $provider,
        public readonly string $token,
        public readonly string $appId,
        public readonly ?string $environment,
        public readonly string $locale,
        public readonly string $idempotencyKey,
        public readonly string $title,
        public readonly string $body,
        public readonly string $route = '/hesabim',
        public readonly string $collapseKey = 'otosanayide-reminder',
        public readonly int $ttlSeconds = 86400,
    ) {}
}
