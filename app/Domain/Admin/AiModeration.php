<?php

namespace App\Domain\Admin;

use App\Domain\Content\Moderation;
use App\Support\Z;
use Illuminate\Support\Facades\Http;
use Throwable;

/** content-moderation.ts checkAiModeration: her hata `unavailable`, asla "temiz" degil. */
final class AiModeration
{
    /** @return array{status:string,flagged?:bool,categories?:list<string>,model?:string} */
    public static function check(string $text, ?string $apiKey): array
    {
        if ($apiKey === null || $apiKey === '') {
            return ['status' => 'unavailable'];
        }
        try {
            $input = [$text, Moderation::normalize($text)];
            $response = Http::timeout(8)->connectTimeout(8)->withoutRedirecting()->withToken($apiKey)->withOptions(['stream' => true])->asJson()
                ->post('https://api.openai.com/v1/moderations', ['model' => 'omni-moderation-latest', 'input' => $input]);
            if (! $response->successful()) {
                return ['status' => 'unavailable'];
            }
            $body = $response->toPsrResponse()->getBody();
            $raw = '';
            while (! $body->eof()) {
                $raw .= $body->read(8192);
                if (strlen($raw) > 128000) {
                    return ['status' => 'unavailable'];
                }
            }
            $value = Z::object([
                'model' => Z::string()->min(1)->max(120),
                'results' => Z::array(Z::object(['flagged' => Z::boolean(), 'categories' => Z::record(Z::string(), Z::boolean())]))->min(1)->max(2),
            ])->parse(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
            if (count($value['results']) !== count($input)) {
                return ['status' => 'unavailable'];
            }
            $flagged = false;
            $categories = [];
            foreach ($value['results'] as $r) {
                $flagged = $flagged || $r['flagged'];
                foreach ($r['categories'] as $k => $v) {
                    if ($v) {
                        $categories[(string) $k] = true;
                    }
                }
            }

            return ['status' => 'checked', 'model' => $value['model'], 'flagged' => $flagged, 'categories' => array_keys($categories)];
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
