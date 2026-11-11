<?php

namespace App\Domain\Payments;

use App\Domain\Account\AccountFence;
use App\Domain\Content\Digest;
use App\Exceptions\WriteConflict;
use App\Support\Tx;
use App\Support\Z;
use Closure;
use stdClass;

/**
 * finance-write-barrier.ts: kaynak UID'leri yazimla AYNI transaction'da, yazimlardan ONCE okunur ve
 * hesap silme cercevesine karsi denetlenir. Rol vermez; rol/surum/tutar kontrolleri cagirandadir.
 *
 * Kapsamlar: actor, ad_campaign, brand_campaign, ad_reservation, ad_order, brand_reservation,
 * brand_order, brand_cancellation, ad_case, brand_case.
 */
final class FinanceBarrier
{
    private static function conflict(): WriteConflict
    {
        return new WriteConflict('finance_write_conflict');
    }

    private static function get(string $table, string $column, string $id, bool $required = true): ?stdClass
    {
        Z::string()->regex('/^[A-Za-z0-9_-]{1,120}$/')->parse($id);
        $row = F7::lock($table, $column, $id);
        if ($row === null && $required) {
            throw self::conflict();
        }

        return $row;
    }

    /**
     * @param  array{kind:string,id?:string,businessId?:string,allowCreate?:bool}  $scope
     * @return list<string> sirali konu UID'leri
     */
    public static function assert(?string $actor, array $scope): array
    {
        $subjects = [];
        $owner = static function (mixed $v) use (&$subjects): void {
            if (! is_string($v) || strlen($v) < 1 || strlen($v) > 128 || preg_match('/^[A-Za-z0-9_-]+$/D', $v) !== 1) {
                throw self::conflict();
            }
            $subjects[$v] = true;
        };
        if ($actor !== null) {
            $owner($actor);
        }
        $adCampaign = static function (string $id, ?string $businessId, ?string $legacy, bool $allowCreate) use ($owner): void {
            F7::uuid()->parse($id);
            $c = self::get('ad_campaigns', 'id', $id, ! $allowCreate);
            if ($c !== null && $businessId !== null && $c->business_id !== $businessId) {
                throw self::conflict();
            }
            $bid = Z::string()->regex('/^[A-Za-z0-9_-]{1,120}$/')->parse($c->business_id ?? $businessId);
            $b = self::get('businesses', 'id', $bid);
            $known = false;
            foreach ([$c?->owner_uid, $b->owner_uid, $legacy] as $v) {
                if ($v !== null) {
                    $owner($v);
                    $known = true;
                }
            }
            if (! $known) {
                throw self::conflict();
            }
        };
        $brandCampaign = static function (string $id, bool $allowCreate) use ($owner, $actor): void {
            F7::uuid()->parse($id);
            $c = self::get('brand_campaigns', 'id', $id, ! $allowCreate);
            if ($c !== null) {
                $owner($c->last_editor_id);
            } elseif ($actor === null) {
                throw self::conflict();
            }
        };
        $adReservation = static function (string $id) use ($owner, $adCampaign): stdClass {
            F7::uuid()->parse($id);
            $r = self::get('ad_reservations', 'id', $id);
            $owner($r->user_id);
            $adCampaign(F7::uuid()->parse($r->campaign_id), $r->business_id, $r->user_id, false);

            return $r;
        };
        $adOrder = static function (string $id) use ($owner, $adReservation): stdClass {
            F7::uuid()->parse($id);
            $o = self::get('ad_orders', 'id', $id);
            $owner($o->user_id);
            $r = $adReservation(F7::uuid()->parse($o->reservation_id));
            if ($o->reservation_id !== $id || $o->user_id !== $r->user_id || $o->campaign_id !== $r->campaign_id || $o->business_id !== $r->business_id) {
                throw self::conflict();
            }

            return $o;
        };
        $brandReservation = static function (string $id) use ($owner, $brandCampaign): stdClass {
            F7::uuid()->parse($id);
            $r = self::get('brand_reservations', 'id', $id);
            $owner($r->actor_id);
            $brandCampaign(F7::uuid()->parse($r->campaign_id), false);

            return $r;
        };
        $brandOrder = static function (string $id) use ($brandReservation): stdClass {
            F7::uuid()->parse($id);
            $o = self::get('brand_orders', 'reservation_id', $id);
            $r = $brandReservation($id);
            if ($o->reservation_id !== $id || $o->campaign_id !== $r->campaign_id) {
                throw self::conflict();
            }

            return $o;
        };
        $id = (string) ($scope['id'] ?? '');
        switch ($scope['kind']) {
            case 'actor':
                if ($actor === null) {
                    throw self::conflict();
                }
                break;
            case 'ad_campaign':
                $adCampaign($id, $scope['businessId'] ?? null, null, (bool) ($scope['allowCreate'] ?? false));
                break;
            case 'brand_campaign':
                $brandCampaign($id, (bool) ($scope['allowCreate'] ?? false));
                break;
            case 'ad_reservation':
                $adReservation($id);
                break;
            case 'ad_order':
                $adOrder($id);
                break;
            case 'brand_reservation':
                $brandReservation($id);
                break;
            case 'brand_order':
                $brandOrder($id);
                break;
            case 'ad_case':
                F7::hash()->parse($id);
                $c = self::get('ad_payment_cases', 'id', $id);
                $adOrder(F7::uuid()->parse($c->order_id));
                break;
            case 'brand_case':
                F7::hash()->parse($id);
                $c = self::get('brand_payment_cases', 'id', $id);
                $brandOrder(F7::uuid()->parse($c->record_id));
                break;
            case 'brand_cancellation':
                F7::uuid()->parse($id);
                $c = self::get('brand_cancellations', 'reservation_id', $id);
                $r = $brandReservation($id);
                if ($c->reservation_id !== $id || $c->campaign_id !== $r->campaign_id) {
                    throw self::conflict();
                }
                $owner($c->requester_id);
                if ($c->reviewer_id !== '') {
                    $owner($c->reviewer_id);
                }
                $brandOrder($id);
                break;
            default:
                throw self::conflict();
        }
        $uids = array_keys($subjects);
        sort($uids, SORT_STRING);
        foreach ($uids as $uid) {
            AccountFence::assertWritable($uid);
        }

        return $uids;
    }

    /**
     * financeWriteTransaction
     *
     * @template T
     *
     * @param  array{kind:string,id?:string,businessId?:string,allowCreate?:bool}  $scope
     * @param  Closure():T  $run
     * @return T
     */
    public static function run(?string $actor, array $scope, Closure $run)
    {
        return Tx::run(static function () use ($actor, $scope, $run) {
            self::assert($actor, $scope);

            return $run();
        });
    }

    /**
     * financeLookupBinding: yalniz degismez fatura kimligi karsilastirilir.
     *
     * @param  'ad_order'|'brand_order'|'brand_cancellation'  $kind
     */
    public static function lookupBinding(string $kind, string $id): string
    {
        $subjects = self::assert(null, ['kind' => $kind, 'id' => $id]);
        $brand = $kind !== 'ad_order';
        $o = (array) ($brand ? F7::lock('brand_orders', 'reservation_id', $id) : F7::lock('ad_orders', 'id', $id));
        $r = (array) ($brand ? F7::lock('brand_reservations', 'id', $id) : F7::lock('ad_reservations', 'id', $id));
        $keys = ['user_id', 'reservation_id', 'campaign_id', 'business_id', 'campaign_version', 'provider', 'merchant_id', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'created_at_ms', 'expires_at_ms', 'tariff_version', 'capacity_version'];
        $pick = static fn (array $v, array $names): array => array_intersect_key($v, array_flip(array_filter($names, static fn (string $k): bool => array_key_exists($k, $v) && $v[$k] !== null)));
        $cancellation = $kind === 'brand_cancellation' ? (array) F7::lock('brand_cancellations', 'reservation_id', $id) : null;
        $binding = $brand ? F7::lock('brand_payment_bindings', 'reservation_id', $id) : null;

        return Digest::of(['subjects' => $subjects, 'order' => $pick($o, $keys), 'reservation' => $pick($r, ['user_id', 'actor_id', 'campaign_id', 'business_id', 'campaign_version']), 'binding' => $binding === null ? null : (array) $binding, 'cancellation' => $cancellation]);
    }
}
