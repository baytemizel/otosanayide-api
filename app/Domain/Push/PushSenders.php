<?php

namespace App\Domain\Push;

use RuntimeException;

/**
 * PUSH_DRIVER=log (varsayilan) | live. live: cihazin saglayicisina gore apns veya fcm.
 * Saglayici hedefi kullanici kararidir (K171); kimlik dosyalari sunucuda, yol .env'de.
 */
class PushSenders
{
    /** @var array<string,PushSender> */
    private array $made = [];

    public function for(string $provider): PushSender
    {
        $driver = config('otosanayide.push.driver') ?: 'log';
        if ($driver === 'log') {
            return $this->made['log'] ??= new LogPushSender;
        }
        if ($driver !== 'live') {
            throw new RuntimeException('push_driver');
        }

        return $this->made[$provider] ??= match ($provider) {
            'apns' => new ApnsPushSender((string) config('otosanayide.push.apns.key_path'), (string) config('otosanayide.push.apns.key_id'), (string) config('otosanayide.push.apns.team_id')),
            'fcm' => new FcmPushSender((string) config('otosanayide.push.fcm.credentials_path'), config('otosanayide.push.fcm.project_id') ?: null),
            default => throw new RuntimeException('push_provider'),
        };
    }
}
