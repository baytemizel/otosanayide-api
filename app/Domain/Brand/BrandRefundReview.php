<?php

namespace App\Domain\Brand;

use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** core/brand-refund-review.ts + server/brand-refund-review.ts: iade uyusmazligi idari incelemesi (para hareketi yok). */
final class BrandRefundReview
{
    private static function target(): array
    {
        return ['reservationId' => F7::uuid(), 'eventId' => Z::string()->regex('/^[A-Za-z0-9-]{1,128}$/')];
    }

    public static function query(): Z
    {
        return Z::strictObject(self::target() + ['before' => Z::string()->regex('/^[1-9]\d{0,8}$/')->transform(static fn (string $v): int => (int) $v)->optional()]);
    }

    public static function action(): Z
    {
        return Z::strictObject(self::target() + ['version' => Z::number()->int()->min(0)->max(999999998), 'status' => Z::enum(['reviewing', 'closed']), 'note' => Z::string()->trim()->min(10)->max(1000), 'acknowledged' => Z::boolean()->default(false)])
            ->refine(static fn (array $v): bool => $v['status'] !== 'closed' || $v['acknowledged']);
    }

    /** @return array{ref:string,current:?array,eventTime:int,receiptId:string} */
    private static function context(string $reservationId, string $eventId): array
    {
        $c = F7::lock('brand_cancellations', 'reservation_id', $reservationId);
        $h = DB::table('brand_cancellation_history')->where('reservation_id', $reservationId)->where('id', $eventId)->lockForUpdate()->first();
        if ($c === null) {
            throw new ZodError('invalid_type');
        }
        if ($c->reservation_id !== $reservationId || $c->status !== 'approved' || $h?->action !== 'refund_checked' || $h->outcome !== 'needs_review' || ! in_array($h->reason, ['amount_or_time', 'additional_refund'], true) || ! F7::isHash($h->receipt_id)) {
            throw new RuntimeException('target');
        }
        $receipt = F7::lock('brand_refund_receipts', 'id', $h->receipt_id);
        if ($receipt === null || $receipt->reservation_id !== $reservationId || $receipt->status !== 'succeeded' || $receipt->outcome !== 'needs_review' || $receipt->reason !== $h->reason || (int) $receipt->checked_at_ms !== (int) $h->created_at_ms || F7::sha([$receipt->provider, $receipt->merchant_id, $receipt->refund_id]) !== $h->receipt_id) {
            throw new RuntimeException('receipt');
        }
        $key = hash('sha256', 'brand-refund-review:'.$h->receipt_id);
        $row = F7::lock('brand_refund_reviews', 'id', $key);
        $current = $row === null ? null : ['reservationId' => $row->reservation_id, 'receiptId' => $row->receipt_id, 'version' => (int) $row->version, 'status' => $row->status, 'note' => $row->note, 'actorId' => $row->actor_id, 'acknowledged' => (bool) $row->acknowledged, 'updatedAtMs' => (int) $row->updated_at_ms];
        if ($current !== null && ($current['reservationId'] !== $reservationId || $current['receiptId'] !== $h->receipt_id)) {
            throw new RuntimeException('review_binding');
        }

        return ['ref' => $key, 'current' => $current, 'eventTime' => (int) $h->created_at_ms, 'receiptId' => $h->receipt_id];
    }

    public static function read(array $q): array
    {
        return Tx::run(static function () use ($q): array {
            ['ref' => $ref, 'current' => $current, 'receiptId' => $receiptId] = self::context($q['reservationId'], $q['eventId']);
            $version = $current['version'] ?? 0;
            $before = $q['before'] ?? null;
            if ($before !== null && $before > $version + 1) {
                throw new RuntimeException('cursor');
            }
            $end = $before === null ? $version : $before - 1;
            $versions = [];
            for ($i = 0; $i < min(20, $end); $i++) {
                $versions[] = $end - $i;
            }
            $rows = DB::table('brand_refund_review_history')->where('review_id', $ref)->whereIn('version', $versions === [] ? [0] : $versions)->get()->keyBy('version');
            $history = [];
            foreach ($versions as $v) {
                $h = $rows->get($v);
                if ($h === null || (int) $h->version !== $v || $h->reservation_id !== $q['reservationId'] || $h->receipt_id !== $receiptId || ($h->status === 'closed' && ! (bool) $h->acknowledged)) {
                    throw new RuntimeException('history');
                }
                $history[] = ['version' => (int) $h->version, 'previousStatus' => $h->previous_status, 'status' => $h->status, 'note' => $h->note, 'updatedAtMs' => (int) $h->updated_at_ms];
            }

            return ['reservationId' => $q['reservationId'], 'eventId' => $q['eventId'], 'status' => $current['status'] ?? 'open', 'version' => $version, 'note' => $current['note'] ?? '', 'updatedAtMs' => $current['updatedAtMs'] ?? null, 'history' => $history, 'next' => $end > 20 ? $end - 19 : null];
        });
    }

    public static function decide(string $actorId, array $a, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        Z::string()->min(1)->max(200)->parse($actorId);

        return FinanceBarrier::run($actorId, ['kind' => 'brand_cancellation', 'id' => $a['reservationId']], static function () use ($actorId, $a, $now): array {
            ['ref' => $ref, 'current' => $current, 'eventTime' => $eventTime, 'receiptId' => $receiptId] = self::context($a['reservationId'], $a['eventId']);
            $version = $current['version'] ?? 0;
            $status = $current['status'] ?? 'open';
            if ($now < $eventTime || ($current !== null && $now < $current['updatedAtMs'])) {
                throw new RuntimeException('time');
            }
            if ($current !== null && $version === $a['version'] + 1 && $current['status'] === $a['status'] && $current['note'] === $a['note'] && $current['acknowledged'] === $a['acknowledged'] && $current['actorId'] === $actorId) {
                $h = DB::table('brand_refund_review_history')->where('review_id', $ref)->where('version', $version)->first();
                if ($h === null || $h->actor_id !== $actorId || (int) $h->version !== $version || $h->status !== $a['status'] || $h->note !== $a['note'] || (bool) $h->acknowledged !== $a['acknowledged']) {
                    throw new RuntimeException('history');
                }

                return ['version' => $version, 'replayed' => true];
            }
            if ($version !== $a['version'] || ($status === 'open' && $a['status'] !== 'reviewing') || ($status === 'closed' && $a['status'] !== 'reviewing') || ($status === $a['status'] && ($current['note'] ?? null) === $a['note'])) {
                throw new RuntimeException('conflict');
            }
            $value = ['reservation_id' => $a['reservationId'], 'receipt_id' => $receiptId, 'version' => $version + 1, 'status' => $a['status'], 'note' => $a['note'], 'actor_id' => $actorId, 'acknowledged' => $a['acknowledged'], 'updated_at_ms' => $now];
            $current !== null ? DB::table('brand_refund_reviews')->where('id', $ref)->update($value) : DB::table('brand_refund_reviews')->insert(['id' => $ref] + $value);
            DB::table('brand_refund_review_history')->insert(['review_id' => $ref, 'previous_status' => $status] + $value);

            return ['version' => $version + 1, 'replayed' => false];
        });
    }
}
