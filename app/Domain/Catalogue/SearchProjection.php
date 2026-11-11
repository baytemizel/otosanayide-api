<?php

namespace App\Domain\Catalogue;

use Illuminate\Support\Facades\DB;

/** Isletme yazimiyla AYNI transaction'da cagrilir; ayri esitleme isi yoktur. */
final class SearchProjection
{
    public static function sync(string $businessId): void
    {
        $record = BusinessReader::records([$businessId])[$businessId] ?? null;
        $doc = $record === null ? null : BusinessReader::indexDocument($businessId, $record);
        if ($doc === null) {
            DB::table('business_search')->where('business_id', $businessId)->delete();

            return;
        }
        // Boylam/enlem karismasin diye WKT eksen sirasi acik verilir (SRID 4326 = enlem-boylam).
        DB::statement(
            "INSERT INTO business_search (business_id, name, city, district, district_norm, card, search_text, latitude, longitude, location, fingerprint)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326, 'axis-order=lat-long'), ?)
             ON DUPLICATE KEY UPDATE name=VALUES(name), city=VALUES(city), district=VALUES(district), district_norm=VALUES(district_norm), card=VALUES(card), search_text=VALUES(search_text), latitude=VALUES(latitude), longitude=VALUES(longitude), location=VALUES(location), fingerprint=VALUES(fingerprint)",
            [$businessId, $doc['name'], $doc['city'], $doc['districtDisplay'], $doc['district'], $doc['card'] ? 1 : 0, $doc['text'], $doc['lat'], $doc['lng'], sprintf('POINT(%.17g %.17g)', $doc['lat'], $doc['lng']), $doc['fingerprint']]
        );
        DB::table('business_search_facets')->where('business_id', $businessId)->delete();
        $rows = [];
        foreach (['category' => $doc['categories'], 'service' => $doc['services'], 'brand' => $doc['brands']] as $facet => $values) {
            foreach (array_unique($values) as $value) {
                $rows[] = ['business_id' => $businessId, 'facet' => $facet, 'value' => $value];
            }
        }
        if ($rows !== []) {
            DB::table('business_search_facets')->insert($rows);
        }
        ReviewSummary::refresh($businessId); // web-08: yeniden olusan satir sifirdan baslamasin.
    }
}
