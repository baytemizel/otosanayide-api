<?php

namespace App\Domain\Brand;

use App\Domain\Ads\AdRules;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** server/brand-acceptance.ts: dogrulanmis odemeden sonra kapasitenin kesinlestirilmesi. Yayin baslatmaz. */
final class BrandAcceptance
{
    public static function view(string $id, ?array $raw): array
    {
        $v = BrandCancellation::acceptanceRecord($raw) ?? throw new RuntimeException('acceptance');
        if ($v['reservationId'] !== $id || $v['committedUntilMs'] <= $v['acceptedAtMs']) {
            throw new RuntimeException('acceptance');
        }

        return ['reservationId' => $id, 'status' => $v['status'], 'acceptedAtMs' => $v['acceptedAtMs'], 'committedUntilMs' => $v['committedUntilMs'], 'currency' => $v['currency'], 'totalMinor' => $v['totalMinor']];
    }

    public static function accept(string $actorId, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $id = BrandRules::orderInput()->parse($raw)['reservationId'];
        Z::string()->min(1)->max(200)->parse($actorId);

        return FinanceBarrier::run($actorId, ['kind' => 'brand_order', 'id' => $id], static function () use ($actorId, $id, $now): array {
            $old = BrandStore::acceptance($id);
            $orderRaw = BrandStore::order($id);
            $r = BrandStore::reservation($id);
            $state = BrandStore::paymentState($id);
            $binding = BrandStore::binding($id);
            if ($old !== null) {
                if ($old['reservationId'] !== $id) {
                    throw new RuntimeException('binding');
                }

                return ['reservationId' => $id, 'status' => 'accepted', 'replayed' => true];
            }
            $order = BrandRules::orderRecord($orderRaw ?? []);
            if ($order['reservationId'] !== $id || ($state['status'] ?? null) !== 'verified' || ! F7::isHash($state['primaryReceiptId'] ?? null)) {
                throw new RuntimeException('payment');
            }
            $receiptId = $state['primaryReceiptId'];
            $receipt = BrandStore::receipt($receiptId);
            if ($receipt === null || $receipt['recordId'] !== $id || $receipt['outcome'] !== 'verified' || $receipt['reason'] !== '' || $receipt['amountMinor'] !== $order['totalMinor'] || $receipt['currency'] !== $order['currency'] || $binding === null || $receipt['provider'] !== $binding['provider'] || $receipt['merchantId'] !== $binding['merchantId'] || F7::sha([$receipt['provider'], $receipt['merchantId'], $receipt['paymentId']]) !== $receiptId) {
                throw new RuntimeException('receipt');
            }
            $captured = $receipt['capturedAtMs'];
            if (! is_int($captured) || $captured < $order['createdAtMs'] || $captured >= $order['expiresAtMs'] || $captured > $now) {
                throw new RuntimeException('capture');
            }
            if (BrandOrders::inspect($id, $r, $order, $now) !== 'prepared') {
                throw new RuntimeException('reservation');
            }
            $dates = $r['dates'];
            $placement = $r['placement'];
            $until = F7::trStart(end($dates)) + F7::DAY;
            if ($until <= $now) {
                throw new RuntimeException('date');
            }
            $capacity = BrandRules::capacity()->parse(($c = BrandStore::capacity($placement)) === null ? null : (object) $c);
            $next = [];
            foreach ($dates as $d) {
                [, $list] = BrandStore::day($placement, $d);
                $holds = AdRules::holds($list, $now);
                if (count($holds) > $capacity['capacity'] || count(array_filter($holds, static fn (array $h): bool => $h['reservationId'] === $id)) !== 1) {
                    throw new RuntimeException('inventory');
                }
                $next[$d] = array_map(static fn (array $h): array => $h['reservationId'] === $id ? ['reservationId' => $id, 'expiresAtMs' => $until] : $h, $holds);
            }
            foreach ($next as $d => $list) {
                BrandStore::putDay($placement, $d, $list);
            }
            DB::table('brand_reservations')->where('id', $id)->update(['status' => 'committed', 'committed_until_ms' => $until]);
            BrandStore::putAnchor($order['campaignId'], $id, $until);
            DB::table('brand_acceptances')->insert(['reservation_id' => $id, 'campaign_id' => $order['campaignId'], 'campaign_version' => $order['campaignVersion'], 'receipt_id' => $receiptId, 'placement' => $placement, 'dates' => F7::json($dates), 'currency' => $order['currency'], 'total_minor' => $order['totalMinor'], 'status' => 'accepted', 'accepted_at_ms' => $now, 'committed_until_ms' => $until]);
            DB::table('brand_acceptance_history')->insert(['reservation_id' => $id, 'action' => 'accepted', 'actor_id' => $actorId, 'created_at_ms' => $now]);

            return ['reservationId' => $id, 'status' => 'accepted', 'replayed' => false];
        });
    }

    public static function read(mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $id = BrandRules::orderInput()->parse($raw)['reservationId'];

        return Tx::run(static function () use ($id, $now): array {
            $a = BrandStore::acceptance($id);
            $s = BrandStore::paymentState($id);
            $o = BrandStore::order($id);
            $r = BrandStore::reservation($id);
            $acceptance = $a !== null ? self::view($id, $a) : null;
            $payment = BrandReview::ledger($id, $s);
            $eligible = false;
            $order = $o !== null ? BrandRules::orderRecord($o) : null;
            if ($order !== null && $order['reservationId'] !== $id) {
                throw new RuntimeException('binding');
            }
            if ($acceptance === null && $order !== null && $payment['status'] === 'verified') {
                $eligible = BrandOrders::inspect($id, $r, $order, $now) === 'prepared';
            }

            return ['reservationId' => $id, 'acceptance' => $acceptance, 'payment' => $payment, 'eligible' => $eligible, 'expiresAtMs' => $order['expiresAtMs'] ?? null, 'checkedAtMs' => $now];
        });
    }
}
