<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;

/** /api/claims kurallari. */
final class Claims
{
    /** @param array<string,mixed> $v claimSchema ciktisi */
    public static function submit(Actor $u, array $v): string
    {
        $id = Ids::auto();
        $key = hash('sha256', Ids::stringify([$u->uid, $v['businessId'] ?? implode('|', [$v['name'], $v['city'], $v['address']])]));
        Barrier::content($u->uid, ['kind' => 'actor'], static function () use ($u, $v, $id, $key): void {
            // Kilit belgesinin karsiligi: ayni anahtarla en son acilan basvuru.
            $old = DB::table('claims')->where('lock_key', $key)->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->user_id !== $u->uid) {
                    throw new HttpError(409, 'Başvuru hesabı incelenmeli.');
                }
                if (in_array($old->status, ['submitted', 'needs_information'], true)) {
                    throw new HttpError(409, 'Bu işletme için açık başvurun var. Başvurularım bölümünden takip et.');
                }
            }
            if (($v['businessId'] ?? null) !== null) {
                $b = Rows::business($v['businessId'], true);
                if ($b === null || (bool) $b->sample || ! (bool) $b->published) {
                    throw new HttpError(404, 'Başvuruya uygun işletme kaydı bulunamadı.');
                }
                if ($b->ownership === 'verified' || ($b->owner_uid !== null && $b->owner_uid !== '')) {
                    throw new HttpError(409, 'Bu işletmenin sahipliği ayrıca incelenmeli.');
                }
            }
            Rows::create('claims', ['id' => $id, 'user_id' => $u->uid, 'business_id' => $v['businessId'] ?? null, 'name' => $v['name'], 'city' => $v['city'], 'address' => $v['address'], 'contact_name' => $v['contactName'], 'phone' => $v['phone'], 'note' => $v['note'], 'status' => 'submitted', 'lock_key' => $key, 'created_at' => Ids::now()],
                static function (): never {
                    // open_lock_key UNIQUE: eszamanli ikinci basvuru.
                    throw new HttpError(409, 'Bu işletme için açık başvurun var. Başvurularım bölümünden takip et.');
                });
        });

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function own(string $uid): array
    {
        return DB::table('claims')->where('user_id', $uid)->orderBy('id')->limit(50)->get()
            ->map(static fn (\stdClass $d): array => Policy::ownClaimView($d->id, Docs::claim($d)))->all();
    }

    /** @param array{claimId:string,message:string} $input */
    public static function reply(Actor $u, array $input): void
    {
        Barrier::content($u->uid, ['kind' => 'claim', 'id' => $input['claimId']], static function () use ($u, $input): void {
            $row = Rows::claim($input['claimId'], true);
            if (! Policy::canReplyToClaim($u->uid, Docs::claim($row))) {
                throw new HttpError(409, 'Yanıt bekleyen başvuru bulunamadı veya durumu değişti.');
            }
            DB::table('claims')->where('id', $input['claimId'])->update(['reply' => $input['message'], 'status' => 'submitted', 'evidence_revision' => ((int) ($row->evidence_revision ?? 0)) + 1, 'updated_at' => Ids::now()]);
            Rows::history('claim_history', 'claim_id', $input['claimId'], ['kind' => 'claimant_reply', 'actor_id' => $u->uid, 'details' => Ids::stringify(['from' => 'needs_information', 'to' => 'submitted', 'message' => $input['message']]), 'created_at' => Ids::now()]);
        });
    }
}
