<?php

namespace App\Domain\Catalogue;

use Collator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SearchService
{
    public const PAGE_LIMIT = 24;

    /** @return list<array<string,mixed>> */
    public function search(SearchFilter $filter): array
    {
        $hits = $this->hits($filter);
        $records = BusinessReader::records(array_map('strval', array_keys($hits)));
        $items = [];
        foreach ($hits as $id => $fingerprint) {
            $id = (string) $id;
            $record = $records[$id] ?? null;
            if ($record === null) {
                continue;
            }
            // Indeks yalniz adaydir: guncel kayit yeniden denetlenir, kopuk projeksiyon gosterilmez.
            $doc = BusinessReader::indexDocument($id, $record);
            if ($doc === null || $doc['fingerprint'] !== $fingerprint) {
                continue;
            }
            $visible = BusinessReader::publicBusiness($id, $record);
            $matched = $visible === null ? null : $this->matches($visible, $filter);
            if ($matched !== null) {
                $items[] = $matched;
            }
        }

        return $items;
    }

    /** Arama v2: en cok 20 sayfa (imlec ofseti). Daha derin tarama icin filtre daraltilir. */
    public const MAX_OFFSET = 456;

    /**
     * web-05/06/08: siralama, imlecli sayfa, yorum ozeti, calisma saatleri. Toplam yalniz metin ve konum
     * sorgusu yokken (tek indeksli COUNT) verilir; aksi halde null.
     *
     * @return array{items:list<array<string,mixed>>,more:bool,total:?int}
     */
    public function searchV2(SearchFilter $f, int $offset): array
    {
        $query = DB::table('business_search as s')->select('s.business_id', 's.fingerprint', 's.review_count', 's.review_sum');
        $this->applyFilters($query, $f);
        if ($f->verifiedOwner) {
            $query->whereExists(fn (Builder $q) => $q->from('businesses as b')->whereColumn('b.id', 's.business_id')->where('b.ownership', 'verified'));
        }
        $total = $f->q === '' && $f->center === null ? (clone $query)->count() : null;
        $distance = function () use ($query, $f): void {
            $point = sprintf('POINT(%.17g %.17g)', $f->center['latitude'], $f->center['longitude']);
            $query->orderByRaw("ST_Distance_Sphere(s.location, ST_GeomFromText(?, 4326, 'axis-order=lat-long'), 6371000) asc", [$point]);
        };
        match ($f->sort) {
            'rating' => $query->orderByDesc('s.review_score')->orderByDesc('s.review_count'),
            'most_reviewed' => $query->orderByDesc('s.review_count')->orderByDesc('s.review_score'),
            default => $f->center !== null ? $distance() : null,
        };
        $rows = $query->orderBy('s.name')->orderBy('s.business_id')->offset($offset)->limit(self::PAGE_LIMIT + 1)->get();
        $more = $rows->count() > self::PAGE_LIMIT;
        $rows = $rows->take(self::PAGE_LIMIT);
        $records = BusinessReader::records($rows->pluck('business_id')->map(fn ($id) => (string) $id)->all());
        $items = [];
        foreach ($rows as $row) {
            $id = (string) $row->business_id;
            $record = $records[$id] ?? null;
            $doc = $record === null ? null : BusinessReader::indexDocument($id, $record);
            if ($doc === null || $doc['fingerprint'] !== $row->fingerprint) {
                continue;
            }
            $visible = BusinessReader::publicBusiness($id, $record);
            $matched = $visible === null ? null : $this->matches($visible, $f);
            if ($matched === null || ($f->verifiedOwner && $matched['ownership'] !== 'verified')) {
                continue;
            }
            $items[] = self::extend($matched, (int) $row->review_count, (int) $row->review_sum, $record['openingHoursJson'] ?? null);
        }

        return ['items' => $items, 'more' => $more, 'total' => $total];
    }

    /**
     * v2 DTO eki: yorum ozeti her zaman, calisma saatleri yalniz yapilandirilmis veri varsa.
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>
     */
    public static function extend(array $b, int $count, int $sum, ?string $hoursJson): array
    {
        $b['reviewSummary'] = ReviewSummary::view($count, $sum);
        $hours = OpeningHours::view($hoursJson);
        if ($hours !== null) {
            $b['openingHours'] = $hours;
        }

        return $b;
    }

    /** @return array<string,string> id => parmak izi, siralama korunur */
    private function hits(SearchFilter $f): array
    {
        $query = DB::table('business_search as s')->select('s.business_id', 's.fingerprint')->limit(self::PAGE_LIMIT);
        $this->applyFilters($query, $f);
        if ($f->center !== null) {
            $point = sprintf('POINT(%.17g %.17g)', $f->center['latitude'], $f->center['longitude']);
            $query->orderByRaw("ST_Distance_Sphere(s.location, ST_GeomFromText(?, 4326, 'axis-order=lat-long'), 6371000) asc", [$point]);
        }
        $query->orderBy('s.name')->orderBy('s.business_id');

        return $query->pluck('fingerprint', 'business_id')->all();
    }

    private function applyFilters(Builder $query, SearchFilter $f): void
    {
        if ($f->city !== '') {
            $query->where('s.city', $f->city);
        }
        if ($f->district !== '') {
            $query->where('s.district_norm', Taxonomy::normalize($f->district));
        }
        foreach (['category' => $f->category, 'service' => $f->service !== '' ? Taxonomy::normalize($f->service) : '', 'brand' => $f->brand] as $facet => $value) {
            if ($value !== '') {
                $query->whereExists(fn (Builder $q) => $q->from('business_search_facets as f')->whereColumn('f.business_id', 's.business_id')->where('f.facet', $facet)->where('f.value', $value));
            }
        }
        if ($f->card) {
            $query->where('s.card', 1);
        }
        $terms = Taxonomy::queryTerms($f->q);
        $long = array_values(array_filter($terms, static fn (string $t): bool => strlen($t) >= 2));
        if ($long !== []) {
            // normalize() yalniz [a-z0-9 ] birakir; boolean islec tasiyamaz. Her terim zorunlu ifade.
            $query->whereRaw('MATCH(s.search_text) AGAINST(? IN BOOLEAN MODE)', [implode(' ', array_map(static fn (string $t): string => '+"'.$t.'"', $long))]);
        }
        foreach ($terms as $term) {
            if (strlen($term) < 2) {
                $query->where('s.search_text', 'like', '%'.$term.'%'); // ngram tek harfi indekslemez.
            }
        }
        if ($f->center !== null) {
            $radius = $f->radius ?? 25.0;
            $lat = $f->center['latitude'];
            $lng = $f->center['longitude'];
            $dLat = $radius / 110.574 + 0.01;
            $cos = cos(deg2rad($lat));
            $dLng = $cos > 0.0001 ? min(180.0, $radius / (111.320 * $cos) + 0.01) : 180.0;
            $south = max(-90.0, $lat - $dLat);
            $north = min(90.0, $lat + $dLat);
            $west = max(-180.0, $lng - $dLng);
            $east = min(180.0, $lng + $dLng);
            $box = sprintf('POLYGON((%1$.17g %3$.17g,%2$.17g %3$.17g,%2$.17g %4$.17g,%1$.17g %4$.17g,%1$.17g %3$.17g))', $south, $north, $west, $east);
            $point = sprintf('POINT(%.17g %.17g)', $lat, $lng);
            $query->whereRaw("MBRContains(ST_GeomFromText(?, 4326, 'axis-order=lat-long'), s.location)", [$box]);
            $query->whereRaw("ST_Distance_Sphere(s.location, ST_GeomFromText(?, 4326, 'axis-order=lat-long'), 6371000) <= ?", [$point, $radius * 1000]);
        }
    }

    /**
     * searchBusinesses([visible], {...filter, q:''}): son denetim ve distance alani.
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>|null
     */
    public function matches(array $b, SearchFilter $f): ?array
    {
        $serviceRow = $f->service !== '' ? Taxonomy::category($f->category) : null;
        if ($f->service !== '' && ($serviceRow === null || ! collect($serviceRow['services'])->contains(fn (string $s): bool => Taxonomy::normalize($s) === Taxonomy::normalize($f->service)))) {
            return null;
        }
        $service = Taxonomy::normalize($f->service);
        if (($f->city !== '' && $b['city'] !== $f->city)
            || ($f->district !== '' && ($f->city === '' || Taxonomy::normalize($b['district']) !== Taxonomy::normalize($f->district)))
            || ($f->category !== '' && ! in_array($f->category, $b['categories'], true))
            || ($f->service !== '' && ! in_array($service, array_map([Taxonomy::class, 'normalize'], $b['services']), true))
            || ($f->brand !== '' && ! in_array($f->brand, $b['brands'], true))
            || ($f->card && ($b['cardPayment'] ?? null) !== true)) {
            return null;
        }
        if ($f->center !== null) {
            if ($b['coordinateStatus'] !== 'verified') {
                return null;
            }
            $distance = Taxonomy::distanceKm($f->center, ['latitude' => $b['latitude'], 'longitude' => $b['longitude']]);
            if ($distance > ($f->radius ?? 25.0)) {
                return null;
            }
            $b['distance'] = $distance;
        }

        return $b;
    }

    /** @return list<string> */
    public function districts(string $city): array
    {
        if ($city === '') {
            return [];
        }
        $names = DB::table('business_search')->where('city', $city)->where('district', '!=', '')->distinct()->orderBy('district')->limit(100)->pluck('district')->all();
        $names = array_values(array_filter($names, static fn (string $s): bool => \App\Support\Js::length($s) > 0 && \App\Support\Js::length($s) <= 100));
        (new Collator('tr_TR'))->sort($names);

        return $names;
    }
}
