<?php

namespace App\Support;

use App\Exceptions\HttpError;
use Illuminate\Http\Request;

/** Kaynaktaki boundedBytes()/body(): sinir asilinca okumayi birakir, dogrulama sirasi handler'da kalir. */
final class Body
{
    public const DEFAULT_LIMIT = 20000;

    public static function bytes(Request $request, int $limit): string
    {
        $length = $request->headers->get('Content-Length');
        if ($length !== null && $length !== '' && (float) $length > $limit) {
            throw new HttpError(413, 'İstek çok büyük.');
        }
        $stream = $request->getContent(true);
        $raw = stream_get_contents($stream, $limit + 1);
        if ($raw === false) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        if (strlen($raw) > $limit) {
            throw new HttpError(413, 'İstek çok büyük.');
        }

        return $raw;
    }

    /** JSON.parse karsiligi: nesneler stdClass kalir, {} ile [] ayrimi kaybolmaz. */
    public static function json(Request $request, int $limit = self::DEFAULT_LIMIT): mixed
    {
        return self::decode(self::bytes($request, $limit));
    }

    public static function decode(string $raw): mixed
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3); // TextDecoder BOM'u atar.
        }
        try {
            return json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
    }
}
