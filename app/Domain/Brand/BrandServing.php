<?php

namespace App\Domain\Brand;

use App\Domain\Ads\AdRules;
use App\Domain\Content\Ids;
use App\Domain\Content\MediaStore;
use App\Domain\Payments\F7;
use App\Domain\Payments\SealedToken;
use App\Exceptions\HttpError;
use App\Support\RateLimit;
use App\Support\Tx;
use App\Support\Z;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * brand-publication.ts, brand-serving.ts, brand-media-token.ts, brand-metrics.ts, brand-preview-image.ts:
 * halka acik banner secimi (en fazla 3), 2 dk gecerli sifreli gorsel/olcum jetonlari, tekil olay sayaci.
 */
final class BrandServing
{
    private const MEDIA_AAD = 'otosanayide-public-brand-image-v1';

    private const METRIC_AAD = 'otosanayide-brand-metric-v1';

    private const TTL = 120000;

    public static function servingEnabled(): bool
    {
        return F7::flag('brand_serving');
    }

    /** brandMetricSecret(): olcum kapaliysa null; anahtar diger sirlardan farkli olmali. */
    public static function metricSecret(): ?string
    {
        if (! F7::flag('brand_metrics')) {
            return null;
        }
        $secret = (string) config('ads.brand_metrics_secret');
        if (preg_match('/^[a-fA-F0-9]{64}$/D', $secret) !== 1 || strtolower($secret) === strtolower((string) config('ads.brand_serving_secret')) || strtolower($secret) === strtolower((string) config('ads.ad_metrics_secret'))) {
            throw new HttpError(503, 'Reklam ölçümü henüz yapılandırılmadı.');
        }

        return $secret;
    }

    /**
     * brandPublicAccess: kaynakta Firebase App Check. Bu yapida secilmis uygulama dogrulama saglayicisi yoktur;
     * BRAND_APP_ATTESTATION_PROVIDER tanimlanana kadar 503 "henuz yapilandirilmadi" doner (uydurma dogrulama yok).
     */
    public static function publicAccess(Request $r): string
    {
        $secret = (string) config('ads.brand_serving_secret');
        $apps = array_values(array_filter(array_map('trim', explode(',', (string) config('ads.brand_public_app_ids'))), static fn (string $v): bool => $v !== ''));
        if (preg_match('/^[a-fA-F0-9]{64}$/D', $secret) !== 1 || $apps === [] || ! BrandMedia::enabled() || ! is_string(config('ads.app_attestation')) || config('ads.app_attestation') === '' || ! app()->bound(AppAttestation::class)) {
            throw new HttpError(503, 'Reklam sunumu henüz yapılandırılmadı.');
        }
        $token = $r->headers->get('X-Firebase-AppCheck');
        if ($token === null || $token === '' || strlen($token) > 8192) {
            throw new HttpError(401, 'Uygulama doğrulaması gerekli.');
        }
        $appId = app(AppAttestation::class)->verify($token);
        if ($appId === null) {
            throw new HttpError(401, 'Uygulama doğrulaması gerekli.');
        }
        if (! in_array($appId, $apps, true)) {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
        }
        // Hiz sinirinda ham istemci jetonu veya adres saklanmaz.
        RateLimit::consume('brand-public-'.hash('sha256', $token));

        return $secret;
    }

    private static function payload(array $extra): Z
    {
        return Z::strictObject([
            'reservationId' => F7::uuid(), 'placement' => Z::enum(BrandRules::PLACEMENTS), 'city' => Z::string(), 'category' => Z::string(), 'imageId' => F7::uuid(),
        ] + $extra);
    }

    /** issueBrandMediaToken / issueBrandMetric */
    private static function issue(array $query, int $end, ?string $secret, int $now, string $aad, bool $media): array
    {
        $q = BrandDelivery::imageInput()->parse((object) $query);
        if ($now < 0 || $end <= $now) {
            throw new RuntimeException('expired');
        }
        $data = ($media ? $q + ['v' => 1] : $q) + ['issuedAtMs' => $now, 'expiresAtMs' => min($end, $now + self::TTL)];

        return ['token' => SealedToken::seal($data, $secret, $aad), 'expiresAtMs' => $data['expiresAtMs']];
    }

    /** readBrandMediaToken / readBrandMetric */
    private static function open(string $token, ?string $secret, int $now, string $aad, bool $media): array
    {
        if ($now < 0 || strlen($token) > 2048) {
            throw new RuntimeException('token');
        }
        $extra = ($media ? ['v' => Z::literal(1)] : []) + ['issuedAtMs' => Z::number()->int()->nonnegative(), 'expiresAtMs' => Z::number()->int()->positive()];
        $data = self::payload($extra)->parse(SealedToken::open($token, $secret, $aad));
        BrandDelivery::imageInput()->parse((object) array_intersect_key($data, array_flip(['reservationId', 'placement', 'city', 'category', 'imageId'])));
        if ($data['issuedAtMs'] > $now || $data['expiresAtMs'] <= $now || $data['expiresAtMs'] <= $data['issuedAtMs'] || $data['expiresAtMs'] - $data['issuedAtMs'] > self::TTL) {
            throw new RuntimeException('expired');
        }

        return $data;
    }

    public static function issueMediaToken(array $q, int $end, ?string $secret, int $now): array
    {
        return self::issue($q, $end, $secret, $now, self::MEDIA_AAD, true);
    }

    public static function readMediaToken(string $token, ?string $secret, int $now): array
    {
        return self::open($token, $secret, $now, self::MEDIA_AAD, true);
    }

    public static function issueMetric(array $q, int $end, ?string $secret, int $now): array
    {
        return self::issue($q, $end, $secret, $now, self::METRIC_AAD, false);
    }

    public static function readMetric(string $token, ?string $secret, int $now): array
    {
        return self::open($token, $secret, $now, self::METRIC_AAD, false);
    }

    /** @return list<string> rotateBrandCandidates */
    public static function rotate(array $ids, int $now): array
    {
        $unique = array_values(array_unique($ids));
        sort($unique, SORT_STRING);
        if ($unique === []) {
            return [];
        }
        $offset = intdiv($now, 60000) % count($unique);

        return array_slice(array_merge(array_slice($unique, $offset), array_slice($unique, 0, $offset)), 0, 6);
    }

    /** selectBrandBanners + serveBrandBanners */
    public static function serve(array $context, string $secret, int $now, ?string $metricsSecret): array
    {
        if ($now > 8640000000000000 - 10800000) {
            return [];
        }

        return Tx::run(static function () use ($context, $secret, $now, $metricsSecret): array {
            $day = DB::table('brand_inventory_days')->where('placement', $context['placement'])->where('date', F7::trDate($now))->first();
            try {
                $holds = AdRules::holds($day === null ? null : F7::list($day->holds), $now);
            } catch (\Throwable) {
                return [];
            }
            $ids = array_column($holds, 'reservationId');
            if (count(array_unique($ids)) !== count($ids)) {
                return [];
            }
            $ends = array_column($holds, 'expiresAtMs', 'reservationId');
            $seen = [];
            $items = [];
            foreach (self::rotate($ids, $now) as $reservationId) {
                $result = BrandDelivery::evaluateIn((object) ($context + ['reservationId' => $reservationId]), $now);
                if (! $result['eligible'] || isset($seen[$result['ad']['campaignId']])) {
                    continue;
                }
                $a = $result['ad'];
                $end = $ends[$reservationId];
                $q = $context + ['reservationId' => $reservationId, 'imageId' => $a['image']['id']];
                $access = self::issueMediaToken($q, $end, $secret, $now);
                if ($access['expiresAtMs'] <= $now || $access['expiresAtMs'] > $end || $access['expiresAtMs'] > $now + self::TTL) {
                    throw new RuntimeException('image_access');
                }
                $metrics = $metricsSecret !== null ? self::issueMetric($q, $end, $metricsSecret, $now) : null;
                $item = ['key' => F7::sha([$a['campaignId'], $a['image']['id']]), 'brand' => $a['brand'], 'headline' => $a['headline'], 'description' => $a['description'], 'destinationUrl' => $a['url'], 'placement' => $a['placement'], 'sponsored' => true]
                    + ($metrics !== null ? ['metrics' => $metrics] : [])
                    + ['image' => ['width' => $a['image']['width'], 'height' => $a['image']['height'], 'url' => '/api/brand-banners/image?token='.$access['token'], 'expiresAtMs' => $access['expiresAtMs']]];
                $seen[$a['campaignId']] = true;
                $items[] = $item;
                if (count($items) === 3) {
                    break;
                }
            }

            return $items;
        });
    }

    /** verifyBrandPreviewImage: bayt/ozet/bicim/boyut ve tam cozumleme. */
    public static function verifyImage(string $bytes, array $asset): void
    {
        $asset = BrandRules::asset()->parse((object) $asset);
        if (strlen($bytes) !== $asset['bytes'] || hash('sha256', $bytes) !== $asset['sha256']) {
            throw new RuntimeException('image_integrity');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info['mime'] !== 'image/webp' || $info[0] !== $asset['width'] || $info[1] !== $asset['height'] || 1920 * 1920 < $info[0] * $info[1]) {
            throw new RuntimeException('image_format');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('image_format');
        }
    }

    /**
     * readBrandPreviewImage: once/sonra degerlendirme, gorsel meta verisi degismemis olmali.
     *
     * @param  Closure(string,int):string  $load
     */
    public static function previewImage(array $input, Closure $load): string
    {
        $imageId = $input['imageId'];
        $query = $input;
        unset($query['imageId']);
        $first = BrandDelivery::evaluate((object) $query, true);
        if (! $first['eligible'] || $first['ad']['image']['id'] !== $imageId) {
            throw new RuntimeException('preview_changed');
        }
        $asset = Tx::run(static function () use ($first, $imageId): array {
            $row = BrandStore::campaign($first['ad']['campaignId'], false);
            $v = BrandRules::asset()->parse(isset($row['asset']) ? (object) $row['asset'] : null);
            if ($v['id'] !== $imageId) {
                throw new RuntimeException('preview_changed');
            }

            return $v;
        });
        $bytes = $load($asset['id'], $asset['bytes']);
        self::verifyImage($bytes, $asset);
        $current = BrandDelivery::evaluate((object) $query, true);
        if (! $current['eligible'] || F7::json($current['ad']) !== F7::json($first['ad'])) {
            throw new RuntimeException('preview_changed');
        }
        Tx::run(static function () use ($first, $asset): void {
            $row = BrandStore::campaign($first['ad']['campaignId'], false);
            if (F7::json(BrandRules::asset()->parse(isset($row['asset']) ? (object) $row['asset'] : null)) !== F7::json($asset)) {
                throw new RuntimeException('preview_changed');
            }
        });

        return $bytes;
    }

    /** Diskten sinirli okuma: boyut beklenenle tam ayni olmali. */
    public static function loader(): Closure
    {
        $disk = BrandMedia::disk();

        return static function (string $id, int $expected) use ($disk): string {
            if ($expected < 1 || $expected > 2500000) {
                throw new RuntimeException('image_size');
            }
            $bytes = MediaStore::read($disk, BrandMedia::path($id));
            if (strlen($bytes) !== $expected) {
                throw new RuntimeException('image_size');
            }

            return $bytes;
        };
    }

    public static function publicImage(string $token, string $secret, Closure $load): string
    {
        $v = self::readMediaToken($token, $secret, F7::nowMs());
        $bytes = self::previewImage(array_intersect_key($v, array_flip(['reservationId', 'placement', 'city', 'category', 'imageId'])), $load);
        self::readMediaToken($token, $secret, F7::nowMs());

        return $bytes;
    }

    public static function metricEvent(): Z
    {
        return Z::strictObject(['token' => Z::string()->min(40)->max(2048)->regex('/^[A-Za-z0-9_-]+$/'), 'kind' => Z::enum(['view', 'click'])]);
    }

    /** recordBrandMetric: olay anahtari jetonun ozetidir; her tur bir kez sayilir. */
    public static function record(mixed $raw, string $secret, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $input = self::metricEvent()->parse($raw);
        $v = self::readMetric($input['token'], $secret, $now);
        $id = hash('sha256', $input['token']);
        $day = F7::isoDate($v['issuedAtMs'] + 10800000);

        return Tx::run(static function () use ($input, $v, $id, $day, $now): array {
            $previous = F7::lock('brand_metric_events', 'id', $id);
            $kind = $input['kind'];
            if ($previous !== null && (bool) $previous->{$kind}) {
                return ['recorded' => false];
            }
            $current = BrandDelivery::evaluateIn((object) ['reservationId' => $v['reservationId'], 'placement' => $v['placement'], 'city' => $v['city'], 'category' => $v['category']], $now);
            if (! $current['eligible'] || $current['ad']['image']['id'] !== $v['imageId']) {
                throw new RuntimeException('eligibility');
            }
            $campaignId = $current['ad']['campaignId'];
            $row = DB::table('brand_campaign_metrics')->where('campaign_id', $campaignId)->where('date', $day)->lockForUpdate()->first();
            $count = static function (string $k) use ($row): int {
                $n = $row === null ? 0 : (int) $row->{$k};
                if ($n < 0 || $n >= F7::SAFE) {
                    throw new RuntimeException('counter');
                }

                return $n;
            };
            $views = $count('views');
            $clicks = $count('clicks');
            DB::table('brand_metric_events')->updateOrInsert(['id' => $id], ['view' => ($previous !== null && (bool) $previous->view) || $kind === 'view', 'click' => ($previous !== null && (bool) $previous->click) || $kind === 'click', 'expires_at' => gmdate('Y-m-d H:i:s', intdiv($v['expiresAtMs'] + 7 * F7::DAY, 1000))]);
            DB::table('brand_campaign_metrics')->updateOrInsert(['campaign_id' => $campaignId, 'date' => $day], ['views' => $views + ($kind === 'view' ? 1 : 0), 'clicks' => $clicks + ($kind === 'click' ? 1 : 0)]);

            return ['recorded' => true];
        });
    }

    /** readBrandMetricReport */
    public static function report(string $campaignId, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($campaignId);

        return Tx::run(static function () use ($campaignId, $now): array {
            if (F7::row('brand_campaigns', 'id', $campaignId) === null) {
                throw new RuntimeException('missing');
            }
            $dates = AdRules::metricDates($now);
            $rows = DB::table('brand_campaign_metrics')->where('campaign_id', $campaignId)->whereIn('date', $dates)->get()->keyBy('date');

            return AdRules::summary(array_map(static function (string $d) use ($rows): array {
                $row = $rows->get($d);

                return ['date' => $d, 'views' => $row === null ? 0 : (int) $row->views, 'clicks' => $row === null ? 0 : (int) $row->clicks];
            }, $dates));
        });
    }

    public static function nonce(): string
    {
        return Ids::uuid();
    }
}
