<?php

namespace App\Domain\Push;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Varsayilan surucu: saglayiciya baglanmaz, jetonu loglamaz. Uretimde reddedilir; aksi halde
 * kuyruk gercek teslim olmadan "accepted" kapanirdi.
 */
final class LogPushSender implements PushSender
{
    public function bindingDigest(): string
    {
        return hash('sha256', json_encode(['driver' => 'log', 'contract' => 'push-v1']) ?: '');
    }

    public function send(PushMessage $message): PushResult
    {
        if (App::environment('production')) {
            throw new RuntimeException('push_log_driver_in_production');
        }
        Log::info('push.log', ['provider' => $message->provider, 'locale' => $message->locale, 'key' => substr($message->idempotencyKey, 0, 12)]);

        return PushResult::accepted('log-'.substr($message->idempotencyKey, 0, 32));
    }
}
