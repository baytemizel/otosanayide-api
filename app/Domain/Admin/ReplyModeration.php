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

/** /api/admin/replies */
final class ReplyModeration
{
    /** @return array{replies:list<array<string,mixed>>,nextCursor:?string,aiEnabled:bool} */
    public static function page(string $status, ?string $after): array
    {
        $q = DB::table('review_replies')->where('status', $status)->orderBy('review_id')->limit(21);
        if ($after !== null) {
            $q->where('review_id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $replies = array_map(static function (\stdClass $d): array {
            Schemas::id()->parse($d->review_id);
            $parent = Docs::review(Rows::review($d->review_id));

            return ['id' => $d->review_id, 'businessId' => $d->business_id, 'version' => (int) $d->version, 'status' => $d->status, 'text' => $d->text, 'parent' => $parent !== null ? Policy::publicReview($d->review_id, $parent) : null, 'automatic' => Moderator::automatic((string) $d->text, $d)];
        }, $visible);

        return ['replies' => $replies, 'nextCursor' => count($page) > 20 ? end($visible)->review_id : null, 'aiEnabled' => Moderator::aiEnabled()];
    }

    /** @param array<string,mixed> $input reviewDecision ciktisi */
    public static function decide(Actor $u, array $input): void
    {
        Barrier::content($u->uid, ['kind' => 'reply', 'id' => $input['id']], static function () use ($u, $input): void {
            $id = $input['id'];
            $row = Rows::reply($id, true);
            if ($row === null || (int) $row->version !== $input['version']) {
                throw new HttpError(409, 'Yanıt değişti. Listeyi yenile.');
            }
            Schemas::id()->parse($row->business_id);
            Schemas::id()->parse($row->review_id);
            if ($row->user_id === $u->uid) {
                throw new HttpError(403, 'Kendi yanıtını inceleyemezsin.');
            }
            $bid = $row->business_id;
            $b = Docs::business(Rows::business($bid, true));
            $m = Docs::membership(Rows::member($u->uid, $bid, true));
            $author = Docs::membership(Rows::member($row->user_id, $bid, true));
            $r = Docs::review(Rows::review($row->review_id, true));
            $p = Rows::publicRow($bid, $row->review_id, true);
            if ($b === null || Policy::affiliatedReviewer($u->uid, $b, $m)) {
                throw new HttpError(403, 'Yetkilisi olduğun işletmenin yorumlarını inceleyemezsin.');
            }
            $reviewed = ($input['contentReviewed'] ?? false) === true;
            if ($input['decision'] === 'approve') {
                if (! $reviewed) {
                    throw new HttpError(400, 'İçeriği ve otomatik kontrol sonuçlarını incelediğini onayla.');
                }
                if ($row->ai_status === 'running' && Ids::nowMs() - (int) ($row->ai_requested_at_ms ?? 0) < 30000) {
                    throw new HttpError(409, 'AI kontrolü tamamlanmadan yayın kararı verilemez.');
                }
                if (! Policy::mayReply($row->user_id, $bid, $b, $author) || ($r['status'] ?? null) !== 'approved' || ($r['businessId'] ?? null) !== $bid || ($r['userId'] ?? null) === $row->user_id || $p === null) {
                    throw new HttpError(409, 'Yanıt veya yorum yayın koşullarını karşılamıyor.');
                }
            }
            try {
                $transition = Policy::reviewTransition($row->status, $input['decision']);
            } catch (RuntimeException $e) {
                throw new HttpError(409, $e->getMessage());
            }
            if ($transition['status'] === 'approved') {
                $reply = Policy::publicReply(['text' => $row->text]);
                if ($reply === null) {
                    throw new HttpError(400, 'Geçersiz yanıt.');
                }
                DB::table('public_reviews')->where('business_id', $bid)->where('review_id', $row->review_id)->update(['reply_text' => $reply['text']]);
            }
            if ($transition['status'] === 'hidden' && $p !== null) {
                DB::table('public_reviews')->where('business_id', $bid)->where('review_id', $row->review_id)->update(['reply_text' => null]);
            }
            DB::table('review_replies')->where('review_id', $id)->update(['status' => $transition['status'], 'version' => $input['version'] + 1, 'reason' => $input['reason'], 'reviewed_by' => $u->uid, 'reviewed_at' => Ids::now()]);
            Rows::history('review_reply_history', 'review_id', $id, ['kind' => 'moderated', 'version' => $input['version'], 'actor_id' => $u->uid, 'details' => Ids::stringify(['decision' => $input['decision'], 'reason' => $input['reason'], 'contentReviewed' => $reviewed, 'automatic' => ['local' => Moderation::local((string) $row->text), 'ai' => Moderator::ai($row)]]), 'created_at' => Ids::now()]);
        });
    }
}
