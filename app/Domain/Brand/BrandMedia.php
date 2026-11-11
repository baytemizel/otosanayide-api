<?php

namespace App\Domain\Brand;

use App\Domain\Content\Barrier;
use App\Domain\Content\Ids;
use App\Domain\Content\ImageCodec;
use App\Domain\Content\MediaStore;
use App\Domain\Payments\F7;
use App\Exceptions\HttpError;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * /api/admin/brand-media: marka gorseli ayri ozel diskte (BRAND_MEDIA_DISK), `brand-media/{id}.webp`.
 * Yukleme rezervasyonu + medya islem kaydi (MediaLedger) + ust kayit surum kontrolu.
 */
final class BrandMedia
{
    public static function enabled(): bool
    {
        $disk = config('ads.brand_media_disk');

        return F7::flag('brand_media') && is_string($disk) && $disk !== '';
    }

    /** brandMediaBucket(): ayri ozel depo; kamuya acik veya diger medya diskleriyle ortak olamaz. */
    public static function disk(): Filesystem
    {
        if (! self::enabled()) {
            throw new HttpError(503, 'Marka görseli yükleme henüz açılmadı.');
        }
        $name = (string) config('ads.brand_media_disk');
        if ($name === config('filesystems.default')) {
            throw new HttpError(503, 'Marka görselleri için ayrı özel depo gerekli.');
        }
        $config = config('filesystems.disks.'.$name);
        if (! is_array($config) || $name === 'public' || ($config['visibility'] ?? 'private') === 'public') {
            throw new HttpError(503, 'Fotoğraf deposunun gizlilik ayarları doğrulanamadı.');
        }

        return Storage::disk($name);
    }

    public static function path(string $id): string
    {
        return 'brand-media/'.$id.'.webp';
    }

    /** GET: butunluk dogrulanmis bayt + inceleme kaydi (onay icin 1 saat gecerli). */
    public static function view(string $uid, string $campaignId): string
    {
        $row = BrandStore::campaign($campaignId, false);
        if (! isset($row['asset'])) {
            throw new HttpError(404, 'Fotoğraf bulunamadı.');
        }
        $asset = BrandRules::asset()->parse((object) $row['asset']);
        $bytes = MediaStore::read(self::disk(), self::path($asset['id']));
        if (strlen($bytes) !== $asset['bytes'] || hash('sha256', $bytes) !== $asset['sha256']) {
            throw new HttpError(409, 'Fotoğraf bütünlüğü doğrulanamadı.');
        }
        Barrier::media($uid, ['kind' => 'brand_parent', 'id' => $campaignId], static function () use ($uid, $campaignId, $row, $asset): void {
            $current = BrandStore::campaign($campaignId);
            if (($current['version'] ?? null) !== $row['version'] || ($current['asset']['id'] ?? null) !== $asset['id']) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }
            DB::table('brand_media_views')->updateOrInsert(['campaign_id' => $campaignId, 'viewer_key' => hash('sha256', $uid)], ['user_id' => $uid, 'image_id' => $asset['id'], 'sha256' => $asset['sha256'], 'version' => $row['version'], 'viewed_at_ms' => F7::nowMs()]);
        });

        return $bytes;
    }

    /** @param \Closure():string $body sinirli govde okuyucu */
    public static function upload(string $uid, string $campaignId, int $version, \Closure $body): array
    {
        $disk = self::disk();
        $id = Ids::uuid();
        $nonce = Ids::uuid();
        $reserved = null;
        try {
            Barrier::media($uid, ['kind' => 'brand_parent', 'id' => $campaignId], static function () use ($uid, $campaignId, $version, $id, $nonce): void {
                $row = BrandStore::campaign($campaignId);
                if ($row === null || ! BrandRules::mayChangeImage($row, $version) || ($row['mediaAttempts'] ?? 0) >= 20) {
                    throw new HttpError(409, 'Görsel değişti veya yükleme sınırına ulaşıldı.');
                }
                DB::table('brand_campaigns')->where('id', $campaignId)->update(['media_attempts' => ($row['mediaAttempts'] ?? 0) + 1]);
                DB::table('brand_media_uploads')->insert(['id' => $id, 'campaign_id' => $campaignId, 'status' => 'uploading', 'created_at_ms' => F7::nowMs(), 'actor_id' => $uid, 'upload_nonce' => $nonce]);
            });
            $reserved = $id;
            try {
                $bytes = $body();
            } catch (Throwable) {
                throw new HttpError(413, 'İstek çok büyük.');
            }
            try {
                $image = ImageCodec::brand($bytes);
            } catch (Throwable) {
                throw new HttpError(400, 'Geçersiz görsel.');
            }
            MediaStore::save($disk, $uid, ['kind' => 'brand', 'id' => $id], self::path($id), $image['data']);
            $item = Barrier::media($uid, ['kind' => 'brand', 'id' => $id], static function () use ($uid, $campaignId, $version, $id, $image): array {
                $row = BrandStore::campaign($campaignId);
                if ($row === null || ! BrandRules::mayChangeImage($row, $version)) {
                    throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
                }
                $reservation = F7::lock('brand_media_uploads', 'id', $id);
                if ($reservation?->status !== 'uploading') {
                    throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
                }
                $now = F7::nowMs();
                $next = array_merge($row, ['asset' => ['id' => $id, 'sha256' => $image['sha256'], 'width' => $image['width'], 'height' => $image['height'], 'bytes' => $image['bytes']], 'status' => 'draft', 'note' => '', 'lastEditorId' => $uid, 'version' => $version + 1, 'updatedAtMs' => $now]);
                BrandStore::putCampaign($campaignId, $next, true);
                DB::table('brand_media_uploads')->where('id', $id)->update(['status' => 'attached', 'sha256' => $image['sha256'], 'bytes' => $image['bytes']]);
                BrandStore::history($campaignId, 'image_attached', $uid, $next);

                return BrandRules::view($campaignId, $next, $uid);
            });
            $reserved = null;

            return $item;
        } catch (Throwable $e) {
            if ($reserved !== null) {
                try {
                    $clean = Barrier::media(null, ['kind' => 'brand', 'id' => $reserved], static function () use ($reserved): bool {
                        $row = F7::lock('brand_media_uploads', 'id', $reserved);
                        if ($row === null || $row->status === 'attached') {
                            return false;
                        }
                        DB::table('brand_media_uploads')->where('id', $reserved)->update(['status' => 'failed']);

                        return true;
                    });
                    if ($clean) {
                        MediaStore::delete($disk, null, ['kind' => 'brand', 'id' => $reserved], self::path($reserved));
                    }
                } catch (Throwable) {
                    report(new RuntimeException('Brand media cleanup requires reconciliation'));
                }
            }
            throw $e;
        }
    }
}
