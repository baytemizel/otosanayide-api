<?php

namespace App\Domain\Brand;

use App\Domain\Content\Ids;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Exceptions\HttpError;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use Throwable;

/** /api/admin/brand-tariffs, brand-capacities, brand-quote, brand-campaigns. */
final class BrandAdmin
{
    public static function tariff(array $market): array
    {
        $id = BrandRules::tariffId($market);
        $raw = BrandStore::tariff($id, false);
        $tariff = $raw !== null ? BrandRules::tariffView($raw) : null;
        if ($tariff !== null && BrandRules::marketKey($tariff['market']) !== BrandRules::marketKey($market)) {
            throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
        }

        return ['tariff' => $tariff];
    }

    /** @param array{version:int,value:array<string,mixed>,reason:string} $a */
    public static function saveTariff(string $uid, array $a): array
    {
        $v = $a['value'];
        $id = BrandRules::tariffId($v['market']);
        FinanceBarrier::run($uid, ['kind' => 'actor'], static function () use ($uid, $a, $v, $id): void {
            $old = F7::lock('brand_tariffs', 'id', $id);
            if (($old === null ? 0 : (int) $old->version) !== $a['version']) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }
            $row = ['placement' => $v['market']['placement'], 'city' => $v['market']['city'], 'category' => $v['market']['category'], 'enabled' => $v['enabled'], 'daily_minor' => $v['dailyMinor'], 'tax_basis_points' => $v['taxBasisPoints'], 'version' => $a['version'] + 1, 'updated_at' => Ids::now()];
            $old === null ? DB::table('brand_tariffs')->insert(['id' => $id] + $row) : DB::table('brand_tariffs')->where('id', $id)->update($row);
            DB::table('brand_tariff_history')->insert(['tariff_id' => $id, 'version' => $a['version'] + 1, 'value' => F7::json($v), 'reason' => $a['reason'], 'actor_id' => $uid, 'created_at' => Ids::now()]);
        });

        return ['tariff' => $v + ['version' => $a['version'] + 1]];
    }

    /** @param array{placement:string,version:int,enabled:bool,capacity:int,reason:string} $a */
    public static function saveCapacity(string $uid, array $a): array
    {
        $capacity = ['enabled' => $a['enabled'], 'capacity' => $a['capacity'], 'version' => $a['version'] + 1];
        FinanceBarrier::run($uid, ['kind' => 'actor'], static function () use ($uid, $a, $capacity): void {
            $old = F7::lock('brand_capacities', 'placement', $a['placement']);
            if (($old === null ? 0 : (int) $old->version) !== $a['version']) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }
            DB::table('brand_capacities')->updateOrInsert(['placement' => $a['placement']], $capacity);
            DB::table('brand_capacity_history')->insert(['placement' => $a['placement'], 'value' => F7::json($capacity), 'reason' => $a['reason'], 'actor_id' => $uid, 'created_at' => Ids::now()]);
        });

        return ['capacity' => $capacity];
    }

    /** @param array{campaignId:string,campaignVersion:int,tariffVersion:int} $a */
    public static function quote(array $a): array
    {
        return Tx::run(static function () use ($a): array {
            $row = BrandStore::campaign($a['campaignId'], false);
            if ($row === null) {
                throw new HttpError(404, 'Marka kampanyası bulunamadı.');
            }
            $c = $row['creative'];
            $tariff = BrandStore::tariff(BrandRules::tariffId(['placement' => $c['placement'], 'city' => $c['city'], 'category' => $c['category']]), false);
            if ($tariff === null) {
                throw new HttpError(409, 'Bu kapsam için marka tarifesi tanımlanmamış.');
            }
            try {
                return BrandRules::price($row, BrandRules::tariffView($tariff), $a, F7::nowMs());
            } catch (Throwable) {
                throw new HttpError(409, 'Tarife, kampanya sürümü veya tarih değişti. Teklifi yenile.');
            }
        });
    }

    public static function campaigns(string $uid, ?string $after, bool $mediaEnabled): array
    {
        $q = DB::table('brand_campaigns')->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->get()->all();

        return ['mediaEnabled' => $mediaEnabled, 'items' => array_map(static fn (\stdClass $r): array => BrandRules::view($r->id, BrandStore::campaignDoc($r), $uid), array_slice($rows, 0, 20)), 'next' => count($rows) > 20 ? $rows[19]->id : null];
    }

    /** @param array<string,mixed> $a brandAction ciktisi */
    public static function act(string $uid, array $a): array
    {
        $now = F7::nowMs();

        return FinanceBarrier::run($uid, ['kind' => 'brand_campaign', 'id' => $a['id'], 'allowCreate' => $a['action'] === 'save' && $a['version'] === 0], static function () use ($uid, $a, $now): array {
            $old = BrandStore::campaign($a['id']);
            if ($a['action'] === 'approve' && isset($old['asset'])) {
                $view = DB::table('brand_media_views')->where('campaign_id', $a['id'])->where('viewer_key', hash('sha256', $uid))->lockForUpdate()->first();
                $v = $view === null ? null : ['id' => $view->image_id, 'sha256' => $view->sha256, 'version' => (int) $view->version, 'viewedAtMs' => (int) $view->viewed_at_ms];
                if (! BrandRules::imageViewed($old['asset'], $old['version'], $v, $now)) {
                    throw new HttpError(409, 'Onaydan önce güncel marka görselini açıp incele.');
                }
            }
            try {
                $next = BrandRules::transition($old, $a, $uid, $now);
            } catch (Throwable) {
                throw new HttpError(409, 'Kampanya değişti veya inceleme koşulları sağlanmadı.');
            }
            if ($a['action'] === 'pause') {
                BrandInventory::release($a['id'], $uid, $now);
            }
            BrandStore::putCampaign($a['id'], $next, $old !== null);
            BrandStore::history($a['id'], $a['action'], $uid, $next);

            return BrandRules::view($a['id'], $next, $uid);
        });
    }
}
