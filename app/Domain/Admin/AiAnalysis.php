<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Content\Barrier;
use App\Domain\Content\Digest;
use App\Domain\Content\Docs;
use App\Domain\Content\ExternalCalls;
use App\Domain\Content\Ids;
use App\Domain\Content\Moderation;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use stdClass;

/** /api/admin/reviews/analyze: OpenAI cagrisi transaction disinda, kayitli islem icinde. */
final class AiAnalysis
{
    private static function read(string $kind, string $id): ?stdClass
    {
        return $kind === 'reply' ? Rows::reply($id, true) : Rows::review($id, true);
    }

    private static function table(string $kind): string
    {
        return $kind === 'reply' ? 'review_replies' : 'reviews';
    }

    private static function key(string $kind): string
    {
        return $kind === 'reply' ? 'review_id' : 'id';
    }

    private static function assertApplicable(?stdClass $v, int $version, string $text, string $runId): void
    {
        if ($v === null || ! AdminPolicy::mayApplyAiResult(['status' => $v->status, 'version' => (int) $v->version, 'text' => $v->text, 'aiRunId' => $v->ai_run_id], ['version' => $version, 'text' => $text, 'runId' => $runId])) {
            throw new HttpError(409, 'Yorum değişti; eski AI sonucu uygulanmadı.');
        }
    }

    /**
     * @param  array{id:string,version:int,kind:string}  $input
     * @return array{automatic:array<string,mixed>}
     */
    public static function run(Actor $u, array $input): array
    {
        $kind = $input['kind'];
        $id = $input['id'];
        $version = $input['version'];
        $policyKind = $kind === 'reply' ? 'reply' : 'review';
        $runId = Ids::uuid();
        $text = Barrier::content($u->uid, ['kind' => $policyKind, 'id' => $id], static function () use ($u, $kind, $id, $version, $runId): string {
            $v = self::read($kind, $id);
            if ($v === null || $v->status !== 'pending' || (int) $v->version !== $version) {
                throw new HttpError(409, 'Yorum değişti veya sonuçlandı.');
            }
            if ($v->user_id === $u->uid) {
                throw new HttpError(403, 'Kendi yorumunu inceleyemezsin.');
            }
            Schemas::id()->parse($v->business_id);
            $b = Docs::business(Rows::business($v->business_id, true));
            $m = Docs::membership(Rows::member($u->uid, $v->business_id, true));
            if ($b === null || Policy::affiliatedReviewer($u->uid, $b, $m)) {
                throw new HttpError(403, 'Bu yorumu inceleyemezsin.');
            }
            if (! is_string($v->text) || \App\Support\Js::length($v->text) > 3000) {
                throw new HttpError(400, 'Geçersiz yorum.');
            }
            if ($v->ai_status === 'checked') {
                throw new HttpError(409, 'Bu sürüm için AI kontrolü zaten tamamlandı.');
            }
            if (Ids::nowMs() - (int) ($v->ai_requested_at_ms ?? 0) < 30000) {
                throw new HttpError(429, 'Kontrol sürüyor veya kısa süre önce denendi.');
            }
            DB::table(self::table($kind))->where(self::key($kind), $id)->update(['ai_run_id' => $runId, 'ai_requested_at_ms' => Ids::nowMs(), 'ai_status' => 'running', 'ai_flagged' => null, 'ai_categories' => null, 'ai_model' => null] + array_intersect_key(Moderation::columns($v->text), array_flip(['local_policy_version', 'local_flagged', 'local_obfuscation', 'local_signals'])));

            return $v->text;
        });
        $path = ($kind === 'reply' ? 'reviewReplies/' : 'reviews/').$id;
        $key = Moderator::openAiKey();
        $ai = ExternalCalls::call([
            'kind' => 'ai',
            'bindingDigest' => Digest::of(['provider' => 'openai', 'endpoint' => 'moderations', 'model' => 'omni-moderation-latest']),
            'resourceKey' => Digest::of(['kind' => 'ai', 'path' => $path]),
            'resolve' => static function () use ($u, $kind, $id, $version, $text, $runId, $policyKind): array {
                $subjects = Barrier::contentSubjects($u->uid, ['kind' => $policyKind, 'id' => $id]);
                self::assertApplicable(self::read($kind, $id), $version, $text, $runId);

                return ['subjects' => $subjects, 'sourceDigest' => Digest::of(['text' => $text, 'runId' => $runId, 'version' => $version])];
            },
        ], static fn (): array => AiModeration::check($text, $key), static fn (array $result): bool => $result['status'] === 'checked');
        Barrier::content($u->uid, ['kind' => $policyKind, 'id' => $id], static function () use ($u, $kind, $id, $version, $text, $runId, $ai): void {
            self::assertApplicable(self::read($kind, $id), $version, $text, $runId);
            DB::table(self::table($kind))->where(self::key($kind), $id)->update(['ai_status' => $ai['status'], 'ai_flagged' => $ai['flagged'] ?? null, 'ai_categories' => isset($ai['categories']) ? json_encode($ai['categories']) : null, 'ai_model' => $ai['model'] ?? null, 'ai_checked_at' => Ids::now()] + array_intersect_key(Moderation::columns($text), array_flip(['local_policy_version', 'local_flagged', 'local_obfuscation', 'local_signals'])));
            Rows::history($kind === 'reply' ? 'review_reply_history' : 'review_history', 'review_id', $id, ['kind' => 'ai_checked', 'version' => $version, 'actor_id' => $u->uid, 'details' => Ids::stringify(['result' => $ai]), 'created_at' => Ids::now()]);
        });

        return ['automatic' => ['local' => Moderation::local($text), 'ai' => $ai]];
    }
}
