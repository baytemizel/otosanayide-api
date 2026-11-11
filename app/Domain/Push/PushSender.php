<?php

namespace App\Domain\Push;

/** Gonderim kapisi (port). Uygulama ici kutu bundan bagimsizdir. */
interface PushSender
{
    /** Saglayici baglami ozeti: ayni dis islem defterinde baska baglama gecis ayirt edilir. */
    public function bindingDigest(): string;

    /** @throws PushUncertain */
    public function send(PushMessage $message): PushResult;
}
