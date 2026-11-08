<?php

namespace App\Domain\Content;

use Normalizer;

/** content-moderation.ts inspectLanguage: yerel, sozluk tabanli kontrol. Orijinal metin degismez. */
final class Moderation
{
    public const POLICY_VERSION = 'tr-en-1';

    private const EQUIVALENTS = ['ı' => 'i', 'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x', 'у' => 'y', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's', 'α' => 'a', 'ο' => 'o', 'ι' => 'i', '@' => 'a', '4' => 'a', '0' => 'o', '1' => 'i', '!' => 'i', '3' => 'e', '5' => 's', '$' => 's', '7' => 't'];

    private const TERMS = [
        ['tr-profanity', 'amk', 'aq', 'siktir', 'siktirin', 'siktirgit', 'sikerim', 'sikeyim', 'sikik', 'yarrak', 'yarak', 'orospu', 'orospuçocuğu', 'oc', 'piç', 'göt', 'götveren', 'ibne', 'pezevenk'],
        ['tr-insult', 'salak', 'aptal', 'gerizekalı', 'şerefsiz'],
        ['en-profanity', 'fuck', 'fucking', 'fucker', 'motherfucker', 'shit', 'bullshit', 'bitch', 'asshole', 'bastard', 'cunt', 'dickhead'],
        ['en-insult', 'idiot', 'moron'],
    ];

    /** text.slice(0,6000): UTF-16 birimiyle. */
    private static function slice(string $text): string
    {
        if (strlen($text) <= 6000) {
            return $text;
        }
        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return strlen($utf16) <= 12000 ? $text : (string) mb_convert_encoding(substr($utf16, 0, 12000), 'UTF-8', 'UTF-16LE');
    }

    public static function normalize(string $text): string
    {
        $nfkc = (string) Normalizer::normalize(self::slice($text), Normalizer::FORM_KC);
        $lower = mb_strtolower(strtr($nfkc, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
        $nfd = (string) Normalizer::normalize($lower, Normalizer::FORM_D);
        $plain = (string) preg_replace('/\p{M}|\p{Cf}/u', '', $nfd);
        $mapped = strtr($plain, self::EQUIVALENTS);

        return (string) preg_replace('/([a-z])\1{2,}/', '$1$1', $mapped);
    }

    /**
     * @param  list<string>  $extraTerms
     * @return array{policyVersion:string,flagged:bool,signals:list<string>,obfuscation:bool}
     */
    public static function inspect(string $text, array $extraTerms = []): array
    {
        $variants = [self::normalize($text), self::normalize((string) preg_replace('/[@!$]/', ' ', $text))];
        $signals = [];
        $groups = self::TERMS;
        $groups[] = ['custom', ...array_slice($extraTerms, 0, 100)];
        foreach ($groups as $words) {
            $group = array_shift($words);
            foreach ($words as $word) {
                $key = (string) preg_replace('/(.)\1+/', '$1', (string) preg_replace('/[^a-z]/', '', self::normalize($word)));
                if (strlen($key) < 2 || strlen($key) > 30) {
                    continue;
                }
                // Harfler arasinda en fazla 8 noktalama; kelime sinirlari korunur.
                $pattern = implode('[^a-z0-9]{0,8}', array_map(static fn (string $c): string => $c.'{1,2}', str_split($key)));
                $regex = '/(?:^|[^a-z0-9])'.$pattern.'(?=$|[^a-z0-9])/uD';
                foreach ($variants as $normalized) {
                    if (preg_match($regex, $normalized) === 1) {
                        $signals[$group] = true;
                        break 2;
                    }
                }
            }
        }
        $obfuscation = preg_match('/\p{Cf}|[а-ео-рсхуіјѕαοι]/u', $text) === 1
            || preg_match('/(?:[a-zçğıöşü][^\p{L}\p{N}]{1,8}){3,}[a-zçğıöşü]/iu', $text) === 1;

        return ['policyVersion' => self::POLICY_VERSION, 'flagged' => $signals !== [], 'signals' => array_keys($signals), 'obfuscation' => $obfuscation];
    }

    /** lib/moderation.ts localModeration */
    public static function local(string $text): array
    {
        $extra = config('otosanayide.moderation_extra_terms');

        return self::inspect($text, is_string($extra) && $extra !== '' ? array_values(array_filter(explode(',', $extra), static fn (string $s): bool => $s !== '')) : []);
    }

    /** Sutunlar: automatic.{local, ai:{status:'not_requested'}} */
    public static function columns(string $text): array
    {
        $m = self::local($text);

        return [
            'local_policy_version' => $m['policyVersion'],
            'local_flagged' => $m['flagged'],
            'local_obfuscation' => $m['obfuscation'],
            'local_signals' => json_encode($m['signals']),
            'ai_status' => 'not_requested',
            'ai_flagged' => null,
            'ai_categories' => null,
            'ai_model' => null,
            'ai_run_id' => null,
            'ai_requested_at_ms' => null,
            'ai_checked_at' => null,
        ];
    }
}
