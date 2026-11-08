<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Domain\Auth\ActorResolver;
use App\Domain\Catalogue\SearchProjection;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\RateLimit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** lib/business-photo-server.ts: owner / public / media modlari (admin F-sonraki fazda). */
final class BusinessPhotos
{
    private const DAY = 86400000;

    public static function user(Request $req): Actor
    {
        $u = ActorResolver::resolve($req);
        if (! $u->emailVerified) {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    private static function owned(string $uid, string $bid): array
    {
        $b = Docs::business(Rows::business($bid));
        $m = Docs::membership(Rows::member($uid, $bid));
        if (! Policy::businessPhotoOwner($uid, $bid, $b, $m)) {
            throw new HttpError(403, 'İşletme fotoğrafları için sahip yetkisi gerekli.');
        }

        return $b;
    }

    public static function project(string $id, array $p, bool $canCover = false, bool $published = false, bool $canOpen = false): array
    {
        return Schemas::businessPhotoItem()->parse((object) ['id' => $id, 'canOpen' => $canOpen, 'canCover' => $canCover, 'public' => $published, 'status' => $p['status'] ?? null, 'version' => $p['version'] ?? null, 'width' => $p['width'] ?? 0, 'height' => $p['height'] ?? 0, 'bytes' => $p['bytes'] ?? 0, 'reason' => $p['reason'] ?? '', 'expiresAtMs' => $p['expiresAtMs'] ?? null]);
    }

    /** @return array<string,mixed> */
    public static function list(Actor $u, string $bid): array
    {
        $b = self::owned($u->uid, $bid);
        $ids = Policy::businessPhotoIds($b['businessPhotoSlots']);
        $now = Ids::nowMs();
        $items = [];
        $rows = $ids === [] ? collect() : DB::table('business_photos')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null || $row->business_id !== $bid || ! in_array($row->status, ['uploading', 'pending', 'approved', 'rejected', 'hidden', 'failed'], true)) {
                continue;
            }
            $p = Docs::businessPhoto($row);
            $approvedOwned = $p['status'] === 'approved' && ($p['ownerUid'] ?? null) === $u->uid && in_array($id, Policy::businessPhotoIds($b['publicBusinessPhotoIds']), true);
            $items[] = self::project($id, $p, $approvedOwned, $approvedOwned && ($b['published'] ?? null) === true, ($p['ownerUid'] ?? null) === $u->uid && Policy::businessPhotoReadable($p, $now));
        }

        return [
            'businessId' => $bid,
            'enabled' => MediaStore::enabled(MediaStore::BUSINESS),
            'canUpload' => count($ids) < 8 && Policy::businessPhotoAttempts($b, $now) < 40,
            'coverId' => $b['businessCoverPhotoId'] ?? null,
            'items' => $items,
        ];
    }

    /** @return array{id:string,status:string} */
    public static function upload(Actor $u, Request $req, string $bid, string $id): array
    {
        $nonce = Ids::uuid();
        self::owned($u->uid, $bid);
        $disk = MediaStore::disk(MediaStore::BUSINESS);
        $resource = ['kind' => 'business', 'id' => $id];
        $reserved = false;
        try {
            Barrier::media($u->uid, ['kind' => 'business_parent', 'id' => $bid], static function () use ($u, $bid, $id, $nonce): void {
                $b = Docs::business(Rows::business($bid, true));
                $m = Docs::membership(Rows::member($u->uid, $bid, true));
                $existing = DB::table('business_photos')->where('id', $id)->lockForUpdate()->exists();
                if (! Policy::businessPhotoOwner($u->uid, $bid, $b, $m)) {
                    throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
                }
                if ($existing) {
                    throw new HttpError(409, 'Bu yükleme kimliği kullanıldı. Galeriyi yenile.');
                }
                $now = Ids::nowMs();
                $slots = Policy::businessPhotoIds($b['businessPhotoSlots']);
                if (count($slots) >= 8 || Policy::businessPhotoAttempts($b, $now) >= 40) {
                    throw new HttpError(409, 'Fotoğraf veya yükleme denemesi sınırına ulaşıldı.');
                }
                Rows::create('business_photos', ['id' => $id, 'business_id' => $bid, 'owner_uid' => $u->uid, 'status' => 'uploading', 'version' => 1, 'rights_attested' => true, 'upload_nonce' => $nonce, 'created_at_ms' => $now], static function (): never {
                    throw new HttpError(409, 'Bu yükleme kimliği kullanıldı. Galeriyi yenile.');
                });
                $reservation = Policy::businessPhotoReservation($b, $id, $now);
                Rows::writeList('business_photo_slots', ['business_id' => $bid], $reservation['businessPhotoSlots']);
                DB::table('businesses')->where('id', $bid)->update(['photo_attempts' => $reservation['photoAttempts'], 'photo_attempt_window' => $reservation['photoAttemptWindow']]);
            });
            $reserved = true;
            try {
                $raw = Body::bytes($req, 2500000);
            } catch (Throwable) {
                throw new HttpError(413, 'Fotoğraf boyut sınırını aşıyor.');
            }
            try {
                $image = ImageCodec::businessPhoto($raw);
            } catch (Throwable) {
                throw new HttpError(400, 'Fotoğraf okunamadı veya boyut sınırını aşıyor.');
            }
            MediaStore::save($disk, $u->uid, $resource, MediaStore::businessPath($id), $image['data']);
            MediaStore::save($disk, $u->uid, $resource, MediaStore::businessPath($id, true), $image['thumbnail']);
            Barrier::media($u->uid, $resource, static function () use ($u, $bid, $id, $image): void {
                $b = Docs::business(Rows::business($bid, true));
                $m = Docs::membership(Rows::member($u->uid, $bid, true));
                $p = DB::table('business_photos')->where('id', $id)->lockForUpdate()->first();
                if (! Policy::businessPhotoOwner($u->uid, $bid, $b, $m) || ($p->status ?? null) !== 'uploading' || ! in_array($id, Policy::businessPhotoIds($b['businessPhotoSlots'] ?? null), true)) {
                    throw new HttpError(409, 'İşletme veya fotoğraf durumu değişti.');
                }
                DB::table('business_photos')->where('id', $id)->update(['status' => 'pending', 'width' => $image['width'], 'height' => $image['height'], 'bytes' => strlen($image['data']), 'thumbnail_bytes' => strlen($image['thumbnail']), 'sha256' => $image['sha256'], 'thumbnail_sha256' => $image['thumbnailSha256'], 'expires_at_ms' => Ids::nowMs() + 90 * self::DAY]);
                Rows::history('business_photo_history', 'photo_id', $id, ['kind' => 'uploaded', 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
            });

            return ['id' => $id, 'status' => 'pending'];
        } catch (Throwable $e) {
            if ($reserved) {
                self::cleanup($u->uid, $bid, $id, $resource, $disk);
            }
            throw $e;
        }
    }

    /** @param array{kind:string,id:string} $resource */
    private static function cleanup(string $uid, string $bid, string $id, array $resource, Filesystem $disk): void
    {
        try {
            $remove = Barrier::media($uid, $resource, static function () use ($bid, $id): bool {
                $p = DB::table('business_photos')->where('id', $id)->lockForUpdate()->first();
                $b = Docs::business(Rows::business($bid, true));
                if (($p->status ?? null) !== 'uploading') {
                    return false;
                }
                DB::table('business_photos')->where('id', $id)->update(['status' => 'failed']);
                if ($b !== null) {
                    Rows::writeList('business_photo_slots', ['business_id' => $bid], array_values(array_filter(Policy::businessPhotoIds($b['businessPhotoSlots']), static fn (string $v): bool => $v !== $id)));
                }

                return true;
            });
            if ($remove) {
                foreach ([false, true] as $thumb) {
                    MediaStore::delete($disk, $uid, $resource, MediaStore::businessPath($id, $thumb));
                }
            }
        } catch (Throwable) {
            Log::error('Business photo requires reconciliation');
        }
    }

    /** @param array{businessId:string,id:string,version:int,action:string} $a */
    public static function action(Actor $u, array $a): void
    {
        Barrier::media($u->uid, ['kind' => 'business', 'id' => $a['id']], static function () use ($u, $a): void {
            $bRow = Rows::business($a['businessId'], true);
            $b = Docs::business($bRow);
            $m = Docs::membership(Rows::member($u->uid, $a['businessId'], true));
            $row = DB::table('business_photos')->where('id', $a['id'])->lockForUpdate()->first();
            if (! Policy::businessPhotoOwner($u->uid, $a['businessId'], $b, $m)) {
                throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
            }
            $v = Docs::businessPhoto($row);
            if ($v === null || ($v['businessId'] ?? null) !== $a['businessId'] || ($v['version'] ?? null) !== $a['version'] || ! in_array($v['status'], ['pending', 'approved', 'rejected', 'hidden', 'failed'], true)) {
                throw new HttpError(409, 'Fotoğraf değişti. Galeriyi yenile.');
            }
            $ids = Policy::businessPhotoIds($b['publicBusinessPhotoIds']);
            if ($a['action'] === 'cover') {
                if ($v['status'] !== 'approved' || ($v['ownerUid'] ?? null) !== ($b['ownerUid'] ?? null) || ! in_array($a['id'], $ids, true)) {
                    throw new HttpError(409, 'Yalnız onaylı güncel fotoğraf kapak olabilir.');
                }
                DB::table('businesses')->where('id', $a['businessId'])->update(['business_cover_photo_id' => $a['id']]);
            } else {
                $remaining = array_values(array_filter($ids, static fn (string $x): bool => $x !== $a['id']));
                DB::table('business_photos')->where('id', $a['id'])->update(['status' => 'withdrawn', 'version' => $v['version'] + 1, 'expires_at_ms' => Ids::nowMs() + 30 * self::DAY]);
                Rows::writeList('business_photo_slots', ['business_id' => $a['businessId']], array_values(array_filter(Policy::businessPhotoIds($b['businessPhotoSlots']), static fn (string $x): bool => $x !== $a['id'])));
                Rows::writeList('business_public_photos', ['business_id' => $a['businessId']], $remaining);
                DB::table('businesses')->where('id', $a['businessId'])->update(['business_cover_photo_id' => ($b['businessCoverPhotoId'] ?? null) === $a['id'] ? ($remaining[0] ?? null) : ($b['businessCoverPhotoId'] ?? null)]);
            }
            Rows::history('business_photo_history', 'photo_id', $a['id'], ['kind' => $a['action'], 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
            // Kapak ve yayindaki fotograf referanslari kamu DTO alanidir.
            SearchProjection::sync($a['businessId']);
        });
    }

    /** @return array{businessId:string,ids:list<string>,coverId:?string} */
    public static function publicList(string $bid): array
    {
        $empty = ['businessId' => $bid, 'ids' => [], 'coverId' => null];
        if (! MediaStore::enabled(MediaStore::BUSINESS)) {
            return $empty;
        }
        $v = Docs::business(Rows::business($bid));
        if ($v === null || ($v['published'] ?? null) !== true || ($v['sample'] ?? null) === true || ! is_string($v['ownerUid'] ?? null)) {
            return $empty;
        }
        $m = Docs::membership(Rows::member($v['ownerUid'], $bid));
        $ids = Policy::businessPhotoIds($v['publicBusinessPhotoIds']);
        $rows = $ids === [] ? collect() : DB::table('business_photos')->whereIn('id', $ids)->get()->keyBy('id');
        $allowed = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row !== null && Policy::businessPhotoPublic(Docs::businessPhoto($row), $v, $m)) {
                $allowed[] = $id;
            }
        }

        return ['businessId' => $bid, 'ids' => $allowed, 'coverId' => in_array($v['businessCoverPhotoId'] ?? null, $allowed, true) ? $v['businessCoverPhotoId'] : ($allowed[0] ?? null)];
    }

    /** bytes doner; basliklari denetleyici kurar. */
    public static function media(Request $req, string $bid, string $id, bool $thumb): string
    {
        if (! MediaStore::enabled(MediaStore::BUSINESS)) {
            throw new HttpError(404, 'Fotoğraf bulunamadı.');
        }
        $row = DB::table('business_photos')->where('id', $id)->first();
        $v = Docs::businessPhoto($row);
        $b = Docs::business(Rows::business($bid));
        if ($v === null || ($v['businessId'] ?? null) !== $bid || $b === null) {
            throw new HttpError(404, 'Fotoğraf bulunamadı.');
        }
        $owner = is_string($b['ownerUid'] ?? null) ? Docs::membership(Rows::member($b['ownerUid'], $bid)) : null;
        $reviewer = null;
        $public = Policy::businessPhotoPublic($v, $b, $owner);
        if ($req->headers->has('Authorization')) {
            $u = self::user($req);
            $m = Docs::membership(Rows::member($u->uid, $bid));
            $canOwner = Policy::businessPhotoPrivateOwner($u->uid, $v, $b, $m);
            $canAdmin = Policy::businessPhotoReviewer($u->uid, $u->admin, $v, $b, $m);
            if (! $public && (! Policy::businessPhotoReadable($v, Ids::nowMs()) || (! $canOwner && ! $canAdmin))) {
                throw new HttpError(404, 'Fotoğraf bulunamadı.');
            }
            if ($canAdmin) {
                $reviewer = $u->uid;
            }
        } elseif (! $public) {
            throw new HttpError(404, 'Fotoğraf bulunamadı.');
        }
        $disk = MediaStore::disk(MediaStore::BUSINESS);
        $path = MediaStore::businessPath($id, $thumb);
        $size = MediaStore::size($disk, $path);
        $expected = $thumb ? ($v['thumbnailBytes'] ?? null) : ($v['bytes'] ?? null);
        if (! is_int($expected) || $expected < 1 || $expected > 2500000 || $size !== $expected) {
            throw new HttpError(409, 'Fotoğraf bütünlüğü doğrulanamadı.');
        }
        $bytes = MediaStore::read($disk, $path);
        if (strlen($bytes) !== $expected || hash('sha256', $bytes) !== ($thumb ? ($v['thumbnailSha256'] ?? null) : ($v['sha256'] ?? null))) {
            throw new HttpError(409, 'Fotoğraf bütünlüğü doğrulanamadı.');
        }
        if ($reviewer !== null && ! $thumb) {
            Barrier::media($reviewer, ['kind' => 'business', 'id' => $id], static function () use ($id, $v, $reviewer): void {
                $current = DB::table('business_photos')->where('id', $id)->lockForUpdate()->first();
                if (($current->sha256 ?? null) !== $v['sha256'] || (int) ($current->version ?? 0) !== $v['version']) {
                    throw new HttpError(409, 'Fotoğraf bütünlüğü doğrulanamadı.');
                }
                DB::statement('INSERT INTO `business_photo_views` (`photo_id`, `viewer_key`, `user_id`, `sha256`, `viewed_at`) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `sha256` = VALUES(`sha256`), `viewed_at` = VALUES(`viewed_at`)', [$id, Ids::viewKey($reviewer), $reviewer, $v['sha256']]);
            });
        }

        return $bytes;
    }
}
