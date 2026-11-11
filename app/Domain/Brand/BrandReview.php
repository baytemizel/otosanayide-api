<?php

namespace App\Domain\Brand;

use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Domain\Payments\PaymentCases;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/** core/brand-payment-review.ts + server/brand-payment-review.ts + /api/admin/brand-payment-review. */
final class BrandReview
{
    public const REASONS = ['amount_or_capture', 'additional_payment', 'expired', 'cancelled', 'invalidated'];

    /** brandReviewQuery: union(after | recordId | caseId+before) */
    public static function query(): Z
    {
        return Z::union([
            Z::strictObject(['after' => F7::hash()->optional()]),
            Z::strictObject(['recordId' => F7::uuid()]),
            Z::strictObject(['caseId' => F7::hash(), 'before' => Z::coerceNumber()->int()->positive()->optional()]),
        ]);
    }

    public static function ledger(string $recordId, ?array $state): array
    {
        $checked = $state['checkedAtMs'] ?? null;

        return ['recordId' => $recordId, 'status' => in_array($state['status'] ?? null, ['pending', 'failed', 'verified', 'needs_review'], true) ? $state['status'] : 'unknown', 'checkedAtMs' => F7::safeInt($checked) && $checked >= 0 ? $checked : null];
    }

    /** brandReviewView */
    public static function view(string $id, array $c, ?array $order = null, ?array $receipt = null, ?array $state = null): array
    {
        $recordId = F7::isUuid($c['recordId'] ?? null) ? $c['recordId'] : null;
        $bound = $recordId !== null && ($c['receiptId'] ?? null) === $id && ($receipt['recordId'] ?? null) === $c['recordId'];
        $version = $c['version'] ?? null;
        $created = $c['createdAtMs'] ?? null;

        return ['id' => $id, 'recordId' => $recordId, 'status' => in_array($c['status'] ?? null, PaymentCases::STATUSES, true) ? $c['status'] : 'unknown', 'version' => F7::safeInt($version) && $version >= 0 ? $version : 0,
            'note' => is_string($c['note'] ?? null) ? mb_substr($c['note'], 0, 1000) : '', 'reason' => in_array($c['reason'] ?? null, self::REASONS, true) ? $c['reason'] : 'unknown',
            'createdAtMs' => F7::safeInt($created) && $created >= 0 ? $created : null]
            + PaymentCases::amounts($recordId !== null && ($order['reservationId'] ?? null) === $c['recordId'] ? $order : null, $bound ? $receipt : null)
            + ['payment' => self::ledger($recordId ?? '', $recordId !== null ? $state : null)];
    }

    /** brandReviewChange */
    public static function change(array $c, mixed $raw, int $now): array
    {
        $a = PaymentCases::action()->parse($raw);
        $v = $c['version'] ?? 0;
        if (! in_array($c['status'] ?? null, PaymentCases::STATUSES, true) || ! F7::safeInt($v) || $v < 0) {
            throw new RuntimeException('corrupt');
        }
        if ($v !== $a['version']) {
            throw new RuntimeException('conflict');
        }
        if ($c['status'] === $a['status']) {
            throw new RuntimeException('state');
        }

        return ['status' => $a['status'], 'version' => $a['version'] + 1, 'note' => $a['note'], 'updatedAtMs' => $now];
    }

    public static function historyView(array $d): array
    {
        return ['version' => (int) ($d['version'] ?? 0), 'status' => in_array($d['status'] ?? null, PaymentCases::STATUSES, true) ? (string) $d['status'] : 'unknown', 'previousStatus' => in_array($d['previousStatus'] ?? null, PaymentCases::STATUSES, true) ? (string) $d['previousStatus'] : 'unknown', 'note' => is_string($d['note'] ?? null) ? mb_substr($d['note'], 0, 1000) : '', 'updatedAtMs' => is_int($d['updatedAtMs'] ?? null) ? $d['updatedAtMs'] : 0];
    }

    private static function doc(stdClass $r): array
    {
        return array_filter(['recordId' => $r->record_id, 'receiptId' => $r->receipt_id, 'reason' => $r->reason, 'status' => $r->status, 'version' => $r->version === null ? null : (int) $r->version, 'note' => $r->note, 'createdAtMs' => (int) $r->created_at_ms], static fn (mixed $v): bool => $v !== null);
    }

    private static function historyDoc(?stdClass $h): ?array
    {
        return $h === null ? null : ['version' => (int) $h->version, 'status' => $h->status, 'previousStatus' => $h->previous_status, 'note' => $h->note, 'actorId' => $h->actor_id, 'updatedAtMs' => (int) $h->updated_at_ms];
    }

    public static function read(array $input): array
    {
        return Tx::run(static function () use ($input): array {
            if (array_key_exists('caseId', $input)) {
                $q = DB::table('brand_payment_case_history')->where('case_id', $input['caseId'])->orderByDesc('version')->limit(21);
                if (isset($input['before'])) {
                    $q->where('version', '<', $input['before']);
                }
                $rows = $q->get()->all();

                return ['history' => array_map(static fn (stdClass $h): array => self::historyView(self::historyDoc($h)), array_slice($rows, 0, 20)), 'next' => count($rows) > 20 ? (int) $rows[19]->version : null];
            }
            if (array_key_exists('recordId', $input)) {
                return ['payment' => self::ledger($input['recordId'], BrandStore::paymentState($input['recordId'], false))];
            }
            $q = DB::table('brand_payment_cases')->orderBy('id')->limit(21);
            if (isset($input['after'])) {
                $q->where('id', '>', $input['after']);
            }
            $rows = $q->get()->all();
            $items = [];
            foreach (array_slice($rows, 0, 20) as $row) {
                $c = self::doc($row);
                if (! F7::isUuid($c['recordId'] ?? null)) {
                    $items[] = self::view($row->id, $c);

                    continue;
                }
                $items[] = self::view($row->id, $c, BrandStore::order($c['recordId'], false), BrandStore::receipt($row->id, false), BrandStore::paymentState($c['recordId'], false));
            }

            return ['items' => $items, 'next' => count($rows) > 20 ? $rows[19]->id : null];
        });
    }

    /** decideBrandReview: ayni karar tekrari yalniz guncel kalmissa ayni sonucu doner. */
    public static function decide(string $actorId, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $a = PaymentCases::action()->parse($raw);
        if ($actorId === '') {
            throw new RuntimeException('actor');
        }

        return FinanceBarrier::run($actorId, ['kind' => 'brand_case', 'id' => $a['id']], static function () use ($actorId, $a, $raw, $now): array {
            $row = F7::lock('brand_payment_cases', 'id', $a['id']);
            if ($row === null) {
                throw new RuntimeException('missing');
            }
            $current = self::doc($row);
            if (($current['version'] ?? null) === $a['version'] + 1 && $current['status'] === $a['status'] && ($current['note'] ?? null) === $a['note']) {
                $h = self::historyDoc(DB::table('brand_payment_case_history')->where('case_id', $a['id'])->where('version', $a['version'] + 1)->lockForUpdate()->first());
                if (($h['actorId'] ?? null) === $actorId) {
                    return self::historyView($h);
                }
            }
            $patch = self::change($current, $raw, $now);
            $event = $patch + ['previousStatus' => $current['status'], 'actorId' => $actorId];
            DB::table('brand_payment_cases')->where('id', $a['id'])->update(['status' => $patch['status'], 'version' => $patch['version'], 'note' => $patch['note'], 'updated_at_ms' => $patch['updatedAtMs']]);
            DB::table('brand_payment_case_history')->insert(['case_id' => $a['id'], 'version' => $patch['version'], 'status' => $patch['status'], 'previous_status' => $current['status'], 'note' => $patch['note'], 'actor_id' => $actorId, 'updated_at_ms' => $patch['updatedAtMs']]);

            return self::historyView($event);
        });
    }

    public static function parseQuery(stdClass $p): array
    {
        try {
            return self::query()->parse($p);
        } catch (ZodError $e) {
            throw $e;
        }
    }
}
