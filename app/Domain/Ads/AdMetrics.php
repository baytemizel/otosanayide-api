<?php

namespace App\Domain\Ads;

use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Domain\Payments\SealedToken;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** packages/server/ad-metrics.ts: sifreli tek kullanimlik olcum jetonu, gunluk sayac ve 30 gunluk rapor. */
final class AdMetrics
{
    private const AAD = 'otosanayide-ad-metric-v1';

    private static function payload(): Z
    {
        return Z::strictObject(['campaignId' => F7::uuid(), 'businessId' => CampaignRules::documentId(), 'nonce' => F7::uuid(), 'issuedAt' => Z::number()->int()->nonnegative(), 'expiresAt' => Z::number()->int()->positive()]);
    }

    public static function secret(): ?string
    {
        $v = config('ads.ad_metrics_secret');

        return is_string($v) ? $v : null;
    }

    public static function enabled(): bool
    {
        return F7::flag('ad_metrics') && F7::flag('ad_serving') && preg_match('/^[a-fA-F0-9]{64}$/D', (string) self::secret()) === 1;
    }

    public static function token(string $campaignId, string $businessId, int $end, ?string $secret, ?int $now = null): string
    {
        $now ??= F7::nowMs();
        $v = self::payload()->parse((object) ['campaignId' => $campaignId, 'businessId' => $businessId, 'nonce' => F7::uuid()->parse((string) Str::uuid()), 'issuedAt' => $now, 'expiresAt' => min($end, $now + 600000)]);
        if ($v['expiresAt'] <= $now) {
            throw new RuntimeException('expired');
        }

        return SealedToken::seal($v, $secret, self::AAD);
    }

    /** @return array{campaignId:string,businessId:string,nonce:string,issuedAt:int,expiresAt:int} */
    public static function read(string $token, ?string $secret, int $now): array
    {
        $v = self::payload()->parse(SealedToken::open($token, $secret, self::AAD, 1350));
        if ($v['issuedAt'] > $now || $v['expiresAt'] <= $now || $v['expiresAt'] - $v['issuedAt'] > 600000) {
            throw new RuntimeException('expired');
        }

        return $v;
    }

    /** recordMetric */
    public static function record(mixed $raw, ?string $secret, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $input = AdRules::metricEvent()->parse($raw);
        $v = self::read($input['token'], $secret, $now);
        $day = F7::isoDate($v['issuedAt'] + 10800000);
        $id = hash('sha256', $input['token']);

        return FinanceBarrier::run(null, ['kind' => 'ad_campaign', 'id' => $v['campaignId'], 'businessId' => $v['businessId']], static function () use ($input, $v, $day, $id): array {
            $event = F7::lock('ad_metric_events', 'id', $id);
            $daily = DB::table('ad_campaign_metrics')->where('campaign_id', $v['campaignId'])->where('date', $day)->lockForUpdate()->first();
            $kind = $input['kind'];
            if ($event !== null && (bool) $event->{$kind}) {
                return ['recorded' => false];
            }
            $count = static function (string $k) use ($daily): int {
                $n = $daily === null ? 0 : (int) $daily->{$k};
                if ($n < 0 || $n >= F7::SAFE) {
                    throw new RuntimeException('counter');
                }

                return $n;
            };
            DB::table('ad_metric_events')->updateOrInsert(['id' => $id], ['view' => ($event !== null && (bool) $event->view) || $kind === 'view', 'click' => ($event !== null && (bool) $event->click) || $kind === 'click', 'expires_at' => gmdate('Y-m-d H:i:s', intdiv($v['expiresAt'] + 7 * F7::DAY, 1000))]);
            DB::table('ad_campaign_metrics')->updateOrInsert(['campaign_id' => $v['campaignId'], 'date' => $day], ['views' => $count('views') + ($kind === 'view' ? 1 : 0), 'clicks' => $count('clicks') + ($kind === 'click' ? 1 : 0)]);

            return ['recorded' => true];
        });
    }

    /**
     * readMetricReport
     *
     * @param  array{businessId:string,campaignVersion:int}|null  $expected
     */
    public static function report(string $uid, string $campaignId, ?int $now = null, ?array $expected = null): array
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($campaignId);

        return Tx::run(static function () use ($uid, $campaignId, $now, $expected): array {
            $c = AdStore::campaign($campaignId, true);
            $bid = $c['businessId'] ?? null;
            if (! F7::ok(CampaignRules::documentId(), $bid)) {
                throw new RuntimeException('access');
            }
            ['b' => $b, 'm' => $m] = AdStore::business($uid, $bid);
            if (! CampaignRules::owns($uid, $bid, $b, $m)) {
                throw new RuntimeException('access');
            }
            if ($expected !== null && ($bid !== $expected['businessId'] || ($c['version'] ?? null) !== $expected['campaignVersion'])) {
                throw new RuntimeException('version');
            }
            $dates = AdRules::metricDates($now);
            $rows = DB::table('ad_campaign_metrics')->where('campaign_id', $campaignId)->whereIn('date', $dates)->get()->keyBy('date');

            return AdRules::summary(array_map(static function (string $date) use ($rows): array {
                $row = $rows->get($date);

                return ['date' => $date, 'views' => $row === null ? 0 : (int) $row->views, 'clicks' => $row === null ? 0 : (int) $row->clicks];
            }, $dates));
        });
    }
}
