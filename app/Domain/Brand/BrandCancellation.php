<?php

namespace App\Domain\Brand;

use App\Domain\Ads\AdRules;
use App\Domain\Content\Ids;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Exceptions\WriteConflict;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * core/brand-cancellation.ts + server/brand-cancellation.ts + brand-cancellation-queue.ts.
 * Guvenilir finans API cagrilari; para transferi veya otomatik iade YOKTUR.
 */
final class BrandCancellation
{
    public static function request(): Z
    {
        return Z::strictObject(['reservationId' => F7::uuid(), 'reason' => Z::string()->trim()->min(10)->max(1000)]);
    }

    public static function decision(): Z
    {
        return Z::strictObject(['reservationId' => F7::uuid(), 'version' => Z::number()->int()->positive(), 'decision' => Z::enum(['approve', 'reject']), 'note' => Z::string()->trim()->min(10)->max(1000)]);
    }

    public static function lookup(): Z
    {
        return Z::strictObject(['reservationId' => F7::uuid()]);
    }

    public static function refundResult(): Z
    {
        return Z::strictObject(['refundId' => Z::string()->min(1)->max(200), 'paymentId' => Z::string()->min(1)->max(200), 'merchantId' => Z::string()->min(1)->max(200), 'reservationId' => F7::uuid(), 'status' => Z::enum(['pending', 'failed', 'succeeded']), 'currency' => Z::string()->length(3), 'amountMinor' => Z::number()->int()->positive()->max(F7::SAFE), 'refundedAtMs' => Z::number()->int()->nonnegative()->nullable()]);
    }

    public const FILTERS = ['pending', 'approved', 'rejected', 'requires_review', 'needs_review', 'refunded', 'all'];

    public static function queueQuery(): Z
    {
        return Z::strictObject(['filter' => Z::enum(self::FILTERS)->default('pending'), 'after' => F7::uuid()->optional()]);
    }

    public static function historyQuery(): Z
    {
        return Z::strictObject(['reservationId' => F7::uuid(), 'after' => Z::string()->regex('/^\d{1,16}_[A-Za-z0-9-]{1,128}$/')->optional()])
            ->refine(static fn (array $v): bool => ! isset($v['after']) || F7::safeInt((int) explode('_', $v['after'])[0]));
    }

    /** brandCancellationRecord */
    private static function record(?stdClass $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $v = ['reservationId' => $r->reservation_id, 'campaignId' => $r->campaign_id, 'status' => $r->status, 'version' => (int) $r->version, 'requesterId' => $r->requester_id, 'reason' => $r->reason, 'createdAtMs' => (int) $r->created_at_ms, 'decidedAtMs' => $r->decided_at_ms === null ? null : (int) $r->decided_at_ms, 'reviewerId' => $r->reviewer_id, 'note' => $r->note];
        Z::strictObject(['reservationId' => F7::uuid(), 'campaignId' => F7::uuid(), 'status' => Z::enum(['pending', 'approved', 'rejected']), 'version' => Z::number()->int()->positive(), 'requesterId' => Z::string()->min(1)->max(200), 'reason' => Z::string()->min(10)->max(1000), 'createdAtMs' => Z::number()->int()->nonnegative(), 'decidedAtMs' => Z::number()->int()->nonnegative()->nullable(), 'reviewerId' => Z::string()->max(200), 'note' => Z::string()->max(1000)])->parse((object) $v);

        return $v;
    }

    /** brandAcceptanceRecord */
    public static function acceptanceRecord(?array $a): ?array
    {
        if ($a === null) {
            return null;
        }
        Z::strictObject(['reservationId' => F7::uuid(), 'campaignId' => F7::uuid(), 'campaignVersion' => Z::number()->int()->positive(), 'receiptId' => F7::hash(), 'placement' => Z::enum(BrandRules::PLACEMENTS), 'dates' => Z::array(Z::string())->min(1)->max(90), 'currency' => Z::literal('TRY'), 'totalMinor' => Z::number()->int()->positive(), 'status' => Z::literal('accepted'), 'acceptedAtMs' => Z::number()->int()->nonnegative(), 'committedUntilMs' => Z::number()->int()->positive()])->parse((object) $a);

        return $a;
    }

    private static function refundState(?stdClass $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $v = ['status' => $r->status, 'checkedAtMs' => (int) $r->checked_at_ms] + ($r->receipt_id !== null ? ['receiptId' => $r->receipt_id] : []);
        if ($v['status'] !== 'requires_review' && ! isset($v['receiptId'])) {
            throw new ZodError('custom', 'Invalid input');
        }

        return $v;
    }

    private static function refundReceipt(string $id): ?array
    {
        $r = F7::lock('brand_refund_receipts', 'id', $id);

        return $r === null ? null : ['reservationId' => $r->reservation_id, 'provider' => $r->provider, 'merchantId' => $r->merchant_id, 'refundId' => $r->refund_id, 'paymentId' => $r->payment_id, 'status' => $r->status, 'currency' => $r->currency, 'amountMinor' => (int) $r->amount_minor, 'refundedAtMs' => $r->refunded_at_ms === null ? null : (int) $r->refunded_at_ms, 'outcome' => $r->outcome, 'reason' => $r->reason, 'checkedAtMs' => (int) $r->checked_at_ms];
    }

    /** readBrandCancellation: transaction icinde cagrilabilir. */
    public static function readIn(string $actorId, string $id, int $now): array
    {
        $c = self::record(F7::lock('brand_cancellations', 'reservation_id', $id));
        $a = self::acceptanceRecord(BrandStore::acceptance($id));
        $r = BrandStore::reservation($id);
        $f = self::refundState(F7::lock('brand_refund_states', 'reservation_id', $id));
        if (($a !== null && $a['reservationId'] !== $id) || ($c !== null && ($a === null || $c['reservationId'] !== $id || $c['campaignId'] !== $a['campaignId'])) || ($f !== null && ($c['status'] ?? null) !== 'approved')) {
            throw new RuntimeException('binding');
        }
        $evidence = null;
        if (isset($f['receiptId'])) {
            $receipt = self::refundReceipt($f['receiptId']);
            if ($receipt === null || $receipt['reservationId'] !== $id || $receipt['outcome'] !== $f['status'] || $receipt['status'] !== 'succeeded' || $receipt['checkedAtMs'] !== $f['checkedAtMs'] || F7::sha([$receipt['provider'], $receipt['merchantId'], $receipt['refundId']]) !== $f['receiptId']) {
                throw new RuntimeException('refund_binding');
            }
            if ($f['status'] === 'refunded' && ($a === null || $receipt['currency'] !== $a['currency'] || $receipt['amountMinor'] !== $a['totalMinor'] || $receipt['reason'] !== '' || ! is_int($receipt['refundedAtMs']) || $c['decidedAtMs'] === null || $receipt['refundedAtMs'] < $c['decidedAtMs'] || $receipt['refundedAtMs'] > $receipt['checkedAtMs'])) {
                throw new RuntimeException('refund_evidence');
            }
            $evidence = ['status' => $receipt['outcome'], 'reason' => $receipt['reason'], 'currency' => $receipt['currency'], 'amountMinor' => $receipt['amountMinor'], 'refundedAtMs' => $receipt['refundedAtMs'], 'checkedAtMs' => $receipt['checkedAtMs']];
        }

        return ['reservationId' => $id,
            'eligible' => $c === null && $a !== null && $a['acceptedAtMs'] <= $now && ($r['status'] ?? null) === 'committed' && $r['campaignId'] === $a['campaignId'] && ($r['committedUntilMs'] ?? null) === $a['committedUntilMs'],
            'canReview' => ($c['status'] ?? null) === 'pending' && $c['requesterId'] !== $actorId, 'checkedAtMs' => $now,
            'cancellation' => $c !== null ? ['status' => $c['status'], 'version' => $c['version'], 'reason' => $c['reason'], 'createdAtMs' => $c['createdAtMs'], 'decidedAtMs' => $c['decidedAtMs'], 'note' => $c['note']] : null,
            'refund' => $f !== null ? ['status' => $f['status'], 'checkedAtMs' => $f['checkedAtMs'], 'evidence' => $evidence] : null];
    }

    public static function read(string $actorId, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $id = self::lookup()->parse($raw)['reservationId'];
        Z::string()->min(1)->max(200)->parse($actorId);

        return Tx::run(static fn (): array => self::readIn($actorId, $id, $now));
    }

    /** acceptance(): kesin kabul tutarliligi. */
    private static function acceptance(string $id): array
    {
        $a = self::acceptanceRecord(BrandStore::acceptance($id)) ?? throw new ZodError('invalid_type');
        $start = F7::trStart($a['dates'][0]);
        if ($a['reservationId'] !== $id || $start === null || BrandRules::dates($a['dates'][0], count($a['dates'])) !== $a['dates'] || $a['committedUntilMs'] !== $start + count($a['dates']) * F7::DAY || $a['acceptedAtMs'] >= $a['committedUntilMs']) {
            throw new RuntimeException('acceptance');
        }

        return $a;
    }

    private static function addHistory(string $id, array $v): void
    {
        DB::table('brand_cancellation_history')->insert(['id' => Ids::uuid(), 'reservation_id' => $id] + $v);
    }

    public static function requestCancellation(string $actorId, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $q = self::request()->parse($raw);
        Z::string()->min(1)->max(200)->parse($actorId);

        return FinanceBarrier::run($actorId, ['kind' => 'brand_order', 'id' => $q['reservationId']], static function () use ($actorId, $q, $now): array {
            $old = self::record(F7::lock('brand_cancellations', 'reservation_id', $q['reservationId']));
            $a = self::acceptance($q['reservationId']);
            if ($a['acceptedAtMs'] > $now) {
                throw new RuntimeException('time');
            }
            if ($old !== null) {
                if ($old['reservationId'] !== $q['reservationId'] || $old['campaignId'] !== $a['campaignId']) {
                    throw new RuntimeException('binding');
                }
                if ($old['requesterId'] !== $actorId || $old['reason'] !== $q['reason']) {
                    throw new RuntimeException('conflict');
                }

                return ['status' => $old['status'], 'version' => $old['version'], 'replayed' => true];
            }
            $r = BrandStore::reservation($q['reservationId']);
            if (($r['status'] ?? null) !== 'committed' || $r['campaignId'] !== $a['campaignId'] || ($r['committedUntilMs'] ?? null) !== $a['committedUntilMs']) {
                throw new RuntimeException('reservation');
            }
            DB::table('brand_cancellations')->insert(['reservation_id' => $q['reservationId'], 'campaign_id' => $a['campaignId'], 'status' => 'pending', 'version' => 1, 'requester_id' => $actorId, 'reason' => $q['reason'], 'created_at_ms' => $now, 'decided_at_ms' => null, 'reviewer_id' => '', 'note' => '']);
            self::addHistory($q['reservationId'], ['action' => 'requested', 'actor_id' => $actorId, 'created_at_ms' => $now]);

            return ['status' => 'pending', 'version' => 1, 'replayed' => false];
        });
    }

    public static function decide(string $actorId, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $q = self::decision()->parse($raw);
        Z::string()->min(1)->max(200)->parse($actorId);

        return FinanceBarrier::run($actorId, ['kind' => 'brand_cancellation', 'id' => $q['reservationId']], static function () use ($actorId, $q, $now): array {
            $id = $q['reservationId'];
            $v = self::record(F7::lock('brand_cancellations', 'reservation_id', $id)) ?? throw new ZodError('invalid_type');
            if ($v['reservationId'] !== $id || $v['status'] !== 'pending' || $v['version'] !== $q['version'] || $v['requesterId'] === $actorId || $now < $v['createdAtMs']) {
                throw new RuntimeException('review');
            }
            $a = self::acceptance($id);
            if ($v['campaignId'] !== $a['campaignId']) {
                throw new RuntimeException('binding');
            }
            if ($q['decision'] === 'approve') {
                $r = BrandStore::reservation($id);
                [, $anchor] = BrandStore::anchor($a['campaignId']);
                $refund = F7::lock('brand_refund_states', 'reservation_id', $id);
                if (($r['status'] ?? null) !== 'committed' || $r['campaignId'] !== $a['campaignId'] || $r['campaignVersion'] !== $a['campaignVersion'] || $r['totalMinor'] !== $a['totalMinor'] || $r['currency'] !== $a['currency'] || $r['placement'] !== $a['placement'] || ($r['committedUntilMs'] ?? null) !== $a['committedUntilMs'] || $r['dates'] !== $a['dates'] || $refund !== null) {
                    throw new RuntimeException('reservation');
                }
                // Tum gunler yazimdan once ayristirilir; diger rezervasyonlar korunur.
                $next = [];
                foreach ($a['dates'] as $d) {
                    [, $list] = BrandStore::day($a['placement'], $d);
                    $h = AdRules::holds($list, 0);
                    if (count(array_unique(array_column($h, 'reservationId'))) !== count($h)) {
                        throw new RuntimeException('inventory');
                    }
                    $next[$d] = array_values(array_filter($h, static fn (array $x): bool => $x['reservationId'] !== $id));
                }
                foreach ($next as $d => $list) {
                    BrandStore::putDay($a['placement'], $d, $list);
                }
                DB::table('brand_reservations')->where('id', $id)->update(['status' => 'released', 'released_at_ms' => $now, 'release_reason' => 'approved_cancellation']);
                if (($anchor['reservationId'] ?? null) === $id) {
                    DB::table('brand_reservation_anchors')->where('campaign_id', $a['campaignId'])->update(['expires_at_ms' => 0]);
                }
                DB::table('brand_refund_states')->insert(['reservation_id' => $id, 'status' => 'requires_review', 'checked_at_ms' => $now]);
            }
            $status = $q['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('brand_cancellations')->where('reservation_id', $id)->update(['status' => $status, 'version' => $v['version'] + 1, 'reviewer_id' => $actorId, 'note' => $q['note'], 'decided_at_ms' => $now]);
            self::addHistory($id, ['action' => $status, 'actor_id' => $actorId, 'note' => $q['note'], 'created_at_ms' => $now]);

            return ['status' => $status, 'version' => $v['version'] + 1];
        });
    }

    /**
     * verifyBrandRefund: dahili giris noktasi (HTTP ucu yok). Iade saglayicidan dogrudan sorgulanir.
     *
     * @param  Closure(string):mixed  $lookupRefund
     */
    public static function verifyRefund(string $id, string $provider, string $merchantId, Closure $lookupRefund, string $refundId, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($id);
        Z::string()->min(1)->max(200)->parse($refundId);
        Z::string()->regex('/^[a-z0-9_-]{1,40}$/')->parse($provider);
        Z::string()->min(1)->max(200)->parse($merchantId);
        $scope = ['kind' => 'brand_cancellation', 'id' => $id];
        $context = static function () use ($id, $provider, $merchantId, $scope): array {
            FinanceBarrier::assert(null, $scope);
            $c = self::record(F7::lock('brand_cancellations', 'reservation_id', $id)) ?? throw new ZodError('invalid_type');
            $b = BrandStore::binding($id);
            $a = self::acceptance($id);
            $r = BrandStore::reservation($id);
            if ($c['reservationId'] !== $id || $c['campaignId'] !== $a['campaignId'] || $c['status'] !== 'approved' || $c['decidedAtMs'] === null || ($b['provider'] ?? null) !== $provider || $b['merchantId'] !== $merchantId) {
                throw new RuntimeException('binding');
            }
            if (($r['status'] ?? null) !== 'released' || ($r['releaseReason'] ?? null) !== 'approved_cancellation' || $r['campaignId'] !== $a['campaignId']) {
                throw new RuntimeException('reservation');
            }
            $receipt = BrandStore::receipt($a['receiptId']);
            if (($receipt['recordId'] ?? null) !== $id || $receipt['outcome'] !== 'verified' || $receipt['reason'] !== '' || $receipt['provider'] !== $provider || $receipt['merchantId'] !== $merchantId || $receipt['amountMinor'] !== $a['totalMinor'] || $receipt['currency'] !== $a['currency'] || F7::sha([$provider, $merchantId, $receipt['paymentId']]) !== $a['receiptId']) {
                throw new RuntimeException('receipt');
            }

            return ['a' => $a, 'c' => $c, 'receipt' => $receipt, 'lookupBinding' => FinanceBarrier::lookupBinding('brand_cancellation', $id)];
        };
        $before = FinanceBarrier::run(null, $scope, $context);
        $result = self::refundResult()->parse($lookupRefund($refundId));
        if ($result['refundId'] !== $refundId || $result['reservationId'] !== $id || $result['merchantId'] !== $merchantId) {
            throw new RuntimeException('binding');
        }
        $receiptId = F7::sha([$provider, $merchantId, $refundId]);

        return Tx::run(static function () use ($context, $before, $result, $receiptId, $id, $provider, $merchantId, $now): array {
            ['a' => $a, 'c' => $c, 'receipt' => $receipt, 'lookupBinding' => $lb] = $context();
            if ($lb !== $before['lookupBinding']) {
                throw new WriteConflict('finance_write_conflict');
            }
            $old = self::refundReceipt($receiptId);
            $current = self::refundState(F7::lock('brand_refund_states', 'reservation_id', $id)) ?? throw new ZodError('invalid_type');
            if ($result['paymentId'] !== $receipt['paymentId']) {
                throw new RuntimeException('payment_binding');
            }
            if ($old !== null) {
                if ($old['reservationId'] !== $id) {
                    throw new RuntimeException('reuse');
                }

                return ['status' => $old['outcome'], 'replayed' => true];
            }
            if ($result['status'] !== 'succeeded') {
                return ['status' => $current['status'], 'replayed' => false];
            }
            $reason = '';
            if ($result['currency'] !== $a['currency'] || $result['amountMinor'] !== $a['totalMinor'] || $result['refundedAtMs'] === null || $result['refundedAtMs'] < $c['decidedAtMs'] || $result['refundedAtMs'] > $now) {
                $reason = 'amount_or_time';
            } elseif ($current['status'] === 'refunded' || $current['status'] === 'needs_review') {
                $reason = 'additional_refund';
            }
            $outcome = $reason !== '' ? 'needs_review' : 'refunded';
            DB::table('brand_refund_receipts')->insert(['id' => $receiptId, 'reservation_id' => $id, 'provider' => $provider, 'merchant_id' => $merchantId, 'refund_id' => $result['refundId'], 'payment_id' => $result['paymentId'], 'status' => $result['status'], 'currency' => $result['currency'], 'amount_minor' => $result['amountMinor'], 'refunded_at_ms' => $result['refundedAtMs'], 'outcome' => $outcome, 'reason' => $reason, 'checked_at_ms' => $now]);
            // Ikinci veya kismi iade daha once dogrulanmis tam iadenin uzerine yazmaz.
            if ($current['status'] !== 'refunded') {
                DB::table('brand_refund_states')->where('reservation_id', $id)->update(['status' => $outcome, 'receipt_id' => $receiptId, 'checked_at_ms' => $now]);
            }
            self::addHistory($id, ['action' => 'refund_checked', 'receipt_id' => $receiptId, 'outcome' => $outcome, 'reason' => $reason, 'created_at_ms' => $now]);

            return ['status' => $outcome, 'replayed' => false];
        });
    }

    /** listBrandCancellations: okunurken degisen filtre eslesmeleri gosterilmez. */
    public static function queue(string $actorId, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $q = self::queueQuery()->parse($raw);
        $financial = in_array($q['filter'], ['requires_review', 'needs_review', 'refunded'], true);
        $query = $financial ? DB::table('brand_refund_states') : DB::table('brand_cancellations');
        $query->orderBy('reservation_id')->limit(21);
        if ($q['filter'] !== 'all') {
            $query->where('status', $q['filter']);
        }
        if (isset($q['after'])) {
            $query->where('reservation_id', '>', $q['after']);
        }
        $docs = $query->pluck('reservation_id')->all();
        $scanned = array_slice($docs, 0, 20);
        $items = [];
        foreach ($scanned as $id) {
            $v = self::read($actorId, (object) ['reservationId' => $id], $now);
            if ($v['cancellation'] !== null && ($q['filter'] === 'all' || ($financial ? ($v['refund']['status'] ?? null) : $v['cancellation']['status']) === $q['filter'])) {
                $items[] = $v;
            }
        }

        return ['filter' => $q['filter'], 'items' => $items, 'next' => count($docs) > 20 ? end($scanned) : null];
    }

    public static function history(string $actorId, mixed $raw): array
    {
        $q = self::historyQuery()->parse($raw);
        $current = self::read($actorId, (object) ['reservationId' => $q['reservationId']]);
        if ($current['cancellation'] === null) {
            throw new RuntimeException('missing');
        }
        $query = DB::table('brand_cancellation_history')->where('reservation_id', $q['reservationId'])->orderByDesc('created_at_ms')->orderByDesc('id')->limit(21);
        if (isset($q['after'])) {
            [$time, $hid] = explode('_', $q['after'], 2);
            $t = (int) $time;
            $query->where(static fn ($w) => $w->where('created_at_ms', '<', $t)->orWhere(static fn ($x) => $x->where('created_at_ms', $t)->where('id', '<', $hid)));
        }
        $docs = $query->get()->all();
        $scanned = array_slice($docs, 0, 20);
        $items = [];
        foreach ($scanned as $h) {
            $amount = null;
            $currency = null;
            if ($h->action === 'refund_checked') {
                if (! F7::isHash($h->receipt_id)) {
                    throw new RuntimeException('receipt');
                }
                $receipt = F7::row('brand_refund_receipts', 'id', $h->receipt_id);
                if ($receipt === null || $receipt->reservation_id !== $q['reservationId'] || $receipt->outcome !== $h->outcome || $receipt->reason !== $h->reason || (int) $receipt->checked_at_ms !== (int) $h->created_at_ms || $receipt->status !== 'succeeded' || F7::sha([$receipt->provider, $receipt->merchant_id, $receipt->refund_id]) !== $h->receipt_id) {
                    throw new RuntimeException('receipt');
                }
                $amount = (int) $receipt->amount_minor;
                $currency = $receipt->currency;
            }
            if (! in_array($h->action, ['requested', 'approved', 'rejected', 'refund_checked'], true)) {
                throw new ZodError('invalid_value');
            }
            $items[] = ($h->action === 'refund_checked' && $h->outcome === 'needs_review' ? ['reviewEventId' => $h->id] : []) + ['action' => $h->action, 'createdAtMs' => (int) $h->created_at_ms, 'note' => is_string($h->note) ? $h->note : '', 'outcome' => $h->action === 'refund_checked' ? $h->outcome : null, 'reason' => $h->action === 'refund_checked' ? $h->reason : '', 'amountMinor' => $amount, 'currency' => $currency];
        }
        $last = end($scanned);

        return ['reservationId' => $q['reservationId'], 'items' => $items, 'next' => count($docs) > 20 && $last !== false ? $last->created_at_ms.'_'.$last->id : null];
    }
}
