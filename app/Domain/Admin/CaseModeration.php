<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Content\Barrier;
use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** /api/admin/review-cases */
final class CaseModeration
{
    /** @return array<string,mixed> */
    private static function caseDoc(\stdClass $c): array
    {
        return array_filter(['userId' => $c->user_id, 'reviewId' => $c->review_id, 'businessId' => $c->business_id, 'kind' => $c->kind, 'text' => $c->text, 'status' => $c->status, 'decision' => $c->decision, 'reason' => $c->reason, 'reviewVersion' => (int) $c->review_version], static fn (mixed $x): bool => $x !== null);
    }

    /** @return array{items:list<array<string,mixed>>,next:?string} */
    public static function page(?string $after): array
    {
        $q = DB::table('review_cases')->where('status', 'open')->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $page = $q->get()->all();
        $items = array_map(static function (\stdClass $d): array {
            $v = self::caseDoc($d);
            $r = Docs::review(Rows::review($d->review_id));
            $pub = Rows::publicRow($d->business_id, $d->review_id);

            return Policy::caseView($d->id, $v) + [
                'moderationReason' => $r['reason'] ?? '',
                'reviewVersion' => $v['reviewVersion'],
                'currentVersion' => $r['version'] ?? 1,
                'review' => $r !== null ? Policy::publicReview($d->review_id, $r + ($pub !== null && $pub->reply_text !== null ? ['reply' => ['text' => $pub->reply_text]] : [])) : null,
            ];
        }, array_slice($page, 0, 20));

        return ['items' => $items, 'next' => count($page) > 20 ? $page[19]->id : null];
    }

    /** @param array<string,mixed> $input caseDecision ciktisi */
    public static function decide(Actor $u, array $input): void
    {
        Barrier::content($u->uid, ['kind' => 'case', 'id' => $input['id']], static function () use ($u, $input): void {
            $row = DB::table('review_cases')->where('id', $input['id'])->lockForUpdate()->first();
            if ($row === null) {
                throw new HttpError(404, 'Başvuru bulunamadı.');
            }
            $c = self::caseDoc($row);
            $rid = Schemas::id()->parse($row->review_id);
            $bid = Schemas::id()->parse($row->business_id);
            $r = Docs::review(Rows::review($rid, true));
            $b = Docs::business(Rows::business($bid, true));
            $m = Docs::membership(Rows::member($u->uid, $bid, true));
            $p = Rows::publicRow($bid, $rid, true);
            if (Policy::affiliatedReviewer($u->uid, $b ?? [], $m)) {
                throw new HttpError(403, 'Bu başvuruyu başka bir yönetici incelemeli.');
            }
            try {
                $action = Policy::caseAction($u->uid, $c, $r, $input['decision']);
            } catch (RuntimeException $e) {
                throw new HttpError(409, $e->getMessage());
            }
            if ($action === 'hide') {
                if ($p === null || $b === null) {
                    throw new HttpError(409, 'Yorum yayın kaydı tutarsız. Destek ekibine başvur.');
                }
                $totals = Policy::reviewTotals($b['reviewCount'] ?? null, $b['reviewRatingSum'] ?? null, $r['rating'], -1);
                DB::table('businesses')->where('id', $bid)->update(['review_count' => $totals['reviewCount'], 'review_rating_sum' => $totals['reviewRatingSum']]);
                DB::table('public_reviews')->where('business_id', $bid)->where('review_id', $rid)->delete();
                \App\Domain\Catalogue\ReviewSummary::refresh($bid); // web-08
                DB::table('reviews')->where('id', $rid)->update(['status' => 'hidden', 'moderation_hold' => true, 'version' => ($r['version'] ?? 1) + 1, 'reason' => $input['reason'], 'reviewed_by' => $u->uid, 'reviewed_at' => Ids::now()]);
            }
            if ($action === 'pending') {
                if ($p !== null) {
                    throw new HttpError(409, 'Yorum yayın kaydı tutarsız. Destek ekibine başvur.');
                }
                $version = ($r['version'] ?? 1) + 1;
                // automatic: {ai:{status:'not_requested'}}; aiRunId/aiRequestedAtMs ust seviye alanlar, dokunulmaz.
                DB::table('reviews')->where('id', $rid)->update(['status' => 'pending', 'moderation_hold' => false, 'version' => $version, 'submission_version' => $version, 'photo_attempts' => 0, 'reason' => $input['reason'], 'local_policy_version' => null, 'local_flagged' => null, 'local_obfuscation' => null, 'local_signals' => null, 'ai_status' => 'not_requested', 'ai_flagged' => null, 'ai_categories' => null, 'ai_model' => null, 'reviewed_by' => $u->uid, 'reviewed_at' => Ids::now()]);
                DB::table('review_photo_refs')->where('review_id', $rid)->delete();
            }
            if ($action !== 'none') {
                Rows::history('review_history', 'review_id', $rid, ['kind' => 'case_decision', 'actor_id' => $u->uid, 'details' => Ids::stringify(['caseId' => $input['id'], 'decision' => $input['decision'], 'reason' => $input['reason']]), 'created_at' => Ids::now()]);
            }
            DB::table('review_cases')->where('id', $input['id'])->update(['status' => 'closed', 'decision' => $input['decision'], 'reason' => $input['reason'], 'reviewed_by' => $u->uid, 'closed_at' => Ids::now()]);
            // Kaynak belgede `kind` yok; NOT NULL kolon bos dize tasir.
            Rows::history('review_case_history', 'case_id', $input['id'], ['kind' => '', 'actor_id' => $u->uid, 'details' => Ids::stringify($input), 'created_at' => Ids::now()]);
        });
    }
}
