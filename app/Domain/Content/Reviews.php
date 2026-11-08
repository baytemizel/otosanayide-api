<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** /api/reviews ve /api/reviews/mine yazma/okuma kurallari. */
final class Reviews
{
    /** @return array{reviews:list<array<string,mixed>>,nextCursor:?string,count:int,average:int|float|null} */
    public static function publicPage(string $businessId, ?string $after): array
    {
        $business = Rows::business($businessId);
        if ($business === null || ! (bool) $business->published || (bool) $business->sample) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }
        $q = DB::table('public_reviews')->where('business_id', $businessId)->orderBy('review_id')->limit(21);
        if ($after !== null) {
            $q->where('review_id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $count = (int) $business->review_count;

        return [
            'reviews' => array_map(static fn (\stdClass $d): array => Policy::publicReview($d->review_id, Docs::publicReview($d)), $visible),
            'nextCursor' => count($page) > 20 ? end($visible)->review_id : null,
            'count' => $count,
            'average' => $count > 0 ? (int) $business->review_rating_sum / $count : null,
        ];
    }

    /** @param array<string,mixed> $v reviewSchema ciktisi */
    private static function fields(array $v): array
    {
        return [
            'rating' => $v['rating'],
            'quality' => $v['quality'] ?? null,
            'payment' => $v['payment'] ?? null,
            'cleanliness' => $v['cleanliness'] ?? null,
            'wc_cleanliness' => $v['wcCleanliness'] ?? null,
            'resolved' => $v['resolved'],
            'wc' => $v['wc'],
            'text' => $v['text'],
        ];
    }

    /** @param array<string,mixed> $v */
    public static function create(Actor $u, array $v): string
    {
        $id = Ids::sha([$u->uid, $v['businessId']]);
        $automatic = Moderation::columns($v['text']);
        Barrier::content($u->uid, ['kind' => 'actor'], static function () use ($u, $v, $id, $automatic): void {
            $b = Docs::business(Rows::business($v['businessId'], true));
            $member = Docs::membership(Rows::member($u->uid, $v['businessId'], true));
            $existing = Rows::review($id, true);
            if (($b['published'] ?? null) !== true || ($b['sample'] ?? null) === true) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            if (Policy::affiliatedReviewer($u->uid, $b, $member)) {
                throw new HttpError(403, 'Yetkilisi olduğun işletmeyi puanlayamazsın.');
            }
            if ($existing !== null) {
                throw new HttpError(409, 'Bu işletme için yorumun zaten var.');
            }
            $version = 1;
            Rows::create('reviews', ['id' => $id, 'business_id' => $v['businessId'], 'user_id' => $u->uid, 'status' => 'pending', 'version' => $version, 'submission_version' => $version, 'created_at' => Ids::now()] + self::fields($v) + $automatic,
                static function (): never {
                    throw new HttpError(409, 'Bu işletme için yorumun zaten var.');
                });
            Rows::history('review_history', 'review_id', $id, ['kind' => 'submitted', 'version' => $version, 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
        });

        return $id;
    }

    /** @return array<string,mixed>|null */
    public static function own(Actor $u, string $businessId): ?array
    {
        $id = Ids::sha([$u->uid, $businessId]);

        return Policy::ownReview($u->uid, $businessId, $id, Docs::review(Rows::review($id)));
    }

    /** @param array<string,mixed> $input authorReviewAction ciktisi */
    public static function change(Actor $u, array $input): string
    {
        $id = Ids::sha([$u->uid, $input['businessId']]);
        Barrier::content($u->uid, ['kind' => 'review_with_reply', 'id' => $id], static function () use ($u, $input, $id): void {
            $row = Rows::review($id, true);
            $businessRow = Rows::business($input['businessId'], true);
            $b = Docs::business($businessRow);
            $m = Docs::membership(Rows::member($u->uid, $input['businessId'], true));
            $p = Rows::publicRow($input['businessId'], $id, true);
            $replyRow = Rows::reply($id, true);
            $previous = Docs::review($row);
            try {
                $transition = Policy::authorReviewTransition($u->uid, $input['businessId'], $previous, $input['expectedVersion'], $input['action']);
            } catch (RuntimeException $e) {
                throw new HttpError(409, $e->getMessage());
            }
            if ($input['action'] === 'edit' && (($b['published'] ?? null) !== true || ($b['sample'] ?? null) === true || Policy::affiliatedReviewer($u->uid, $b, $m))) {
                throw new HttpError(403, 'İşletme veya yorumcu yayın koşullarını karşılamıyor.');
            }
            if (($previous['status'] === 'approved') !== ($p !== null)) {
                throw new HttpError(409, 'Yorum yayın kaydı tutarsız. Destek ekibine başvur.');
            }
            if ($transition['delta'] === -1) {
                if ($businessRow === null) {
                    throw new HttpError(409, 'İşletme bulunamadı.');
                }
                $totals = Policy::reviewTotals((int) $businessRow->review_count, (int) $businessRow->review_rating_sum, (int) $previous['rating'], -1);
                DB::table('businesses')->where('id', $input['businessId'])->update(['review_count' => $totals['reviewCount'], 'review_rating_sum' => $totals['reviewRatingSum']]);
            }
            DB::table('public_reviews')->where('business_id', $input['businessId'])->where('review_id', $id)->delete();
            if ($replyRow !== null && $replyRow->business_id === $input['businessId']) {
                DB::table('review_replies')->where('review_id', $id)->update(['status' => 'hidden', 'version' => (int) $replyRow->version + 1, 'reason' => 'Ana yorum değişti veya geri çekildi.', 'updated_at' => Ids::now()]);
                Rows::history('review_reply_history', 'review_id', $id, ['kind' => 'parent_changed', 'actor_id' => $u->uid, 'details' => Ids::stringify(['reviewVersion' => $transition['version']]), 'created_at' => Ids::now()]);
            }
            Rows::history('review_history', 'review_id', $id, ['kind' => $input['action'] === 'edit' ? 'author_edited' : 'author_withdrawn', 'version' => $transition['version'], 'actor_id' => $u->uid, 'details' => Ids::stringify(['before' => Policy::ownReview($u->uid, $input['businessId'], $id, $previous)]), 'created_at' => Ids::now()]);
            if ($input['action'] === 'edit') {
                // tx.set: yayin/inceleme alanlari da sifirlanir, yalniz createdAt korunur.
                DB::table('reviews')->where('id', $id)->update(['status' => 'pending', 'version' => $transition['version'], 'submission_version' => $transition['submissionVersion'], 'moderation_hold' => false, 'reason' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'withdrawn_at' => null, 'photo_attempts' => 0, 'updated_at' => Ids::now()] + self::fields($input['review']) + Moderation::columns($input['review']['text']));
            } else {
                DB::table('reviews')->where('id', $id)->update(['status' => 'withdrawn', 'version' => $transition['version'], 'submission_version' => $transition['submissionVersion'], 'moderation_hold' => $transition['moderationHold'], 'withdrawn_at' => Ids::now()]);
            }
            DB::table('review_photo_refs')->where('review_id', $id)->delete();
        });

        return $input['action'] === 'edit' ? 'pending' : 'withdrawn';
    }
}
