<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** /api/review-photos ve /media. */
final class ReviewPhotos
{
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private static function assertVersion(?string $expected, ?array $r): void
    {
        if ($expected !== null && (float) $expected !== (float) ($r['version'] ?? 1)) {
            throw new HttpError(409, 'Yorum değişti. Durumu yenile.');
        }
    }

    /** @param array<string,mixed> $p */
    public static function item(string $id, array $p): array
    {
        return ['id' => $id, 'status' => $p['status'], 'version' => $p['version'], 'width' => $p['width'] ?? 0, 'height' => $p['height'] ?? 0, 'bytes' => $p['bytes'] ?? 0, 'reason' => $p['reason'] ?? '', 'expiresAtMs' => $p['expiresAtMs'] ?? null];
    }

    /** @return array<string,mixed> */
    public static function list(Actor $u, string $reviewId, ?string $version): array
    {
        $r = Docs::review(Rows::review($reviewId));
        if (($r['userId'] ?? null) !== $u->uid) {
            throw new HttpError(404, 'Yorum bulunamadı.');
        }
        self::assertVersion($version, $r);
        $items = [];
        $rows = DB::table('review_photos')->where('review_id', $reviewId)->where('submission_version', Policy::photoSubmission($r))->orderBy('id')->limit(20)->get();
        foreach ($rows as $row) {
            $p = Docs::reviewPhoto($row);
            if (Policy::photoMatches($p, $r) && ! in_array($p['status'], ['withdrawn', 'failed', 'purging', 'purged'], true)) {
                $items[] = self::item($row->id, $p);
            }
        }

        return [
            'reviewId' => $reviewId,
            'reviewVersion' => $r['version'] ?? 1,
            'enabled' => MediaStore::enabled(MediaStore::REVIEW),
            'canUpload' => Policy::photoCanUpload($u->uid, $r) && count(Policy::photoIds($r['photoSlots'])) < 3 && ($r['photoAttempts'] ?? 0) < 20,
            'items' => $items,
        ];
    }

    /** @return array{id:string,status:string} */
    public static function upload(Actor $u, Request $req, string $reviewId, ?string $version): array
    {
        $initial = Docs::review(Rows::review($reviewId));
        if (! Policy::photoCanUpload($u->uid, $initial)) {
            throw new HttpError(403, 'Bu yoruma fotoğraf ekleyemezsin.');
        }
        self::assertVersion($version, $initial);
        if (! in_array($req->headers->get('Content-Type') ?? '', self::TYPES, true)) {
            throw new HttpError(415, 'JPEG, PNG veya WebP gerekli.');
        }
        $disk = MediaStore::disk(MediaStore::REVIEW);
        $id = Ids::uuid();
        $nonce = Ids::uuid();
        $resource = ['kind' => 'review', 'id' => $id];
        $reserved = false;
        try {
            Barrier::media($u->uid, ['kind' => 'review_parent', 'id' => $reviewId], static function () use ($u, $reviewId, $id, $nonce, $version): void {
                $v = Docs::review(Rows::review($reviewId, true));
                if (! Policy::photoCanUpload($u->uid, $v)) {
                    throw new HttpError(409, 'Yorum durumu değişti.');
                }
                self::assertVersion($version, $v);
                $slots = Policy::photoIds($v['photoSlots']);
                if (count($slots) >= 3 || ($v['photoAttempts'] ?? 0) >= 20) {
                    throw new HttpError(409, 'Fotoğraf sayısı veya yükleme deneme sınırına ulaşıldı.');
                }
                DB::table('review_photos')->insert(['id' => $id, 'review_id' => $reviewId, 'business_id' => $v['businessId'], 'user_id' => $u->uid, 'submission_version' => Policy::photoSubmission($v), 'version' => 1, 'status' => 'uploading', 'rights_attested' => true, 'upload_nonce' => $nonce, 'created_at_ms' => Ids::nowMs(), 'created_at' => Ids::now()]);
                Rows::writeList('review_photo_refs', ['review_id' => $reviewId, 'kind' => 'slot'], [...$slots, $id]);
                DB::table('reviews')->where('id', $reviewId)->update(['photo_attempts' => ($v['photoAttempts'] ?? 0) + 1]);
            });
            $reserved = true;
            try {
                $raw = Body::bytes($req, 2500000);
            } catch (Throwable) {
                throw new HttpError(413, 'Fotoğraf boyut sınırını aşıyor.');
            }
            try {
                $image = ImageCodec::reviewPhoto($raw);
            } catch (Throwable) {
                throw new HttpError(400, 'Fotoğraf okunamadı veya boyut sınırını aşıyor.');
            }
            MediaStore::save($disk, $u->uid, $resource, MediaStore::reviewPath($id), $image['data']);
            MediaStore::save($disk, $u->uid, $resource, MediaStore::reviewPath($id, true), $image['thumbnail']);
            Barrier::media($u->uid, $resource, static function () use ($u, $reviewId, $id, $image, $version): void {
                $r = Docs::review(Rows::review($reviewId, true));
                $p = Docs::reviewPhoto(DB::table('review_photos')->where('id', $id)->lockForUpdate()->first());
                if (! Policy::photoCanUpload($u->uid, $r) || ($p['status'] ?? null) !== 'uploading' || ! Policy::photoMatches($p, $r) || ! in_array($id, Policy::photoIds($r['photoSlots'] ?? null), true)) {
                    throw new HttpError(409, 'Yorum durumu değişti.');
                }
                self::assertVersion($version, $r);
                DB::table('review_photos')->where('id', $id)->update(['status' => 'pending', 'width' => $image['width'], 'height' => $image['height'], 'bytes' => strlen($image['data']), 'thumbnail_bytes' => strlen($image['thumbnail']), 'sha256' => $image['sha256'], 'thumbnail_sha256' => $image['thumbnailSha256'], 'expires_at_ms' => Ids::nowMs() + 90 * 86400000]);
                Rows::history('review_photo_history', 'photo_id', $id, ['kind' => 'uploaded', 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
            });

            return ['id' => $id, 'status' => 'pending'];
        } catch (Throwable $e) {
            if ($reserved) {
                self::cleanup($u->uid, $reviewId, $id, $resource, $disk);
            }
            throw $e;
        }
    }

    /** @param array{kind:string,id:string} $resource */
    private static function cleanup(string $uid, string $reviewId, string $id, array $resource, \Illuminate\Contracts\Filesystem\Filesystem $disk): void
    {
        try {
            $failed = Barrier::media($uid, $resource, static function () use ($reviewId, $id): bool {
                $p = DB::table('review_photos')->where('id', $id)->lockForUpdate()->first();
                if (($p->status ?? null) !== 'uploading') {
                    return false;
                }
                DB::table('review_photos')->where('id', $id)->update(['status' => 'failed']);
                $r = Docs::review(Rows::review($reviewId, true));
                if ($r !== null) {
                    Rows::writeList('review_photo_refs', ['review_id' => $reviewId, 'kind' => 'slot'], array_values(array_filter(Policy::photoIds($r['photoSlots']), static fn (string $v): bool => $v !== $id)));
                }

                return true;
            });
            if ($failed) {
                foreach ([false, true] as $thumb) {
                    MediaStore::delete($disk, $uid, $resource, MediaStore::reviewPath($id, $thumb));
                }
            }
        } catch (Throwable) {
            Log::error('Review photo cleanup requires reconciliation');
        }
    }

    /** @param array{id:string,version:int} $input */
    public static function withdraw(Actor $u, array $input): void
    {
        Barrier::media($u->uid, ['kind' => 'review', 'id' => $input['id']], static function () use ($u, $input): void {
            $ref = DB::table('review_photos')->where('id', $input['id'])->lockForUpdate()->first();
            $v = Docs::reviewPhoto($ref);
            if ($v === null || ($v['userId'] ?? null) !== $u->uid || ($v['version'] ?? null) !== $input['version'] || ! in_array($v['status'], ['pending', 'approved', 'rejected', 'hidden'], true)) {
                throw new HttpError(409, 'Fotoğraf değişti. Listeyi yenile.');
            }
            Schemas::id()->parse($v['reviewId']);
            Schemas::id()->parse($v['businessId']);
            $r = Docs::review(Rows::review($v['reviewId'], true));
            $pub = Rows::publicRow($v['businessId'], $v['reviewId'], true);
            DB::table('review_photos')->where('id', $input['id'])->update(['status' => 'withdrawn', 'version' => $v['version'] + 1, 'expires_at_ms' => Ids::nowMs() + 30 * 86400000]);
            $without = static fn (array $ids): array => array_values(array_filter($ids, static fn (string $x): bool => $x !== $input['id']));
            if ($r !== null) {
                Rows::writeList('review_photo_refs', ['review_id' => $v['reviewId'], 'kind' => 'slot'], $without(Policy::photoIds($r['photoSlots'])));
                Rows::writeList('review_photo_refs', ['review_id' => $v['reviewId'], 'kind' => 'public'], $without(Policy::photoIds($r['publicPhotoIds'])));
            }
            if ($pub !== null) {
                $doc = Docs::publicReview($pub);
                DB::table('public_review_photos')->where('business_id', $v['businessId'])->where('review_id', $v['reviewId'])->delete();
                foreach ($without(Policy::photoIds($doc['photoIds'])) as $position => $photo) {
                    DB::table('public_review_photos')->insert(['business_id' => $v['businessId'], 'review_id' => $v['reviewId'], 'position' => $position, 'photo_id' => $photo]);
                }
            }
            Rows::history('review_photo_history', 'photo_id', $input['id'], ['kind' => 'withdrawn', 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
        });
    }

    /** @param array{id:string,thumbnail:bool} $query */
    public static function media(Request $req, array $query): string
    {
        $id = $query['id'];
        $thumb = $query['thumbnail'];
        $row = DB::table('review_photos')->where('id', $id)->first();
        $p = Docs::reviewPhoto($row);
        if ($p === null) {
            throw new HttpError(404, 'Fotoğraf bulunamadı.');
        }
        Schemas::id()->parse($p['reviewId']);
        Schemas::id()->parse($p['businessId']);
        $r = Docs::review(Rows::review($p['reviewId']));
        $b = Docs::business(Rows::business($p['businessId']));
        $adminId = null;
        if (! Policy::photoIsPublic($p, $r, $b)) {
            $u = \App\Domain\Auth\ActorResolver::resolve($req);
            RateLimit::consume($u->uid);
            if (! $u->emailVerified || (! $u->admin && $u->uid !== ($p['userId'] ?? null)) || ! Policy::photoMatches($p, $r) || ! in_array($p['status'], ['pending', 'approved', 'rejected', 'hidden'], true) || ($p['status'] !== 'approved' && (! is_int($p['expiresAtMs'] ?? null) || $p['expiresAtMs'] <= Ids::nowMs()))) {
                throw new HttpError(404, 'Fotoğraf bulunamadı.');
            }
            if ($u->admin) {
                $adminId = $u->uid;
            }
        } elseif ($req->headers->has('Authorization')) {
            $u = \App\Domain\Auth\ActorResolver::resolve($req);
            RateLimit::consume($u->uid);
            if ($u->admin && $u->emailVerified) {
                $adminId = $u->uid;
            }
        }
        $disk = MediaStore::disk(MediaStore::REVIEW);
        $bytes = MediaStore::read($disk, MediaStore::reviewPath($id, $thumb));
        $sha = hash('sha256', $bytes);
        if (strlen($bytes) !== ($thumb ? ($p['thumbnailBytes'] ?? null) : ($p['bytes'] ?? null)) || $sha !== ($thumb ? ($p['thumbnailSha256'] ?? null) : ($p['sha256'] ?? null))) {
            throw new HttpError(409, 'Fotoğraf bütünlüğü doğrulanamadı.');
        }
        // Yalniz tam gorselin acilmasi moderasyon onayina sayilir.
        if ($adminId !== null && ! $thumb) {
            Barrier::media($adminId, ['kind' => 'review', 'id' => $id], static function () use ($id, $p, $adminId): void {
                $current = DB::table('review_photos')->where('id', $id)->lockForUpdate()->first();
                if (($current->sha256 ?? null) !== $p['sha256'] || ($current->status ?? null) !== $p['status']) {
                    throw new HttpError(409, 'Fotoğraf bütünlüğü doğrulanamadı.');
                }
                $key = Ids::viewKey($adminId);
                DB::statement('INSERT INTO `review_photo_views` (`photo_id`, `viewer_key`, `reviewer_id`, `sha256`, `viewed_at`) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE `reviewer_id` = VALUES(`reviewer_id`), `sha256` = VALUES(`sha256`), `viewed_at` = VALUES(`viewed_at`)', [$id, $key, $adminId, $p['sha256']]);
            });
        }

        return $bytes;
    }
}
