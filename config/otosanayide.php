<?php

use Illuminate\Support\Env;

// Kaynak `process.env.X==='true'` karsilastirir; Laravel env() "TRUE" gibi degerleri de true yapardi.
$flag = static fn (string $key): bool => Env::getRepository()->get($key) === 'true';

return [
    'public_web_origin' => Env::getRepository()->get('PUBLIC_WEB_ORIGIN'),
    'mobile_web_origin' => Env::getRepository()->get('MOBILE_WEB_ORIGIN'),
    'google_places_api_key' => Env::getRepository()->get('GOOGLE_PLACES_API_KEY'),
    'moderation_extra_terms' => Env::getRepository()->get('MODERATION_EXTRA_TERMS'),

    // Zamanlanmis yazicilar varsayilan kapali (kaynakta bes kapali job).
    'workers' => [
        'request_notifications' => $flag('REQUEST_NOTIFICATIONS_WORKER_ENABLED'),
        'garage_recycle_purge' => $flag('GARAGE_RECYCLE_PURGE_ENABLED'),
    ],

    'flags' => [
        'shared_garage' => $flag('SHARED_GARAGE_ENABLED'),
        'mobile_garage_backup' => $flag('MOBILE_GARAGE_BACKUP_ENABLED'),
        'data_requests' => $flag('DATA_REQUESTS_ENABLED'),
        'push_delivery' => $flag('PUSH_DELIVERY_ENABLED'),
        'review_photos' => $flag('REVIEW_PHOTOS_ENABLED'),
        'business_photos' => $flag('BUSINESS_PHOTOS_ENABLED'),
        'claim_evidence' => $flag('CLAIM_EVIDENCE_ENABLED'),
        'google_places' => $flag('GOOGLE_PLACES_ENABLED'),
        'ai_moderation' => $flag('AI_MODERATION_ENABLED'),
        'ad_serving' => $flag('AD_SERVING_ENABLED'),
        'ad_metrics' => $flag('AD_METRICS_ENABLED'),
        'ad_reservations' => $flag('AD_RESERVATIONS_ENABLED'),
        'brand_orders' => $flag('BRAND_ORDERS_ENABLED'),
        'brand_metrics' => $flag('BRAND_METRICS_ENABLED'),
        'brand_cancellations' => $flag('BRAND_CANCELLATIONS_ENABLED'),
        'brand_reservations' => $flag('BRAND_RESERVATIONS_ENABLED'),
        'brand_refund_review' => $flag('BRAND_REFUND_REVIEW_ENABLED'),
        'brand_preview' => $flag('BRAND_PREVIEW_ENABLED'),
        'brand_media' => $flag('BRAND_MEDIA_ENABLED'),
        'brand_acceptance' => $flag('BRAND_ACCEPTANCE_ENABLED'),
        'brand_serving' => $flag('BRAND_SERVING_ENABLED'),
        'payments' => $flag('PAYMENTS_ENABLED'),
    ],
];
