<?php

namespace App\Domain\Ads;

use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\Rows;
use App\Domain\Payments\F7;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Satir <-> kaynak belge bicimi (campaigns, adTariffs, adInventory, adReservations, adOrders). */
final class AdStore
{
    /** @return array<string,mixed>|null campaigns/{id} */
    public static function campaign(string $id, bool $lock = false): ?array
    {
        $q = DB::table('ad_campaigns')->where('id', $id);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();

        return $r === null ? null : self::campaignDoc($r);
    }

    /** @return array<string,mixed> */
    public static function campaignDoc(stdClass $r): array
    {
        return array_filter([
            'placement' => $r->placement, 'city' => $r->city, 'category' => $r->category, 'days' => (int) $r->days, 'startDate' => $r->start_date,
            'headline' => $r->headline, 'description' => $r->description, 'businessId' => $r->business_id, 'businessName' => $r->business_name,
            'approvedProfileVersion' => $r->approved_profile_version === null ? null : (int) $r->approved_profile_version,
            'status' => $r->status, 'version' => (int) $r->version, 'reason' => $r->reason, 'createdAtMs' => (int) $r->created_at_ms, 'updatedAtMs' => (int) $r->updated_at_ms,
            'ownerUid' => $r->owner_uid,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** tx.set(ref,{...next,ownerUid}) */
    public static function putCampaign(string $id, array $v, string $ownerUid, bool $exists): void
    {
        $row = [
            'business_id' => $v['businessId'], 'owner_uid' => $ownerUid, 'placement' => $v['placement'], 'city' => $v['city'], 'category' => $v['category'],
            'days' => $v['days'], 'start_date' => $v['startDate'], 'headline' => $v['headline'], 'description' => $v['description'],
            'business_name' => $v['businessName'] ?? '', 'approved_profile_version' => $v['approvedProfileVersion'] ?? null, 'status' => $v['status'],
            'version' => $v['version'], 'reason' => $v['reason'], 'created_at_ms' => $v['createdAtMs'], 'updated_at_ms' => $v['updatedAtMs'],
        ];
        if ($exists) {
            DB::table('ad_campaigns')->where('id', $id)->update($row);
        } else {
            DB::table('ad_campaigns')->insert(['id' => $id] + $row);
        }
    }

    /** @return array{b:array<string,mixed>,m:?array<string,mixed>,exists:bool} isletme + uyelik (kilitli) */
    public static function business(string $uid, string $bid, bool $lock = true): array
    {
        $row = Rows::business($bid, $lock);

        return ['b' => Docs::business($row) ?? [], 'm' => Docs::membership(Rows::member($uid, $bid, $lock)), 'exists' => $row !== null];
    }

    /** @return array<string,mixed>|null adTariffs/{marketId} */
    public static function tariff(string $marketId, bool $lock = false): ?array
    {
        $q = DB::table('ad_tariffs')->where('id', $marketId);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($r === null) {
            return null;
        }

        return ['placement' => $r->placement, 'city' => $r->city, 'category' => $r->category, 'enabled' => (bool) $r->enabled, 'capacity' => (int) $r->capacity, 'sevenDaysMinor' => (int) $r->seven_days_minor, 'thirtyDaysMinor' => (int) $r->thirty_days_minor, 'taxBasisPoints' => (int) $r->tax_basis_points, 'version' => (int) $r->version];
    }

    /** @return array{0:bool,1:mixed} [var mi, holds] adInventory/{market}_{date} */
    public static function day(string $table, string $key, string $value, string $date): array
    {
        $r = DB::table($table)->where($key, $value)->where('date', $date)->lockForUpdate()->first();

        return [$r !== null, $r === null ? null : F7::list($r->holds)];
    }

    /** @param list<array{reservationId:string,expiresAtMs:int}> $holds */
    public static function putDay(string $table, string $key, string $value, string $date, array $holds): void
    {
        DB::table($table)->updateOrInsert([$key => $value, 'date' => $date], ['holds' => F7::json($holds)]);
    }

    /** @return array<string,mixed>|null adReservations/{id} */
    public static function reservation(string $id, bool $lock = true): ?array
    {
        $q = DB::table('ad_reservations')->where('id', $id);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($r === null) {
            return null;
        }

        return array_filter([
            'userId' => $r->user_id, 'campaignId' => $r->campaign_id, 'businessId' => $r->business_id, 'marketId' => $r->market_id,
            'campaignVersion' => (int) $r->campaign_version, 'tariffVersion' => (int) $r->tariff_version, 'currency' => $r->currency,
            'subtotalMinor' => (int) $r->subtotal_minor, 'taxMinor' => (int) $r->tax_minor, 'totalMinor' => (int) $r->total_minor,
            'dates' => F7::list($r->dates), 'status' => $r->status, 'expiresAtMs' => (int) $r->expires_at_ms, 'createdAtMs' => (int) $r->created_at_ms,
            'releasedAtMs' => $r->released_at_ms === null ? null : (int) $r->released_at_ms, 'orderId' => $r->order_id,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string,mixed>|null adOrders/{id} */
    public static function order(string $id, bool $lock = true): ?array
    {
        $q = DB::table('ad_orders')->where('id', $id);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($r === null) {
            return null;
        }

        return array_filter([
            'userId' => $r->user_id, 'reservationId' => $r->reservation_id, 'campaignId' => $r->campaign_id, 'businessId' => $r->business_id,
            'campaignVersion' => (int) $r->campaign_version, 'provider' => $r->provider, 'merchantId' => $r->merchant_id, 'currency' => $r->currency,
            'subtotalMinor' => (int) $r->subtotal_minor, 'taxMinor' => (int) $r->tax_minor, 'totalMinor' => (int) $r->total_minor,
            'status' => $r->status, 'reason' => $r->reason, 'receiptId' => $r->receipt_id, 'createdAtMs' => (int) $r->created_at_ms,
            'checkedAtMs' => $r->checked_at_ms === null ? null : (int) $r->checked_at_ms, 'paidAtMs' => $r->paid_at_ms === null ? null : (int) $r->paid_at_ms,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array{0:bool,1:?array<string,mixed>} campaignReservations/{campaignId} */
    public static function anchor(string $campaignId): array
    {
        $r = F7::lock('ad_campaign_reservations', 'campaign_id', $campaignId);

        return [$r !== null, $r === null ? null : ['reservationId' => $r->reservation_id, 'expiresAtMs' => (int) $r->expires_at_ms]];
    }

    public static function putAnchor(string $campaignId, string $reservationId, int $expiresAtMs): void
    {
        DB::table('ad_campaign_reservations')->updateOrInsert(['campaign_id' => $campaignId], ['reservation_id' => $reservationId, 'expires_at_ms' => $expiresAtMs]);
    }

    public static function history(string $campaignId, string $action, int $version, string $actor, string $reason, array $snapshot): void
    {
        DB::table('ad_campaign_history')->insert(['campaign_id' => $campaignId, 'action' => $action, 'version' => $version, 'actor_id' => $actor, 'reason' => $reason, 'snapshot' => F7::json($snapshot), 'created_at' => Ids::now()]);
    }
}
