<?php

use Illuminate\Support\Env;

// F7 sirlari ve odeme gecidi ayarlari. Bayraklar config/otosanayide.php'dedir (varsayilan kapali).
// Degerler yalniz ortamdan okunur; depoda gercek deger yoktur.
$env = static fn (string $key): ?string => ($v = Env::getRepository()->get($key)) === null ? null : (string) $v;
$flag = static fn (string $key): bool => Env::getRepository()->get($key) === 'true';

return [
    'ad_metrics_secret' => $env('AD_METRICS_SECRET'),
    'brand_serving_secret' => $env('BRAND_SERVING_SECRET'),
    'brand_metrics_secret' => $env('BRAND_METRICS_SECRET'),
    'brand_public_app_ids' => $env('BRAND_PUBLIC_APP_IDS'),
    // Kaynakta Firebase App Check; MySQL/Laravel yapisinda secilmis bir uygulama dogrulama saglayicisi yok.
    // Bos kaldikca halka acik banner sunumu 503 "henuz yapilandirilmadi" doner.
    'app_attestation' => $env('BRAND_APP_ATTESTATION_PROVIDER'),
    // Marka gorselleri icin ayri ozel disk (claim/review/business disklerinden farkli olmali).
    'brand_media_disk' => $env('BRAND_MEDIA_DISK'),

    // Normalized gateway v1: kendi relay sozlesmemiz. Gercek odeme kurulusu secilmedi (PAY-01).
    'payment' => [
        'name' => $env('PAYMENT_PROVIDER_NAME'),
        'merchant_id' => $env('PAYMENT_MERCHANT_ID'),
        'origin' => $env('PAYMENT_GATEWAY_ORIGIN'),
        'token' => $env('PAYMENT_GATEWAY_TOKEN'),
        'webhook_secret' => $env('PAYMENT_WEBHOOK_SECRET'),
        'return_origin' => $env('PAYMENT_RETURN_ORIGIN'),
        'checkout_origins' => $env('PAYMENT_CHECKOUT_ORIGINS'),
    ],

    // Zamanlanmis F7 isleri varsayilan kapali; --apply olmadan kuru calisir.
    'workers' => [
        'brand_media_cleanup' => $flag('BRAND_MEDIA_CLEANUP_ENABLED'),
    ],
];
