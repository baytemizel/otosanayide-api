<?php

namespace App\Domain\Ads;

use App\Domain\Content\Ids;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;

/** /api/admin/ad-tariffs: pazar (yer/il/kategori) basina tarife; surum kontrollu yazim ve gecmis. */
final class AdTariffs
{
    /** @param array{placement:string,city:string,category:string} $market */
    public static function read(array $market): array
    {
        $t = AdStore::tariff(AdRules::marketId($market));
        if ($t === null) {
            return ['tariff' => null];
        }

        return ['tariff' => $market + ['enabled' => $t['enabled'], 'capacity' => $t['capacity'], 'sevenDaysMinor' => $t['sevenDaysMinor'], 'thirtyDaysMinor' => $t['thirtyDaysMinor'], 'taxBasisPoints' => $t['taxBasisPoints'], 'version' => $t['version']]];
    }

    /** @param array{version:int,value:array<string,mixed>,reason:string} $input */
    public static function update(string $uid, array $input): array
    {
        $v = $input['value'];
        $id = AdRules::marketId($v);
        FinanceBarrier::run($uid, ['kind' => 'actor'], static function () use ($uid, $input, $v, $id): void {
            $old = F7::lock('ad_tariffs', 'id', $id);
            if (($old === null ? 0 : (int) $old->version) !== $input['version']) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }
            $row = ['placement' => $v['placement'], 'city' => $v['city'], 'category' => $v['category'], 'enabled' => $v['enabled'], 'capacity' => $v['capacity'], 'seven_days_minor' => $v['sevenDaysMinor'], 'thirty_days_minor' => $v['thirtyDaysMinor'], 'tax_basis_points' => $v['taxBasisPoints'], 'version' => $input['version'] + 1, 'updated_at' => Ids::now()];
            if ($old === null) {
                DB::table('ad_tariffs')->insert(['id' => $id] + $row);
            } else {
                DB::table('ad_tariffs')->where('id', $id)->update($row);
            }
            DB::table('ad_tariff_history')->insert(['tariff_id' => $id, 'version' => $input['version'] + 1, 'value' => F7::json($v), 'reason' => $input['reason'], 'actor_id' => $uid, 'created_at' => Ids::now()]);
        });

        return ['version' => $input['version'] + 1];
    }
}
