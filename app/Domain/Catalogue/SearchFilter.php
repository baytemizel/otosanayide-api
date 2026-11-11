<?php

namespace App\Domain\Catalogue;

use App\Support\Js;
use App\Support\Query;
use InvalidArgumentException;

/** packages/core/search-request.ts birebir. */
final class SearchFilter
{
    private const ALLOWED = ['q', 'city', 'district', 'category', 'service', 'brand', 'card', 'lat', 'lng', 'radius'];

    /** Arama v2 (web-05/06/08): yalniz v=2 ile kabul edilir; v1 yaniti ve kurallari degismez. */
    private const ALLOWED_V2 = ['v', 'sort', 'cursor', 'verifiedOwner'];

    public const SORTS = ['recommended', 'nearest', 'rating', 'most_reviewed'];

    /** @param array{latitude:float,longitude:float}|null $center */
    public function __construct(
        public readonly string $q,
        public readonly string $city,
        public readonly string $district,
        public readonly string $category,
        public readonly string $service,
        public readonly string $brand,
        public readonly bool $card,
        public readonly ?array $center,
        public readonly ?float $radius,
        public readonly bool $v2 = false,
        public readonly string $sort = 'recommended',
        public readonly ?string $cursor = null,
        public readonly bool $verifiedOwner = false,
    ) {}

    /** Imlecin baglandigi filtre ozeti (cursor haric). */
    public function digest(): string
    {
        return hash('sha256', (string) json_encode([$this->q, $this->city, $this->district, $this->category, $this->service, $this->brand, $this->card, $this->center, $this->radius, $this->sort, $this->verifiedOwner]));
    }

    public static function parse(Query $p): self
    {
        $v2 = $p->has('v');
        if ($v2 && $p->get('v') !== '2') {
            throw new InvalidArgumentException('invalid');
        }
        foreach ($p->keys() as $key) {
            if (! (in_array($key, self::ALLOWED, true) || ($v2 && in_array($key, self::ALLOWED_V2, true))) || $p->count($key) !== 1) {
                throw new InvalidArgumentException('invalid');
            }
        }
        $text = static function (string $k, int $max) use ($p): string {
            $v = Js::trim($p->get($k) ?? '');
            if (Js::length($v) > $max) {
                throw new InvalidArgumentException('invalid');
            }

            return $v;
        };
        $q = $text('q', 150);
        $city = $text('city', 80);
        $district = $text('district', 100);
        $category = $text('category', 80);
        $service = $text('service', 120);
        $brand = $text('brand', 80);
        $categoryRow = $category !== '' ? Taxonomy::category($category) : null;
        $serviceOk = $service === '' || ($categoryRow !== null && collect($categoryRow['services'])->contains(fn (string $s): bool => Taxonomy::normalize($s) === Taxonomy::normalize($service)));
        if (($city !== '' && ! in_array($city, Taxonomy::cities(), true)) || ($district !== '' && $city === '') || ($category !== '' && $categoryRow === null) || ! $serviceOk) {
            throw new InvalidArgumentException('invalid');
        }
        if ($p->has('card') && ! in_array($p->get('card'), ['true', 'false'], true)) {
            throw new InvalidArgumentException('invalid');
        }
        $numeric = static function (string $key, float $min, float $max) use ($p): ?float {
            $raw = $p->get($key);
            if ($raw === null) {
                return null;
            }
            if (preg_match('/^-?\d+(?:\.\d+)?$/D', $raw) !== 1) {
                throw new InvalidArgumentException('invalid');
            }
            $v = (float) $raw;
            if (! is_finite($v) || $v < $min || $v > $max) {
                throw new InvalidArgumentException('invalid');
            }

            return $v;
        };
        $latitude = $numeric('lat', -90, 90);
        $longitude = $numeric('lng', -180, 180);
        $radius = $numeric('radius', 1, Taxonomy::maxNearbyRadius());
        if (($latitude === null) !== ($longitude === null) || ($radius !== null && $latitude === null)) {
            throw new InvalidArgumentException('invalid');
        }

        $sort = $p->get('sort') ?? 'recommended';
        $cursor = $p->get('cursor');
        if (! in_array($sort, self::SORTS, true) || ($sort === 'nearest' && $latitude === null)
            || ($p->has('verifiedOwner') && $p->get('verifiedOwner') !== 'true') || ($cursor !== null && (strlen($cursor) < 1 || strlen($cursor) > 512))) {
            throw new InvalidArgumentException('invalid');
        }

        return new self($q, $city, $district, $category, $service, $brand, $p->get('card') === 'true', $latitude !== null ? ['latitude' => $latitude, 'longitude' => (float) $longitude] : null, $radius, $v2, $sort, $cursor, $p->get('verifiedOwner') === 'true');
    }
}
