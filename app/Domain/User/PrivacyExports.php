<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * web-10: veri kopyasi talebi. Otomatik paket uretimi YOK: talep yonetici gizlilik kuyruguna duser, operator
 * kopyayi hazirlayip kanal disi teslim eder ve durumu isaretler. Talep vermek kopyanin hazirlandigi anlamina gelmez.
 */
final class PrivacyExports
{
    public const OPEN = ['submitted', 'in_progress'];

    public static function view(stdClass $r): array
    {
        return ['id' => $r->id, 'status' => $r->status, 'version' => (int) $r->version, 'reason' => $r->reason, 'createdAtMs' => (int) $r->created_at_ms, 'updatedAtMs' => (int) $r->updated_at_ms];
    }

    public static function list(string $uid): array
    {
        $rows = DB::table('privacy_exports')->where('owner_uid', $uid)->orderByDesc('created_at_ms')->orderBy('id')->limit(5)->get();

        return ['items' => $rows->map(fn (stdClass $r) => self::view($r))->all(), 'preparation' => 'manual'];
    }

    /** Ayni anda tek acik talep; ayni operationId tekrari ayni kaydi dondurur. @return array{created:bool,request:array<string,mixed>} */
    public static function submit(string $uid, string $operationId, int $now): array
    {
        return Tx::run(static function () use ($uid, $operationId, $now): array {
            AccountFence::assertWritable($uid);
            DB::table('auth_accounts')->where('id', $uid)->lockForUpdate()->first();
            $same = DB::table('privacy_exports')->where('id', $operationId)->first();
            if ($same !== null) {
                if ($same->owner_uid !== $uid) {
                    throw new HttpError(409, 'Talep durumu değişti. Yeniden kontrol et.');
                }

                return ['created' => false, 'request' => self::view($same)];
            }
            if (DB::table('privacy_exports')->where('owner_uid', $uid)->whereIn('status', self::OPEN)->exists()) {
                throw new HttpError(409, 'Açık bir veri kopyası talebin var.');
            }
            if (DB::table('privacy_exports')->where('owner_uid', $uid)->where('created_at_ms', '>', $now - 86400000)->exists()) {
                throw new HttpError(429, 'Günde bir veri kopyası talebi gönderebilirsin.');
            }
            DB::table('privacy_exports')->insert(['id' => $operationId, 'owner_uid' => $uid, 'status' => 'submitted', 'version' => 1, 'created_at_ms' => $now, 'updated_at_ms' => $now]);

            return ['created' => true, 'request' => self::view(DB::table('privacy_exports')->where('id', $operationId)->first())];
        });
    }

    public static function cancel(string $uid, string $id, int $expectedVersion): array
    {
        return Tx::run(static function () use ($uid, $id, $expectedVersion): array {
            $r = DB::table('privacy_exports')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || $r->owner_uid !== $uid) {
                throw new HttpError(404, 'Talep bulunamadı.');
            }
            if ((int) $r->version !== $expectedVersion || $r->status !== 'submitted') {
                throw new HttpError(409, 'Talep durumu değişti. Yeniden kontrol et.');
            }
            DB::table('privacy_exports')->where('id', $id)->update(['status' => 'cancelled', 'version' => $expectedVersion + 1, 'updated_at_ms' => Js::nowMs()]);

            return self::view(DB::table('privacy_exports')->where('id', $id)->first());
        });
    }

    public static function adminSchema(): Z
    {
        return Z::strictObject(['kind' => Z::literal('export'), 'requestId' => Z::string()->uuid(), 'expectedVersion' => Z::number()->int()->positive(), 'action' => Z::enum(['start', 'deliver', 'reject']), 'reason' => Z::enum(['identity_unverified', 'duplicate', 'legal_hold', 'other'])->optional()])
            ->refine(static fn (array $v): bool => ($v['action'] === 'reject') === isset($v['reason']), 'reason');
    }

    /** @param array{requestId:string,expectedVersion:int,action:string,reason?:string} $in */
    public static function decide(string $adminUid, array $in): array
    {
        return Tx::run(static function () use ($adminUid, $in): array {
            $r = DB::table('privacy_exports')->where('id', $in['requestId'])->lockForUpdate()->first();
            $from = ['start' => 'submitted', 'deliver' => 'in_progress', 'reject' => null];
            if ($r === null || (int) $r->version !== $in['expectedVersion'] || ! in_array($r->status, self::OPEN, true) || ($from[$in['action']] !== null && $r->status !== $from[$in['action']])) {
                throw new HttpError(409, 'Talep durumu değişti. Yeniden kontrol et.');
            }
            if ($r->owner_uid === $adminUid) {
                throw new HttpError(403, 'Kendi talebini işleyemezsin.');
            }
            $to = ['start' => 'in_progress', 'deliver' => 'delivered', 'reject' => 'rejected'][$in['action']];
            DB::table('privacy_exports')->where('id', $r->id)->update(['status' => $to, 'version' => (int) $r->version + 1, 'reason' => $in['reason'] ?? null, 'handled_by' => $adminUid, 'updated_at_ms' => Js::nowMs()]);

            return self::view(DB::table('privacy_exports')->where('id', $r->id)->first()) + ['ownerUid' => $r->owner_uid];
        });
    }

    public static function queue(string $status, ?string $after): array
    {
        $q = DB::table('privacy_exports')->where('status', $status);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->orderBy('id')->limit(51)->get();
        $page = $rows->take(50);

        return ['items' => $page->map(fn (stdClass $r) => self::view($r) + ['ownerUid' => $r->owner_uid])->values()->all(), 'next' => $rows->count() > 50 ? $page->last()->id : null];
    }
}
