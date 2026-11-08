<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

/**
 * Kaynaktaki translate(): Turkce metin anahtardir, `{x}` yer tutuculari korunur.
 * Laravel'in `__()` yardimcisi kullanilmaz: nokta iceren anahtarlari grup.anahtar sanar.
 */
final class I18n
{
    /** @var array<string,string>|null */
    private static ?array $english = null;

    /** @param array<string,string|int|float> $params */
    public static function t(string $source, array $params = [], ?string $locale = null): string
    {
        $locale ??= App::getLocale();
        $key = Js::trim($source);
        $translated = $key;
        if ($locale === 'en') {
            self::$english ??= File::json(lang_path('en.json'));
            $translated = self::$english[$key] ?? $key;
        }
        preg_match('/^\s*/u', $source, $start);
        preg_match('/\s*$/u', $source, $end);
        $padded = ($start[0] ?? '').$translated.($end[0] ?? '');

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0],
            $padded,
        );
    }

    /** Once otosanayide-locale cerezi, sonra Accept-Language; yoksa tr. */
    public static function resolve(?string $cookie, ?string $acceptLanguage): string
    {
        if ($cookie === 'tr' || $cookie === 'en') {
            return $cookie;
        }
        $best = null;
        $bestQ = 0.0;
        foreach (explode(',', (string) $acceptLanguage) as $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            $base = explode('-', $tag)[0];
            if ($base !== 'tr' && $base !== 'en') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q=([0-9.]+)\s*$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > $bestQ) {
                $bestQ = $q;
                $best = $base;
            }
        }

        return $best ?? 'tr';
    }
}
