<?php

namespace App\Domain\Content;

use App\Exceptions\HttpError;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Firebase Storage kovalarinin karsiligi: yapilandirilmis ozel disk.
 * Kaynaktaki Public Access Prevention / uniform erisim denetimi burada disk ayariyla sinirlidir;
 * nesne generation/nonce on kosullari yerine rezervasyon satiri ve islem kaydi kullanilir.
 */
final class MediaStore
{
    public const REVIEW = ['flag' => 'review_photos', 'closed' => 'Yorum fotoğrafı yükleme henüz açılmadı.', 'privacy' => 'Fotoğraf deposunun gizlilik ayarları doğrulanamadı.'];

    public const BUSINESS = ['flag' => 'business_photos', 'closed' => 'İşletme fotoğrafı yükleme henüz açılmadı.', 'privacy' => 'Fotoğraf deposunun gizlilik ayarları doğrulanamadı.'];

    public const EVIDENCE = ['flag' => 'claim_evidence', 'closed' => 'Güvenli belge yükleme henüz açılmadı.', 'privacy' => 'Belge deposunun gizlilik yapılandırması doğrulanamadı.'];

    /** @param array{flag:string,closed:string,privacy:string} $kind */
    public static function enabled(array $kind): bool
    {
        $disk = config('filesystems.default');

        return config('otosanayide.flags.'.$kind['flag']) === true && is_string($disk) && $disk !== '';
    }

    /** @param array{flag:string,closed:string,privacy:string} $kind */
    public static function disk(array $kind): Filesystem
    {
        if (! self::enabled($kind)) {
            throw new HttpError(503, $kind['closed']);
        }
        $name = (string) config('filesystems.default');
        $config = config('filesystems.disks.'.$name);
        if (! is_array($config) || $name === 'public' || ($config['visibility'] ?? 'private') === 'public') {
            throw new HttpError(503, $kind['privacy']);
        }

        return Storage::disk($name);
    }

    public static function reviewPath(string $id, bool $thumb = false): string
    {
        return 'review-photos/'.$id.'/'.($thumb ? 'thumbnail' : 'full').'.webp';
    }

    public static function businessPath(string $id, bool $thumb = false): string
    {
        return 'business-photos/'.$id.'/'.($thumb ? 'thumbnail' : 'full').'.webp';
    }

    public static function evidencePath(string $claimId, string $id): string
    {
        return 'claim-evidence/'.$claimId.'/'.$id.'.webp';
    }

    /**
     * bucket.file(path).save(): uzerine yazmaz, en fazla 2621440 bayt, nonce sart.
     *
     * @param  array{kind:string,id:string,claimId?:string}  $resource
     */
    public static function save(Filesystem $disk, ?string $actor, array $resource, string $path, string $data): void
    {
        MediaLedger::mutate($actor, $resource, $path, 'save', static function (array $op) use ($disk, $path, $data): \Closure {
            if (($op['binding']['nonce'] ?? null) === null || $data === '' || strlen($data) > 2621440) {
                throw new RuntimeException('media_save_contract');
            }

            return static function () use ($disk, $path, $data): void {
                if ($disk->exists($path)) {
                    throw new RuntimeException('media_generation_changed');
                }
                if ($disk->put($path, $data) !== true) {
                    throw new RuntimeException('media_save_failed');
                }
            };
        });
    }

    /**
     * bucket.file(path).delete({ignoreNotFound:true}): yoksa no-op, sonuc belirsizse islem kaydi kapanmaz.
     *
     * @param  array{kind:string,id:string,claimId?:string}  $resource
     */
    public static function delete(Filesystem $disk, ?string $actor, array $resource, string $path): void
    {
        MediaLedger::mutate($actor, $resource, $path, 'delete', static function () use ($disk, $path): \Closure {
            return static function () use ($disk, $path): void {
                if ($disk->exists($path) && $disk->delete($path) !== true) {
                    throw new RuntimeException('media_delete_failed');
                }
            };
        });
    }

    /** bucket.file(path).download() */
    public static function read(Filesystem $disk, string $path): string
    {
        $bytes = $disk->get($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('media_object_missing');
        }

        return $bytes;
    }

    /** Boyutu indirmeden; yoksa veya okunamazsa hata. */
    public static function size(Filesystem $disk, string $path): int
    {
        try {
            return (int) $disk->size($path);
        } catch (Throwable) {
            throw new RuntimeException('media_object_missing');
        }
    }
}
