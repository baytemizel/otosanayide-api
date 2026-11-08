<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Content\Barrier;
use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\Moderation;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** /api/admin/reviews */
final class ReviewModeration
{
    /** @return array{reviews:list<array<string,mixed>>,nextCursor:?string,aiEnabled:bool} */
    public static function page(string $status, ?string $after): array
    {
        $q = DB::table('reviews')->where('status', $status)->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $reviews = array_map(static fn (\stdClass $d): array => Policy::publicReview($d->id, Docs::review($d)) + [
            'businessId' => $d->business_id,
            'version' => (int) $d->version,
            'status' => $d->status,
            'automatic' => Moderator::automatic((string) $d->text, $d),
        ], $visible);

        return ['reviews' => $reviews, 'nextCursor' => count($page) > 20 ? end($visible)->id : null, 'aiEnabled' => Moderator::aiEnabled()];
    }

    /** @param array<string,mixed> $input reviewDecision ciktisi */
    public static function decide(Actor $u, array $input): void
    {
        Barrier::content($u->uid, ['kind' => 'review_with_reply', 'id' => $input['id']], static function () use ($u, $input): void {
            $id = $input['id'];
            $row = Rows::review($id, true);
            $v = Docs::review($row);
            if ($v === null || ($v['version'] ?? 1) !== $input['version']) {
                throw new HttpError(409, 'Yorum değişti. Listeyi yenile.');
            }
            $reviewed = ($input['contentReviewed'] ?? false) === true;
            if ($input['decision'] === 'approve' && ! $reviewed) {
                throw new HttpError(400, 'İçeriği ve otomatik kontrol sonuçlarını incelediğini onayla.');
            }
            if ($input['decision'] === 'approve' && $row->ai_status === 'running' && Ids::nowMs() - (int) ($row->ai_requested_at_ms ?? 0) < 30000) {
                throw new HttpError(409, 'AI kontrolü tamamlanmadan yayın kararı verilemez.');
            }
            if ($v['userId'] === $u->uid) {
                throw new HttpError(403, 'Kendi yorumunu inceleyemezsin.');
            }
            Schemas::id()->parse($v['businessId']);
            $bid = $v['businessId'];
            $bRow = Rows::business($bid, true);
            $reviewer = Docs::membership(Rows::member($u->uid, $bid, true));
            $author = Docs::membership(Rows::member($v['userId'], $bid, true));
            $b = Docs::business($bRow);
            if ($b === null) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            if (Policy::affiliatedReviewer($u->uid, $b, $reviewer)) {
                throw new HttpError(403, 'Yetkilisi olduğun işletmenin yorumlarını inceleyemezsin.');
            }
            if ($input['decision'] === 'approve' && (($b['published'] ?? null) !== true || ($b['sample'] ?? null) === true || Policy::affiliatedReviewer($v['userId'], $b, $author))) {
                throw new HttpError(409, 'İşletme veya yorumcu yayın koşullarını karşılamıyor.');
            }
            try {
                $transition = Policy::reviewTransition($v['status'], $input['decision']);
            } catch (RuntimeException $e) {
                throw new HttpError(409, $e->getMessage());
            }
            $fields = Policy::publicReview($id, $v);
            unset($fields['id'], $fields['photoIds'], $fields['reply']);
            $validated = Schemas::review()->parse((object) (['businessId' => $v['businessId']] + $fields));
            $totals = Policy::reviewTotals($b['reviewCount'] ?? null, $b['reviewRatingSum'] ?? null, $validated['rating'], $transition['delta']);
            if ($transition['status'] === 'approved') {
                // tx.set: varsa eski yanit ve fotograflar da gider.
                DB::table('public_reviews')->where('business_id', $bid)->where('review_id', $id)->delete();
                DB::table('public_reviews')->insert(['business_id' => $bid, 'review_id' => $id, 'rating' => $validated['rating'], 'quality' => $validated['quality'] ?? null, 'payment' => $validated['payment'] ?? null, 'cleanliness' => $validated['cleanliness'] ?? null, 'wc_cleanliness' => $validated['wcCleanliness'] ?? null, 'resolved' => $validated['resolved'], 'wc' => $validated['wc'], 'text' => $validated['text'], 'reply_text' => null, 'published_at' => Ids::now()]);
            }
            if ($transition['status'] === 'hidden') {
                DB::table('public_reviews')->where('business_id', $bid)->where('review_id', $id)->delete();
            }
            DB::table('businesses')->where('id', $bid)->update(['review_count' => $totals['reviewCount'], 'review_rating_sum' => $totals['reviewRatingSum']]);
            DB::table('reviews')->where('id', $id)->update(['status' => $transition['status'], 'version' => $input['version'] + 1, 'reason' => $input['reason'], 'reviewed_by' => $u->uid, 'reviewed_at' => Ids::now()]);
            Rows::history('review_history', 'review_id', $id, ['kind' => 'moderated', 'version' => $input['version'], 'actor_id' => $u->uid, 'details' => Ids::stringify(['decision' => $input['decision'], 'reason' => $input['reason'], 'contentReviewed' => $reviewed, 'automatic' => ['local' => Moderation::local((string) $row->text), 'ai' => Moderator::ai($row)]]), 'created_at' => Ids::now()]);
        });
    }
}
