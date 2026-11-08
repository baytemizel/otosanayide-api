<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** /api/businesses/replies */
final class Replies
{
    /** @return array{businessId:string,reviews:list<array<string,mixed>>,nextCursor:?string} */
    public static function page(Actor $u, string $id, ?string $after): array
    {
        $b = Docs::business(Rows::business($id));
        $m = Docs::membership(Rows::member($u->uid, $id));
        if ($b === null || ! Policy::mayReply($u->uid, $id, $b, $m)) {
            throw new HttpError(403, 'Bu işletme adına yanıt veremezsin.');
        }
        $q = DB::table('public_reviews')->where('business_id', $id)->orderBy('review_id')->limit(21);
        if ($after !== null) {
            $q->where('review_id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $items = array_map(static function (\stdClass $d) use ($id): array {
            $reply = Rows::reply($d->review_id);

            return Policy::publicReview($d->review_id, Docs::publicReview($d)) + [
                'replyDraft' => $reply !== null && $reply->business_id === $id ? ['text' => $reply->text, 'status' => $reply->status, 'version' => (int) $reply->version, 'reason' => $reply->reason ?? ''] : null,
            ];
        }, $visible);

        return ['businessId' => $id, 'reviews' => $items, 'nextCursor' => count($page) > 20 ? end($visible)->review_id : null];
    }

    /** @param array<string,mixed> $input replyInput/replyWithdraw ciktisi */
    public static function write(Actor $u, array $input, bool $withdraw, ?string $text): void
    {
        $bid = $input['businessId'];
        $rid = $input['reviewId'];
        Barrier::content($u->uid, ['kind' => 'review_with_reply', 'id' => $rid], static function () use ($u, $input, $withdraw, $text, $bid, $rid): void {
            $b = Docs::business(Rows::business($bid, true));
            $m = Docs::membership(Rows::member($u->uid, $bid, true));
            $r = Docs::review(Rows::review($rid, true));
            $oldRow = Rows::reply($rid, true);
            $old = Docs::reply($oldRow);
            $p = Rows::publicRow($bid, $rid, true);
            if ($b === null || ! Policy::mayReply($u->uid, $bid, $b, $m)) {
                throw new HttpError(403, 'Bu işletme adına yanıt veremezsin.');
            }
            if (($r['businessId'] ?? null) !== $bid || ($r['status'] ?? null) !== 'approved' || $p === null) {
                throw new HttpError(409, 'Yorum artık yayında değil.');
            }
            if (($r['userId'] ?? null) === $u->uid) {
                throw new HttpError(403, 'Kendi yorumuna işletme yanıtı veremezsin.');
            }
            if ($old !== null && ($old['businessId'] ?? null) !== $bid) {
                throw new HttpError(409, 'Yanıt kaydı eşleşmiyor.');
            }
            if ($withdraw) {
                if ($old === null || ($old['version'] ?? null) !== $input['expectedVersion'] || ! in_array($old['status'], ['pending', 'approved'], true)) {
                    throw new HttpError(409, 'Yanıt değişti. Listeyi yenile.');
                }
                DB::table('review_replies')->where('review_id', $rid)->update(['status' => 'hidden', 'version' => $input['expectedVersion'] + 1, 'updated_at' => Ids::now()]);
                DB::table('public_reviews')->where('business_id', $bid)->where('review_id', $rid)->update(['reply_text' => null]);
            } else {
                try {
                    $version = Policy::replyVersion($old, $input['expectedVersion']);
                } catch (RuntimeException $e) {
                    throw new HttpError(409, $e->getMessage());
                }
                // tx.set: onceki inceleme alanlari tasinmaz.
                $row = ['business_id' => $bid, 'user_id' => $u->uid, 'text' => $text, 'status' => 'pending', 'version' => $version, 'reason' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => Ids::now(), 'updated_at' => null] + Moderation::columns((string) $text);
                if ($oldRow !== null) {
                    DB::table('review_replies')->where('review_id', $rid)->update($row);
                } else {
                    Rows::create('review_replies', ['review_id' => $rid] + $row, static function (): never {
                        throw new HttpError(409, 'Yanıt değişti veya zaten incelemede/yayında. Listeyi yenile.');
                    });
                }
            }
            Rows::history('review_reply_history', 'review_id', $rid, ['kind' => $withdraw ? 'withdrawn' : 'submitted', 'version' => $input['expectedVersion'] + 1, 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
        });
    }
}
