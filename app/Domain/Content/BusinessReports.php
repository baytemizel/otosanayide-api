<?php

namespace App\Domain\Content;

use App\Domain\Account\AccountFence;
use App\Domain\Catalogue\BusinessReader;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use stdClass;

/** web-04: yanlis bilgi bildirimi. Profili DEGISTIRMEZ; yonetici kuyruguna duser. Yalniz dogrulanmis uye gonderir. */
final class BusinessReports
{
    public const REASONS = ['closed', 'moved', 'phone', 'address', 'location', 'hours', 'services', 'other'];

    public static function schema(): Z
    {
        return Z::strictObject(['reason' => Z::enum(self::REASONS), 'note' => Z::string()->trim()->max(1000)->optional(), 'operationId' => Z::string()->uuid()])
            ->refine(static fn (array $v): bool => $v['reason'] !== 'other' || \App\Support\Js::length($v['note'] ?? '') >= 10, 'note');
    }

    /** @param array{reason:string,note?:string,operationId:string} $in @return array{created:bool,report:array<string,mixed>} */
    public static function submit(string $uid, string $businessId, array $in): array
    {
        $note = $in['note'] ?? '';
        if (! TextRules::acceptable($note)) {
            throw new HttpError(400, 'Notta bağlantı, iletişim bilgisi veya uygunsuz ifade olmamalı.');
        }

        return Tx::run(static function () use ($uid, $businessId, $in, $note): array {
            AccountFence::assertWritable($uid);
            $same = DB::table('business_reports')->where('operation_id', $in['operationId'])->lockForUpdate()->first();
            if ($same !== null) {
                if ($same->reporter_uid !== $uid || $same->business_id !== $businessId || $same->reason !== $in['reason'] || $same->note !== $note) {
                    throw new HttpError(409, 'İşlem anahtarı farklı içerikte kullanılmış.');
                }

                return ['created' => false, 'report' => self::ownView($same)];
            }
            DB::table('businesses')->where('id', $businessId)->sharedLock()->first();
            $record = BusinessReader::records([$businessId])[$businessId] ?? null;
            if ($record === null || BusinessReader::publicBusiness($businessId, $record) === null) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            $open = DB::table('business_reports')->where('business_id', $businessId)->where('reporter_uid', $uid)->where('status', 'submitted')->lockForUpdate()->first();
            if ($open !== null) {
                throw new HttpError(409, 'Bu işletme için açık bir bildirimin var.');
            }
            $now = Js::nowMs();
            DB::table('business_reports')->insert(['id' => $in['operationId'], 'business_id' => $businessId, 'reporter_uid' => $uid, 'operation_id' => $in['operationId'], 'reason' => $in['reason'], 'note' => $note, 'status' => 'submitted', 'version' => 1, 'created_at_ms' => $now, 'updated_at_ms' => $now]);

            return ['created' => true, 'report' => self::ownView(DB::table('business_reports')->where('id', $in['operationId'])->first())];
        });
    }

    private static function ownView(stdClass $r): array
    {
        return ['id' => $r->id, 'businessId' => $r->business_id, 'reason' => $r->reason, 'status' => $r->status, 'createdAtMs' => (int) $r->created_at_ms];
    }

    public static function queue(string $status, ?string $after): array
    {
        $q = DB::table('business_reports')->where('status', $status);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->orderBy('id')->limit(51)->get();
        $page = $rows->take(50);
        $names = DB::table('businesses')->whereIn('id', $page->pluck('business_id'))->pluck('name', 'id');

        return ['items' => $page->map(fn (stdClass $r) => ['id' => $r->id, 'businessId' => $r->business_id, 'businessName' => $names[$r->business_id] ?? null, 'reason' => $r->reason, 'note' => $r->note, 'status' => $r->status, 'version' => (int) $r->version, 'resolution' => $r->resolution, 'createdAtMs' => (int) $r->created_at_ms, 'updatedAtMs' => (int) $r->updated_at_ms])->values()->all(), 'next' => $rows->count() > 50 ? $page->last()->id : null];
    }

    public static function decisionSchema(): Z
    {
        return Z::strictObject(['id' => Z::string()->uuid(), 'expectedVersion' => Z::number()->int()->positive(), 'decision' => Z::enum(['resolved', 'dismissed']), 'resolution' => Z::string()->trim()->min(10)->max(1000)]);
    }

    /** Yonetici karari yalniz bildirimi kapatir; profil duzeltmesi ayri (business-changes / katalog) akistadir. */
    public static function decide(string $adminUid, array $in): void
    {
        Tx::run(static function () use ($adminUid, $in): void {
            $r = DB::table('business_reports')->where('id', $in['id'])->lockForUpdate()->first();
            if ($r === null || $r->status !== 'submitted' || (int) $r->version !== $in['expectedVersion']) {
                throw new HttpError(409, 'İnceleme değişti veya sonuçlandı.');
            }
            if ($r->reporter_uid === $adminUid) {
                throw new HttpError(403, 'Kendi bildirimini değerlendiremezsin.');
            }
            DB::table('business_reports')->where('id', $r->id)->update(['status' => $in['decision'], 'version' => (int) $r->version + 1, 'resolution' => $in['resolution'], 'reviewed_by' => $adminUid, 'updated_at_ms' => Js::nowMs()]);
        });
    }
}
