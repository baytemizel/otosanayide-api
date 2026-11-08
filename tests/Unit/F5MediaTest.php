<?php

use App\Domain\Content\ImageCodec;
use App\Domain\Content\Places;
use App\Exceptions\HttpError;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

// Kodlayici: review-photos.test.ts, claim-evidence.test.ts, business-photos.test.ts; yalniz GD.

function f5jpeg(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 210, 122, 56));
    ob_start();
    imagejpeg($im, null, 90);

    return ob_get_clean();
}

/** JPEG'e Orientation etiketli APP1 ekler. */
function f5exif(string $jpeg, int $orientation): string
{
    $tiff = "MM\0*\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0".chr($orientation)."\0\0\0\0\0\0";
    $app1 = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function f5webpSize(string $webp): array
{
    $info = getimagesizefromstring($webp);

    return [$info[0], $info[1]];
}

it('resizes, strips metadata and makes a bounded thumbnail for review photos', function () {
    $r = ImageCodec::reviewPhoto(f5exif(f5jpeg(2200, 1200), 1));
    expect($r['width'])->toBe(1600)->and($r['height'])->toBe(873)->and(strlen($r['thumbnail']))->toBeLessThan(200000);
    expect(f5webpSize($r['data']))->toBe([1600, 873]);
    expect(f5webpSize($r['thumbnail'])[0])->toBeLessThanOrEqual(320);
    expect(strlen($r['sha256']))->toBe(64)->and($r['sha256'])->toBe(hash('sha256', $r['data']))->and($r['thumbnailSha256'])->toBe(hash('sha256', $r['thumbnail']));
    expect(substr($r['data'], 0, 4))->toBe('RIFF')->and(str_contains($r['data'], 'EXIF'))->toBeFalse()->and(str_contains($r['data'], 'Exif'))->toBeFalse();
});

it('does not enlarge small images', function () {
    $r = ImageCodec::businessPhoto(f5jpeg(40, 30));
    expect([$r['width'], $r['height']])->toBe([40, 30])->and(f5webpSize($r['data']))->toBe([40, 30]);
    expect($r['sha256'])->not->toBe($r['thumbnailSha256']);
});

it('applies EXIF orientation and re-encodes evidence', function () {
    $r = ImageCodec::evidence(f5exif(f5jpeg(120, 80), 6));
    expect([$r['width'], $r['height']])->toBe([80, 120])->and(f5webpSize($r['data']))->toBe([80, 120])->and(strlen($r['sha256']))->toBe(64);
});

it('flattens transparency on white', function () {
    $im = imagecreatetruecolor(4, 4);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    $r = ImageCodec::reviewPhoto($png);
    $back = imagecreatefromstring($r['data']);
    expect(imagecolorat($back, 1, 1) & 0xFFFFFF)->toBe(0xFFFFFF);
});

it('rejects non raster, invalid and oversized input before decoding', function (string $input) {
    expect(fn () => ImageCodec::reviewPhoto($input))->toThrow(RuntimeException::class);
})->with([
    'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>',
    'bytes' => "\x01\x02\x03",
    'pdf' => '%PDF-1.7',
    'gif' => "GIF89a\x01\0\x01\0\0\0\0",
    'oversized' => str_repeat('a', 2500001),
    'empty' => '',
]);

it('enforces pixel limits from the header', function () {
    // 5000x5000 PNG basligi (25 MP > 24 MP): cozumlemeye gecmez.
    $header = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR".pack('N', 5000).pack('N', 5000)."\x08\x02\0\0\0\0\0\0\0";
    expect(fn () => ImageCodec::reviewPhoto($header))->toThrow(RuntimeException::class, 'pixels');
    // 4000x4000 = 16 MP sinirda gecer, 4001x4000 evidence'ta reddedilir.
    $header2 = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR".pack('N', 4001).pack('N', 4000)."\x08\x02\0\0\0\0\0\0\0";
    expect(fn () => ImageCodec::evidence($header2))->toThrow(RuntimeException::class, 'pixels');
});

it('rejects animated webp from the VP8X flag and truncated data', function () {
    $animated = 'RIFF'.pack('V', 30).'WEBPVP8X'.pack('V', 10)."\x02\0\0\0".str_repeat("\0", 6).str_repeat("\0", 10);
    expect(fn () => ImageCodec::reviewPhoto($animated))->toThrow(RuntimeException::class, 'frames');
    $truncated = substr(f5jpeg(200, 200), 0, 300);
    expect(fn () => ImageCodec::reviewPhoto($truncated))->toThrow(RuntimeException::class);
});

it('reads webp and png dimensions from headers', function () {
    $im = imagecreatetruecolor(32, 18);
    ob_start();
    imagewebp($im, null, 80);
    $webp = ob_get_clean();
    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    expect(ImageCodec::header($webp))->toMatchArray(['format' => 'webp', 'width' => 32, 'height' => 18]);
    expect(ImageCodec::header($png))->toMatchArray(['format' => 'png', 'width' => 32, 'height' => 18]);
    expect(ImageCodec::header(f5jpeg(16, 9)))->toMatchArray(['format' => 'jpeg', 'width' => 16, 'height' => 9, 'orientation' => 1]);
    expect(ImageCodec::header(f5exif(f5jpeg(16, 9), 8))['orientation'])->toBe(8);
});

// business-places.test.ts

function f5point(array $over = []): array
{
    return $over + ['id' => 'place_1', 'displayName' => ['text' => 'TEST Service'], 'formattedAddress' => 'TEST business address in İstanbul', 'location' => ['latitude' => 41, 'longitude' => 29], 'addressComponents' => [['shortText' => 'TR', 'types' => ['country']]]];
}

it('uses the fixed endpoint and minimum field mask and stays a candidate', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['places' => [f5point()]])]);
    $result = Places::google('TEST address', 'tr', 'synthetic-key');
    expect($result['verification'])->toBe('candidate')->and($result['places'][0]['latitude'])->toBe(41)->and($result)->not->toHaveKey('apiKey');
    Http::assertSent(function (ClientRequest $r) {
        return $r->url() === 'https://places.googleapis.com/v1/places:searchText'
            && $r->hasHeader('X-Goog-Api-Key', 'synthetic-key')
            && $r->hasHeader('X-Goog-FieldMask', 'places.id,places.displayName,places.formattedAddress,places.location,places.addressComponents')
            && $r['pageSize'] === 5 && $r['regionCode'] === 'TR' && $r['languageCode'] === 'tr' && $r['textQuery'] === 'TEST address';
    });
});

it('filters foreign countries and out-of-scope coordinates', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['places' => [f5point(['addressComponents' => [['shortText' => 'GR', 'types' => ['country']]]]), f5point(['location' => ['latitude' => 60, 'longitude' => 29]])]])]);
    expect(Places::google('TEST', 'en', 'key')['places'])->toBe([]);
});

it('never turns provider errors or malformed bodies into results', function () {
    Http::fake(['places.googleapis.com/*' => Http::response('PRIVATE PROVIDER DIAGNOSTIC', 403)]);
    try {
        Places::google('TEST', 'tr', 'key');
        expect(true)->toBeFalse();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('PRIVATE')->and($e)->not->toBeInstanceOf(HttpError::class);
    }
});

it('surfaces a malformed provider body as a Zod failure', function () {
    Http::fake(['places.googleapis.com/*' => Http::response('{"places":[{"id":""}]}', 200, ['Content-Type' => 'application/json'])]);
    expect(fn () => Places::google('TEST', 'tr', 'key'))->toThrow(App\Support\ZodError::class);
});
