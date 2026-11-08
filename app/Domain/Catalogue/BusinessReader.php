<?php

namespace App\Domain\Catalogue;

use App\Support\Js;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use stdClass;

/** packages/server/search-index.ts: yayinlanan DTO ve arama projeksiyonu. */
final class BusinessReader
{
    /**
     * @param  list<string>  $ids
     * @return array<string,array<string,mixed>> id => kayit
     */
    public static function records(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('businesses')->whereIn('id', $ids)->get()->keyBy('id');
        $values = DB::table('business_values')->whereIn('business_id', $ids)->orderBy('position')->get()->groupBy('business_id');
        $photos = DB::table('business_public_photos')->whereIn('business_id', $ids)->orderBy('position')->get()->groupBy('business_id');
        $out = [];
        foreach ($rows as $id => $row) {
            $out[$id] = self::record($row, collect($values[$id] ?? [])->all(), collect($photos[$id] ?? [])->pluck('photo_id')->all());
        }

        return $out;
    }

    /**
     * @param  list<stdClass>  $values
     * @param  list<string>  $photoIds
     * @return array<string,mixed>
     */
    private static function record(stdClass $row, array $values, array $photoIds): array
    {
        $list = static fn (string $kind): array => array_values(array_map(static fn (stdClass $v): string => $v->value, array_filter($values, static fn (stdClass $v): bool => $v->kind === $kind)));

        return [
            'name' => $row->name, 'city' => $row->city, 'district' => $row->district, 'address' => $row->address,
            'phone' => $row->phone, 'contactEmail' => $row->contact_email, 'website' => $row->website, 'hours' => $row->hours,
            'description' => $row->description, 'categories' => $list('category'), 'services' => $list('service'), 'brands' => $list('brand'),
            'published' => (bool) $row->published, 'sample' => (bool) $row->sample, 'ownership' => $row->ownership, 'ownerReady' => (bool) $row->owner_ready,
            'features' => ['quotes' => (bool) $row->feature_quotes, 'appointments' => (bool) $row->feature_appointments],
            'coordinateStatus' => $row->coordinate_status,
            'latitude' => $row->latitude === null ? null : (float) $row->latitude,
            'longitude' => $row->longitude === null ? null : (float) $row->longitude,
            'cardPayment' => $row->card_payment === null ? null : (bool) $row->card_payment,
            'wc' => $row->wc === null ? null : (bool) $row->wc,
            'businessCoverPhotoId' => $row->business_cover_photo_id,
            'publicBusinessPhotoIds' => $photoIds,
        ];
    }

    /** @param array<string,mixed> $b */
    private static function profileValid(array $b): bool
    {
        $website = Z::string()->trim()->max(500)->refine(static function (string $s): bool {
            if ($s === '') {
                return true;
            }
            $u = parse_url($s);

            return is_array($u) && in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) && ! empty($u['host']) && ! isset($u['user']) && ! isset($u['pass']);
        });
        $schema = Z::strictObject([
            'name' => Z::string()->trim()->min(2)->max(160),
            'city' => Z::string()->refine(static fn (string $s): bool => in_array($s, Taxonomy::cities(), true)),
            'district' => Z::string()->trim()->min(2)->max(100),
            'address' => Z::string()->trim()->min(10)->max(500),
            'phone' => Z::string()->trim()->refine(static fn (string $v): bool => $v === '' || Contact::phone($v) !== null),
            'website' => $website,
            'hours' => Z::string()->trim()->max(300),
            'description' => Z::string()->trim()->max(2000),
            'categories' => Z::array(Z::string()->refine(static fn (string $s): bool => Taxonomy::category($s) !== null))->min(1)->max(12),
            'services' => Z::array(Z::string()->trim()->min(2)->max(120))->max(50),
            'brands' => Z::array(Z::string()->trim()->min(1)->max(80))->max(100),
            'location' => Z::literal(null),
        ]);
        try {
            $schema->parse((object) ['name' => $b['name'], 'city' => $b['city'], 'district' => $b['district'], 'address' => $b['address'], 'phone' => $b['phone'] ?? '', 'website' => $b['website'] ?? '', 'hours' => $b['hours'] ?? '', 'description' => $b['description'] ?? '', 'categories' => $b['categories'], 'services' => $b['services'], 'brands' => $b['brands'], 'location' => null]);

            return true;
        } catch (\App\Support\ZodError) {
            return false;
        }
    }

    /**
     * searchBusiness(): yayinlanmamis, ornek veya profili gecersiz kayit null.
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>|null
     */
    public static function publicBusiness(string $id, array $b): ?array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $id) !== 1 || $b['published'] !== true || $b['sample'] === true || ! self::profileValid($b)) {
            return null;
        }
        $lat = $b['latitude'];
        $lng = $b['longitude'];
        $verified = $b['coordinateStatus'] === 'verified' && is_float($lat) && is_finite($lat) && $lat >= 35.7 && $lat <= 42.3 && is_float($lng) && is_finite($lng) && $lng >= 25.5 && $lng <= 45;
        $owned = $b['ownership'] === 'verified';
        $ready = $owned && $b['ownerReady'] === true;
        $email = Contact::businessEmail($b['contactEmail']);
        $cover = $b['businessCoverPhotoId'];
        $out = ['id' => $id];
        if ($email !== null) {
            $out['contactEmail'] = $email;
        }
        $out += [
            'name' => Js::trim($b['name']), 'city' => $b['city'], 'district' => Js::trim($b['district']), 'address' => Js::trim($b['address']),
            'phone' => Js::trim($b['phone'] ?? ''), 'website' => Js::trim($b['website'] ?? ''), 'hours' => Js::trim($b['hours'] ?? ''),
            'categories' => $b['categories'], 'services' => array_map([Js::class, 'trim'], $b['services']), 'brands' => array_map([Js::class, 'trim'], $b['brands']),
            'published' => true, 'ownership' => $owned ? 'verified' : 'unclaimed', 'coordinateStatus' => $verified ? 'verified' : 'unverified',
        ];
        if ($verified) {
            $out['latitude'] = $lat;
            $out['longitude'] = $lng;
        }
        $out['ownerReady'] = $ready;
        $out['features'] = ['quotes' => $ready && $b['features']['quotes'] === true, 'appointments' => $ready && $b['features']['appointments'] === true];
        if (is_bool($b['cardPayment'])) {
            $out['cardPayment'] = $b['cardPayment'];
        }
        if (is_bool($b['wc'])) {
            $out['wc'] = $b['wc'];
        }
        if ($owned && is_string($cover) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $cover) === 1 && in_array($cover, array_slice($b['publicBusinessPhotoIds'], 0, 8), true)) {
            $out['coverPhotoId'] = $cover;
        }

        return $out;
    }

    /**
     * indexDocument(): arama yalniz yayinda VE koordinati onayli kaydi alir (kullanici karari; kaynakta koordinatsiz kayit da vardi).
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>|null
     */
    public static function indexDocument(string $id, array $b): ?array
    {
        $v = self::publicBusiness($id, $b);
        if ($v === null || $v['coordinateStatus'] !== 'verified') {
            return null;
        }
        $names = array_map(static fn (string $c): string => Taxonomy::category($c)['name'] ?? '', $v['categories']);
        $services = array_map([Taxonomy::class, 'normalize'], $v['services']);
        $fields = [
            'id' => $id, 'name' => $v['name'], 'city' => $v['city'], 'district' => Taxonomy::normalize($v['district']), 'districtDisplay' => $v['district'],
            'categories' => $v['categories'], 'services' => $services, 'brands' => $v['brands'], 'card' => ($v['cardPayment'] ?? null) === true,
            'text' => implode(' ', Taxonomy::queryTerms(implode(' ', [$v['name'], $v['city'], $v['district'], ...$v['services'], ...$v['brands'], ...$names]), false)),
            'lat' => $v['latitude'], 'lng' => $v['longitude'],
        ];
        $fields['fingerprint'] = hash('sha256', (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $fields;
    }
}
