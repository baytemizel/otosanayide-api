<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Content\Barrier;
use App\Domain\Content\BusinessPhotos;
use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\MediaStore;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;

/** /api/admin/review-photos ve /api/admin/business-photos */
final class PhotoModeration
{
    private const DAY = 86400000;

    /** @return array{enabled:bool,items:list<array<string,mixed>>,nextCursor:?string} */
    public static function reviewPage(string $status, ?string $after): array
    {
        $q = DB::table('review_photos')->where('status', $status)->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $items = array_map(static fn (\stdClass $p): array => ['id' => $p->id, 'reviewId' => $p->review_id, 'businessId' => $p->business_id, 'status' => $p->status, 'version' => (int) $p->version, 'width' => $p->width === null ? null : (int) $p->width, 'height' => $p->height === null ? null : (int) $p->height, 'bytes' => $p->bytes === null ? null : (int) $p->bytes], $visible);

        return ['enabled' => MediaStore::enabled(MediaStore::REVIEW), 'items' => $items, 'nextCursor' => count($page) > 20 ? end($visible)->id : null];
    }

    /** @param array<string,mixed> $input reviewPhotoDecision ciktisi */
    public static function decideReview(Actor $u, array $input): void
    {
        Barrier::media($u->uid, ['kind' => 'review', 'id' => $input['id']], static function () use ($u, $input): void {
            $id = $input['id'];
            $row = DB::table('review_photos')->where('id', $id)->lockForUpdate()->first();
            $p = Docs::reviewPhoto($row);
            if ($p === null || ($p['version'] ?? null) !== $input['version'] || ! ($input['decision'] === 'hide' ? $p['status'] === 'approved' : $p['status'] === 'pending')) {
                throw new HttpError(409, 'Fotoğraf değişti. Listeyi yenile.');
            }
            if ($p['userId'] === $u->uid) {
                throw new HttpError(403, 'Kendi fotoğrafını inceleyemezsin.');
            }
            Schemas::id()->parse($p['reviewId']);
            Schemas::id()->parse($p['businessId']);
            $bid = $p['businessId'];
            $rid = $p['reviewId'];
            $r = Docs::review(Rows::review($rid, true));
            $b = Docs::business(Rows::business($bid, true));
            $m = Docs::membership(Rows::member($u->uid, $bid, true));
            $author = Docs::membership(Rows::member($p['userId'], $bid, true));
            $viewRow = DB::table('review_photo_views')->where('photo_id', $id)->where('viewer_key', Ids::viewKey($u->uid))->first();
            $view = $viewRow === null ? null : ['sha256' => $viewRow->sha256];
            $publicRow = Rows::publicRow($bid, $rid, true);
            if ($b === null || Policy::affiliatedReviewer($u->uid, $b, $m)) {
                throw new HttpError(403, 'Yetkilisi olduğun işletmenin yorumlarını inceleyemezsin.');
            }
            $status = $input['decision'] === 'approve' ? 'approved' : ($input['decision'] === 'hide' ? 'hidden' : 'rejected');
            $without = static fn (array $ids): array => array_values(array_filter($ids, static fn (string $x): bool => $x !== $id));
            if ($status === 'approved') {
                if ($publicRow === null || ! Policy::photoMayPublish($p, $r, $b, $view, $input['attested'], Ids::nowMs()) || Policy::affiliatedReviewer($p['userId'], $b, $author)) {
                    throw new HttpError(409, 'Fotoğrafı tam boyutta incele; yorum ve yayın koşullarını kontrol et.');
                }
                $ids = array_values(array_unique([...Policy::photoIds($r['publicPhotoIds']), $id]));
                if (count($ids) > 3) {
                    throw new HttpError(409, 'Yorum fotoğraf sınırı aşıldı.');
                }
                Rows::writeList('review_photo_refs', ['review_id' => $rid, 'kind' => 'public'], $ids);
                Rows::writeList('public_review_photos', ['business_id' => $bid, 'review_id' => $rid], $ids);
            } elseif ($status === 'hidden') {
                if ($r !== null) {
                    Rows::writeList('review_photo_refs', ['review_id' => $rid, 'kind' => 'public'], $without(Policy::photoIds($r['publicPhotoIds'])));
                }
                if ($publicRow !== null) {
                    Rows::writeList('public_review_photos', ['business_id' => $bid, 'review_id' => $rid], $without(Policy::photoIds(Docs::publicReview($publicRow)['photoIds'])));
                }
            }
            DB::table('review_photos')->where('id', $id)->update(['status' => $status, 'version' => $p['version'] + 1, 'reason' => $input['reason'], 'expires_at_ms' => $status === 'approved' ? null : Ids::nowMs() + 30 * self::DAY, 'reviewed_at' => Ids::now(), 'reviewed_by' => $u->uid]);
            Rows::history('review_photo_history', 'photo_id', $id, ['kind' => 'moderated', 'version' => $input['version'], 'actor_id' => $u->uid, 'details' => Ids::stringify(['decision' => $input['decision'], 'reason' => $input['reason'], 'attested' => $input['attested']]), 'created_at' => Ids::now()]);
        });
    }

    /** @return array{items:list<array<string,mixed>>,next:?string} */
    public static function businessPage(string $status, ?string $after): array
    {
        $q = DB::table('business_photos')->where('status', $status)->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);

        return [
            'items' => array_map(static fn (\stdClass $d): array => BusinessPhotos::project($d->id, Docs::businessPhoto($d)) + ['businessId' => $d->business_id], $visible),
            'next' => count($page) > 20 ? $visible[19]->id : null,
        ];
    }

    /** @param array<string,mixed> $a businessPhotoDecision ciktisi */
    public static function decideBusiness(Actor $u, array $a): void
    {
        Barrier::media($u->uid, ['kind' => 'business', 'id' => $a['id']], static function () use ($u, $a): void {
            $id = $a['id'];
            $row = DB::table('business_photos')->where('id', $id)->lockForUpdate()->first();
            $v = Docs::businessPhoto($row);
            if ($v === null) {
                throw new HttpError(404, 'Fotoğraf bulunamadı.');
            }
            $bid = $v['businessId'];
            $b = Docs::business(Rows::business($bid, true));
            $m = Docs::membership(Rows::member($u->uid, $bid, true));
            $owner = Docs::membership(Rows::member($v['ownerUid'], $bid, true));
            $viewRow = DB::table('business_photo_views')->where('photo_id', $id)->where('viewer_key', Ids::viewKey($u->uid))->first();
            $view = $viewRow === null ? null : ['sha256' => $viewRow->sha256];
            if (! Policy::businessPhotoReviewer($u->uid, $u->admin, $v, $b, $m)) {
                throw new HttpError(403, 'Kendi veya yetkili olduğun işletmenin fotoğrafını inceleyemezsin.');
            }
            if (($v['version'] ?? null) !== $a['version'] || ($a['decision'] === 'hide' ? $v['status'] !== 'approved' : $v['status'] !== 'pending')) {
                throw new HttpError(409, 'Fotoğraf değişti. Galeriyi yenile.');
            }
            $ids = Policy::businessPhotoIds($b['publicBusinessPhotoIds'] ?? null);
            if ($a['decision'] === 'approve') {
                if (! Policy::businessPhotoMayApprove($v, $b, $owner, $view, $a['attested'], Ids::nowMs())) {
                    throw new HttpError(409, 'Fotoğrafı tam boyutta incele; sahiplik ve yayın koşullarını kontrol et.');
                }
                $next = Policy::businessPhotoIds([...$ids, $id]);
                if (! in_array($id, $next, true)) {
                    throw new HttpError(409, 'Fotoğraf sınırı aşıldı.');
                }
                $cover = $b['businessCoverPhotoId'] ?? null;
                Rows::writeList('business_public_photos', ['business_id' => $bid], $next);
                DB::table('businesses')->where('id', $bid)->update(['business_cover_photo_id' => in_array($cover, $next, true) ? $cover : $next[0]]);
            } elseif ($b !== null) {
                $next = array_values(array_filter($ids, static fn (string $x): bool => $x !== $id));
                Rows::writeList('business_public_photos', ['business_id' => $bid], $next);
                DB::table('businesses')->where('id', $bid)->update(['business_cover_photo_id' => ($b['businessCoverPhotoId'] ?? null) === $id ? ($next[0] ?? null) : ($b['businessCoverPhotoId'] ?? null)]);
            }
            DB::table('business_photos')->where('id', $id)->update(['status' => $a['decision'] === 'approve' ? 'approved' : ($a['decision'] === 'hide' ? 'hidden' : 'rejected'), 'version' => $v['version'] + 1, 'reason' => $a['reason'], 'expires_at_ms' => $a['decision'] === 'approve' ? null : Ids::nowMs() + 30 * self::DAY]);
            Rows::history('business_photo_history', 'photo_id', $id, ['kind' => $a['decision'], 'actor_id' => $u->uid, 'details' => Ids::stringify(['reason' => $a['reason'], 'attested' => $a['attested']]), 'created_at' => Ids::now()]);
            // Yayindaki foto/kapak kamu DTO alanidir.
            if ($b !== null) {
                SearchProjection::sync($bid);
            }
        });
    }
}
