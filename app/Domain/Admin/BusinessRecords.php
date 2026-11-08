<?php

namespace App\Domain\Admin;

use Illuminate\Support\Facades\DB;

/** Onaylanan profil projeksiyonunu kolonlara ve siralı degerlere yazar. */
final class BusinessRecords
{
    /** @param array<string,mixed> $projection profileProjection ciktisi */
    public static function profileColumns(array $projection): array
    {
        $columns = [
            'name' => $projection['name'], 'city' => $projection['city'], 'district' => $projection['district'], 'address' => $projection['address'],
            // e164 olmayan (444) numarada kaynak null yazar; okumalar null ile ''yi ayni gorur, kolon NOT NULL.
            'phone' => $projection['phone'] ?? '',
            'website' => $projection['website'], 'hours' => $projection['hours'], 'description' => $projection['description'],
            'latitude' => $projection['latitude'], 'longitude' => $projection['longitude'], 'coordinate_status' => $projection['coordinateStatus'],
        ];
        if (array_key_exists('contactEmail', $projection)) {
            $columns['contact_email'] = $projection['contactEmail'];
        }

        return $columns;
    }

    /** @param array<string,mixed> $projection */
    public static function writeValues(string $id, array $projection): void
    {
        DB::table('business_values')->where('business_id', $id)->delete();
        foreach (['category' => 'categories', 'service' => 'services', 'brand' => 'brands'] as $kind => $field) {
            foreach (array_values($projection[$field]) as $position => $value) {
                DB::table('business_values')->insert(['business_id' => $id, 'kind' => $kind, 'position' => $position, 'value' => $value]);
            }
        }
    }
}
