<?php

namespace App\Domain\Content;

use GdImage;
use RuntimeException;

/**
 * sharp ile yapilan yeniden kodlamanin GD karsiligi: yalniz JPEG/PNG/WebP tek kare,
 * boyut/piksel siniri dosya basligindan okunur, cozumlemeden once uygulanir, EXIF yeni dosyaya tasinmaz.
 * Her hata RuntimeException: cagiran kaynaktaki tek kullanici mesajina cevirir.
 */
final class ImageCodec
{
    /** @return array{data:string,thumbnail:string,width:int,height:int,sha256:string,thumbnailSha256:string} */
    public static function reviewPhoto(string $input): array
    {
        return self::photo($input, IMG_WEBP_LOSSLESS);
    }

    /** @return array{data:string,thumbnail:string,width:int,height:int,sha256:string,thumbnailSha256:string} */
    public static function businessPhoto(string $input): array
    {
        return self::photo($input, 94);
    }

    /** @return array{data:string,width:int,height:int,sha256:string} */
    public static function evidence(string $input): array
    {
        $image = self::canonical($input, 4 * 1024 * 1024, 16000000, 2400);
        $data = self::webp($image, IMG_WEBP_LOSSLESS);
        if (strlen($data) > 4 * 1024 * 1024) {
            throw new RuntimeException('Belge okunabilir kalitede 4 MB sınırını aşıyor. Sayfayı yeniden çek.');
        }

        return ['data' => $data, 'width' => imagesx($image), 'height' => imagesy($image), 'sha256' => hash('sha256', $data)];
    }

    /** @return array{data:string,thumbnail:string,width:int,height:int,sha256:string,thumbnailSha256:string} */
    private static function photo(string $input, int $quality): array
    {
        $image = self::canonical($input, 2500000, 24000000, 1600);
        $data = self::webp($image, $quality);
        if (strlen($data) > 2500000) {
            throw new RuntimeException('Fotoğraf boyut sınırını aşıyor.');
        }
        $thumb = self::fit($image, 320);
        $thumbnail = self::webp($thumb, 80);

        return ['data' => $data, 'thumbnail' => $thumbnail, 'width' => imagesx($image), 'height' => imagesy($image), 'sha256' => hash('sha256', $data), 'thumbnailSha256' => hash('sha256', $thumbnail)];
    }

    private static function canonical(string $input, int $maxBytes, int $maxPixels, int $edge): GdImage
    {
        if ($input === '' || strlen($input) > $maxBytes) {
            throw new RuntimeException('size');
        }
        $header = self::header($input);
        if ($header['width'] < 1 || $header['height'] < 1 || $header['width'] * $header['height'] > $maxPixels) {
            throw new RuntimeException('pixels');
        }
        $image = self::decode($input, $header);

        return self::fit($image, $edge);
    }

    private static function decode(string $input, array $header): GdImage
    {
        $oriented = $header['orientation'] !== 1;
        $pixels = $header['width'] * $header['height'];
        self::reserve($pixels, $oriented);
        $iniJpeg = ini_get('gd.jpeg_ignore_warning');
        ini_set('gd.jpeg_ignore_warning', '0');
        $warnings = false;
        set_error_handler(static function () use (&$warnings): bool {
            $warnings = true;

            return true;
        });
        try {
            $image = imagecreatefromstring($input);
        } finally {
            restore_error_handler();
            ini_set('gd.jpeg_ignore_warning', $iniJpeg === false ? '1' : $iniJpeg);
        }
        if ($image === false || $warnings) {
            throw new RuntimeException('decode');
        }
        if (imagesx($image) !== $header['width'] || imagesy($image) !== $header['height']) {
            throw new RuntimeException('dimensions');
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $oriented ? self::orient($image, $header['orientation']) : $image;
    }

    /** GD 4 bayt/piksel tutar; yakalanamaz bellek hatasi yerine limit bu istek icin yukseltilir (istek sonunda sifirlanir). */
    private static function reserve(int $pixels, bool $oriented): void
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return;
        }
        $bytes = self::shorthand($limit);
        $needed = memory_get_usage() + (int) ($pixels * ($oriented ? 9 : 5)) + 67108864;
        if ($needed > $bytes && ini_set('memory_limit', (string) $needed) === false) {
            throw new RuntimeException('memory');
        }
    }

    private static function shorthand(string $value): int
    {
        $n = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function orient(GdImage $image, int $orientation): GdImage
    {
        $white = 0x7F000000;
        $out = match ($orientation) {
            3 => imagerotate($image, 180, $white),
            6, 5, 7 => imagerotate($image, 270, $white),
            8 => imagerotate($image, 90, $white),
            default => $image,
        };
        if ($out === false) {
            throw new RuntimeException('orient');
        }
        if ($orientation === 2 || $orientation === 5) {
            imageflip($out, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4 || $orientation === 7) {
            imageflip($out, IMG_FLIP_VERTICAL);
        }

        return $out;
    }

    /** fit:'inside', withoutEnlargement + beyaz zemin (flatten). */
    private static function fit(GdImage $source, int $edge): GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, $edge / $w, $edge / $h);
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($tw, $th);
        if ($out === false) {
            throw new RuntimeException('canvas');
        }
        imagealphablending($out, true);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagealphablending($source, true);
        if (imagecopyresampled($out, $source, 0, 0, 0, 0, $tw, $th, $w, $h) !== true) {
            throw new RuntimeException('resample');
        }

        return $out;
    }

    private static function webp(GdImage $image, int $quality): string
    {
        ob_start();
        $ok = imagewebp($image, null, $quality);
        $data = ob_get_clean();
        if ($ok !== true || ! is_string($data) || $data === '') {
            throw new RuntimeException('encode');
        }

        return $data;
    }

    /**
     * rasterDimensions + yon + kare: tum kontroller yalniz bastan okur.
     *
     * @return array{format:string,width:int,height:int,orientation:int}
     */
    public static function header(string $b): array
    {
        $n = strlen($b);
        if ($n >= 24 && $b[0] === "\x89" && substr($b, 1, 3) === 'PNG' && substr($b, 12, 4) === 'IHDR') {
            return ['format' => 'png', 'width' => self::u32($b, 16), 'height' => self::u32($b, 20), 'orientation' => self::pngOrientation($b)];
        }
        if ($n >= 30 && substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
            return self::webpHeader($b);
        }
        if ($n >= 4 && $b[0] === "\xFF" && $b[1] === "\xD8") {
            return self::jpegHeader($b);
        }
        throw new RuntimeException('format');
    }

    /** @return array{format:string,width:int,height:int,orientation:int} */
    private static function webpHeader(string $b): array
    {
        $format = substr($b, 12, 4);
        if ($format === 'VP8X') {
            if ((ord($b[20]) & 2) !== 0) {
                throw new RuntimeException('frames');
            }
            $w = 1 + ord($b[24]) + (ord($b[25]) << 8) + (ord($b[26]) << 16);
            $h = 1 + ord($b[27]) + (ord($b[28]) << 8) + (ord($b[29]) << 16);

            return ['format' => 'webp', 'width' => $w, 'height' => $h, 'orientation' => self::webpOrientation($b)];
        }
        if ($format === 'VP8L' && ord($b[20]) === 47) {
            return ['format' => 'webp', 'width' => 1 + ord($b[21]) + ((ord($b[22]) & 63) << 8), 'height' => 1 + (ord($b[22]) >> 6) + (ord($b[23]) << 2) + ((ord($b[24]) & 15) << 10), 'orientation' => 1];
        }
        if ($format === 'VP8 ' && ord($b[23]) === 157 && ord($b[24]) === 1 && ord($b[25]) === 42) {
            return ['format' => 'webp', 'width' => (ord($b[26]) | (ord($b[27]) << 8)) & 16383, 'height' => (ord($b[28]) | (ord($b[29]) << 8)) & 16383, 'orientation' => 1];
        }
        throw new RuntimeException('format');
    }

    /** @return array{format:string,width:int,height:int,orientation:int} */
    private static function jpegHeader(string $b): array
    {
        $n = strlen($b);
        $offset = 2;
        $orientation = 1;
        while ($offset + 4 <= $n) {
            if ($b[$offset++] !== "\xFF") {
                break;
            }
            while ($offset < $n && $b[$offset] === "\xFF") {
                $offset++;
            }
            if ($offset >= $n) {
                break;
            }
            $marker = ord($b[$offset++]);
            if ($marker === 0 || $marker === 217 || $marker === 218) {
                break;
            }
            if ($marker === 1 || ($marker >= 208 && $marker <= 215)) {
                continue;
            }
            if ($offset + 2 > $n) {
                break;
            }
            $length = self::u16($b, $offset);
            if ($length < 2 || $offset + $length > $n) {
                break;
            }
            if ($marker === 0xE1 && $orientation === 1 && substr($b, $offset + 2, 6) === "Exif\0\0") {
                $orientation = self::tiffOrientation(substr($b, $offset + 8, $length - 8));
            }
            if (in_array($marker, [192, 193, 194, 195, 197, 198, 199, 201, 202, 203, 205, 206, 207], true) && $length >= 8) {
                return ['format' => 'jpeg', 'width' => self::u16($b, $offset + 5), 'height' => self::u16($b, $offset + 3), 'orientation' => $orientation];
            }
            $offset += $length;
        }
        throw new RuntimeException('format');
    }

    private static function pngOrientation(string $b): int
    {
        $offset = 8;
        $n = strlen($b);
        while ($offset + 12 <= $n) {
            $length = self::u32($b, $offset);
            $type = substr($b, $offset + 4, 4);
            if ($type === 'eXIf' && $offset + 8 + $length <= $n) {
                return self::tiffOrientation(substr($b, $offset + 8, $length));
            }
            if ($type === 'IEND') {
                break;
            }
            $offset += 12 + $length;
        }

        return 1;
    }

    private static function webpOrientation(string $b): int
    {
        $offset = 12;
        $n = strlen($b);
        while ($offset + 8 <= $n) {
            $id = substr($b, $offset, 4);
            $size = self::u32le($b, $offset + 4);
            if ($id === 'EXIF' && $offset + 8 + $size <= $n) {
                $tiff = substr($b, $offset + 8, $size);

                return self::tiffOrientation(str_starts_with($tiff, "Exif\0\0") ? substr($tiff, 6) : $tiff);
            }
            $offset += 8 + $size + ($size & 1);
        }

        return 1;
    }

    private static function tiffOrientation(string $t): int
    {
        if (strlen($t) < 14) {
            return 1;
        }
        $little = substr($t, 0, 2) === 'II';
        if (! $little && substr($t, 0, 2) !== 'MM') {
            return 1;
        }
        $u16 = static fn (int $o): int => $little ? ord($t[$o]) | (ord($t[$o + 1]) << 8) : (ord($t[$o]) << 8) | ord($t[$o + 1]);
        $u32 = static fn (int $o): int => $little
            ? ord($t[$o]) | (ord($t[$o + 1]) << 8) | (ord($t[$o + 2]) << 16) | (ord($t[$o + 3]) << 24)
            : (ord($t[$o]) << 24) | (ord($t[$o + 1]) << 16) | (ord($t[$o + 2]) << 8) | ord($t[$o + 3]);
        $ifd = $u32(4);
        if ($ifd + 2 > strlen($t)) {
            return 1;
        }
        $count = min($u16($ifd), 512);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($t)) {
                return 1;
            }
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    private static function u16(string $b, int $o): int
    {
        return (ord($b[$o]) << 8) | ord($b[$o + 1]);
    }

    private static function u32(string $b, int $o): int
    {
        return (ord($b[$o]) << 24) | (ord($b[$o + 1]) << 16) | (ord($b[$o + 2]) << 8) | ord($b[$o + 3]);
    }

    private static function u32le(string $b, int $o): int
    {
        return ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24);
    }
}
