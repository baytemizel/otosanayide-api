<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;

final class ReviewCases
{
    /** @return array{items:list<array<string,mixed>>,next:?string} */
    public static function page(string $uid, ?string $after): array
    {
        $q = DB::table('review_cases')->where('user_id', $uid)->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);

        return [
            'items' => array_map(static fn (\stdClass $d): array => Policy::caseView($d->id, ['reviewId' => $d->review_id, 'businessId' => $d->business_id, 'kind' => $d->kind, 'text' => $d->text, 'status' => $d->status, 'decision' => $d->decision, 'reason' => $d->reason]), $visible),
            'next' => count($page) > 20 ? $page[19]->id : null,
        ];
    }

    /** @param array<string,mixed> $input caseInput ciktisi */
    public static function file(Actor $u, array $input): void
    {
        Barrier::content($u->uid, ['kind' => 'review', 'id' => $input['reviewId']], static function () use ($u, $input): void {
            $v = Docs::review(Rows::review($input['reviewId'], true));
            if (! Policy::canFileCase($u->uid, $input['kind'], $v)) {
                throw new HttpError(403, 'Bu içerik için başvuru yapılamıyor.');
            }
            $bid = Schemas::id()->parse($v['businessId']);
            $version = $v['version'] ?? 1;
            $id = Ids::sha([$u->uid, $input['reviewId'], $input['kind'], $version]);
            $existing = DB::table('review_cases')->where('id', $id)->lockForUpdate()->exists();
            $b = Docs::business(Rows::business($bid, true));
            $p = Rows::publicRow($bid, $input['reviewId'], true);
            $conflict = static function (): never {
                throw new HttpError(409, 'Bu sürüm için başvurun zaten var. Başvurularım bölümünden takip et.');
            };
            if ($existing) {
                $conflict();
            }
            if ($input['kind'] === 'report' && (($b['published'] ?? null) !== true || ($b['sample'] ?? null) === true || $p === null)) {
                throw new HttpError(409, 'Bu içerik artık yayında değil.');
            }
            Rows::create('review_cases', ['id' => $id, 'review_id' => $input['reviewId'], 'business_id' => $bid, 'user_id' => $u->uid, 'kind' => $input['kind'], 'text' => $input['text'], 'review_version' => $version, 'status' => 'open', 'created_at' => Ids::now()], $conflict);
        });
    }
}
