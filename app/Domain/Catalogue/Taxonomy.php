<?php

namespace App\Domain\Catalogue;

use Illuminate\Support\Facades\File;
use Normalizer;

/** packages/core/index.ts ve coverage.ts referans verisi ile metin kurallari. */
final class Taxonomy
{
    /** @var array{categories:list<array{id:string,name:string,services:list<string>}>,aliases:array<string,string>,englishExtra:array<string,string>}|null */
    private static ?array $data = null;

    /** @var array<string,mixed>|null */
    private static ?array $coverage = null;

    /** @var array{0:array<string,string>,1:string}|null */
    private static ?array $english = null;

    /** @return array{categories:list<array{id:string,name:string,services:list<string>}>,aliases:array<string,string>,englishExtra:array<string,string>} */
    private static function data(): array
    {
        return self::$data ??= File::json(resource_path('data/catalogue.json'));
    }

    /** @return array<string,mixed> */
    private static function coverage(): array
    {
        return self::$coverage ??= File::json(resource_path('data/coverage.json'));
    }

    /** @return list<string> */
    public static function cities(): array
    {
        return self::coverage()['cities'];
    }

    /** @return array{id:string,name:string,services:list<string>}|null */
    public static function category(string $id): ?array
    {
        foreach (self::data()['categories'] as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }

        return null;
    }

    public static function maxNearbyRadius(): int
    {
        return 200;
    }

    /** toLocaleLowerCase('tr-TR') + i, aksan ve noktalama sadelestirme. */
    public static function normalize(string $value): string
    {
        $lower = mb_strtolower(strtr($value, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
        $nfd = (string) Normalizer::normalize(str_replace('ı', 'i', $lower), Normalizer::FORM_D);
        $plain = (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $nfd);
        $ascii = (string) preg_replace('/[^a-z0-9\s]/u', ' ', $plain);

        return trim((string) preg_replace('/\s+/u', ' ', $ascii));
    }

    /** @return list<string> */
    public static function queryTerms(string $q, bool $english = true): array
    {
        $normalized = self::normalize($q);
        if ($english && $normalized !== '') {
            [$labels, $pattern] = self::english();
            $normalized = (string) preg_replace_callback($pattern, static fn (array $m): string => $labels[$m[0]], $normalized);
        }
        $aliases = self::data()['aliases'];
        $terms = [];
        foreach (explode(' ', $normalized) as $term) {
            $mapped = array_key_exists($term, $aliases) ? $aliases[$term] : $term;
            if ($mapped !== '') {
                $terms[] = $mapped;
            }
        }

        return $terms;
    }

    /** @return array{0:array<string,string>,1:string} */
    private static function english(): array
    {
        if (self::$english !== null) {
            return self::$english;
        }
        $translations = File::json(lang_path('en.json'));
        $labels = [];
        foreach (self::data()['categories'] as $category) {
            foreach ([$category['name'], ...$category['services']] as $label) {
                $labels[self::normalize($translations[$label] ?? $label)] = self::normalize($label);
            }
        }
        $labels = array_replace($labels, self::data()['englishExtra']);
        $keys = array_map('strval', array_keys($labels));
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::$english = [$labels, '/\b(?:'.implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), $keys)).')\b/'];
    }

    /**
     * @param  array{latitude:float|int,longitude:float|int}  $a
     * @param  array{latitude:float|int,longitude:float|int}  $b
     */
    public static function distanceKm(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $x = sin(($b['latitude'] - $a['latitude']) * $rad / 2) ** 2
            + cos($a['latitude'] * $rad) * cos($b['latitude'] * $rad) * sin(($b['longitude'] - $a['longitude']) * $rad / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($x), sqrt(1 - $x));
    }
}
