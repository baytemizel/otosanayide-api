<?php

namespace App\Domain\Payments;

use App\Exceptions\HttpError;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/** core/payment-cases.ts + /api/admin/payment-cases (isletme reklami odeme uyusmazliklari). */
final class PaymentCases
{
    public const STATUSES = ['open', 'reviewing', 'refund_required', 'closed'];

    public const REASONS = ['amount_or_capture' => 'Tutar, para birimi veya ödeme zamanı uyuşmuyor', 'additional_payment' => 'Ek ödeme alındı', 'expired_or_released' => 'Rezervasyon süresi dolmuş veya bırakılmış', 'eligibility_changed' => 'İşletme veya kampanya uygunluğu değişmiş', 'inventory' => 'Kapasite verisi uyuşmuyor', 'capacity_lost' => 'Ayrılan kapasite bulunamadı'];

    public static function query(): Z
    {
        return Z::strictObject(['after' => F7::hash()->optional()]);
    }

    public static function action(): Z
    {
        return Z::strictObject(['id' => F7::hash(), 'version' => Z::number()->int()->nonnegative(), 'status' => Z::enum(self::STATUSES), 'note' => Z::string()->trim()->min(10)->max(1000)]);
    }

    /** paymentCaseView */
    public static function view(string $id, array $d): array
    {
        $version = $d['version'] ?? null;

        return ['id' => $id, 'status' => in_array($d['status'] ?? null, self::STATUSES, true) ? $d['status'] : 'open', 'version' => F7::safeInt($version) && $version >= 0 ? $version : 0,
            'reason' => is_string($d['reason'] ?? null) && isset(self::REASONS[$d['reason']]) ? $d['reason'] : 'unknown', 'createdAtMs' => is_int($d['createdAtMs'] ?? null) ? $d['createdAtMs'] : 0,
            'note' => is_string($d['note'] ?? null) ? mb_substr($d['note'], 0, 1000) : ''];
    }

    /** changePaymentCase */
    public static function change(array $d, array $input, int $now): array
    {
        $previous = self::view($input['id'], $d);
        if ($previous['version'] !== $input['version']) {
            throw new RuntimeException('conflict');
        }
        if ($previous['status'] === $input['status']) {
            throw new RuntimeException('state');
        }

        return ['status' => $input['status'], 'version' => $input['version'] + 1, 'note' => $input['note'], 'updatedAtMs' => $now];
    }

    /** paymentCaseAmounts */
    public static function amounts(?array $order, ?array $receipt): array
    {
        $money = static fn (?array $d, string $field): ?array => $d !== null && F7::safeInt($d[$field] ?? null) && $d[$field] >= 0 && is_string($d['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $d['currency']) === 1 ? ['minor' => $d[$field], 'currency' => $d['currency']] : null;

        return ['expected' => $money($order, 'totalMinor'), 'received' => $money($receipt, 'amountMinor')];
    }

    public static function doc(stdClass $r): array
    {
        return array_filter(['orderId' => $r->order_id, 'receiptId' => $r->receipt_id, 'reason' => $r->reason, 'status' => $r->status, 'version' => $r->version === null ? null : (int) $r->version, 'note' => $r->note, 'createdAtMs' => (int) $r->created_at_ms], static fn (mixed $v): bool => $v !== null);
    }

    public static function list(?string $after): array
    {
        $q = DB::table('ad_payment_cases')->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->get()->all();
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $c = self::doc($row);
            $order = is_string($c['orderId'] ?? null) && preg_match('/^[a-f0-9-]{36}$/D', $c['orderId']) === 1 ? F7::row('ad_orders', 'id', $c['orderId']) : null;
            $receipt = is_string($c['receiptId'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $c['receiptId']) === 1 ? F7::row('ad_payment_receipts', 'id', $c['receiptId']) : null;
            $items[] = self::view($row->id, $c) + self::amounts($order === null ? null : ['totalMinor' => (int) $order->total_minor, 'currency' => $order->currency], $receipt === null ? null : ['amountMinor' => (int) $receipt->amount_minor, 'currency' => $receipt->currency]);
        }

        return ['items' => $items, 'next' => count($rows) > 20 ? $rows[19]->id : null];
    }

    public static function decide(string $uid, array $input): array
    {
        return FinanceBarrier::run($uid, ['kind' => 'ad_case', 'id' => $input['id']], static function () use ($uid, $input): array {
            $row = F7::lock('ad_payment_cases', 'id', $input['id']);
            if ($row === null) {
                throw new HttpError(404, 'Kayıt bulunamadı.');
            }
            $d = self::doc($row);
            try {
                $patch = self::change($d, $input, F7::nowMs());
            } catch (Throwable) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }
            DB::table('ad_payment_cases')->where('id', $input['id'])->update(['status' => $patch['status'], 'version' => $patch['version'], 'note' => $patch['note'], 'updated_at_ms' => $patch['updatedAtMs']]);
            DB::table('ad_payment_case_history')->insert(['case_id' => $input['id'], 'status' => $patch['status'], 'previous_status' => $d['status'], 'version' => $patch['version'], 'note' => $patch['note'], 'actor_id' => $uid, 'updated_at_ms' => $patch['updatedAtMs']]);

            return self::view($input['id'], array_merge($d, $patch));
        });
    }
}
