<?php

namespace App\Domain\Brand;

use App\Domain\Content\Ids;
use App\Domain\Payments\F7;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Satir <-> kaynak belge bicimi (brandCampaigns, brandTariffs, brandCapacities, brandReservations, brandOrders). */
final class BrandStore
{
    private static function first(string $table, string $column, string $id, bool $lock): ?stdClass
    {
        $q = DB::table($table)->where($column, $id);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    /** @return array<string,mixed>|null */
    public static function campaign(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_campaigns', 'id', $id, $lock);

        return $r === null ? null : self::campaignDoc($r);
    }

    /** @return array<string,mixed> */
    public static function campaignDoc(stdClass $r): array
    {
        $doc = ['creative' => ['brand' => $r->brand, 'headline' => $r->headline, 'description' => $r->description, 'url' => $r->url, 'placement' => $r->placement, 'city' => $r->city, 'category' => $r->category, 'startDate' => $r->start_date, 'endDate' => $r->end_date],
            'status' => $r->status, 'version' => (int) $r->version, 'note' => $r->note, 'lastEditorId' => $r->last_editor_id, 'mediaAttempts' => (int) $r->media_attempts,
            'createdAtMs' => (int) $r->created_at_ms, 'updatedAtMs' => (int) $r->updated_at_ms];
        if ($r->asset_id !== null) {
            $doc['asset'] = ['id' => $r->asset_id, 'sha256' => $r->asset_sha256, 'width' => (int) $r->asset_width, 'height' => (int) $r->asset_height, 'bytes' => (int) $r->asset_bytes];
        }

        return $doc;
    }

    public static function putCampaign(string $id, array $d, bool $exists): void
    {
        $c = $d['creative'];
        $a = $d['asset'] ?? null;
        $row = ['brand' => $c['brand'], 'headline' => $c['headline'], 'description' => $c['description'], 'url' => $c['url'], 'placement' => $c['placement'], 'city' => $c['city'], 'category' => $c['category'], 'start_date' => $c['startDate'], 'end_date' => $c['endDate'],
            'status' => $d['status'], 'version' => $d['version'], 'note' => $d['note'], 'last_editor_id' => $d['lastEditorId'], 'media_attempts' => $d['mediaAttempts'] ?? 0,
            'asset_id' => $a['id'] ?? null, 'asset_sha256' => $a['sha256'] ?? null, 'asset_width' => $a['width'] ?? null, 'asset_height' => $a['height'] ?? null, 'asset_bytes' => $a['bytes'] ?? null,
            'created_at_ms' => $d['createdAtMs'], 'updated_at_ms' => $d['updatedAtMs']];
        if ($exists) {
            DB::table('brand_campaigns')->where('id', $id)->update($row);
        } else {
            DB::table('brand_campaigns')->insert(['id' => $id] + $row);
        }
    }

    public static function history(string $campaignId, string $action, string $actor, array $snapshot): void
    {
        DB::table('brand_campaign_history')->insert(['campaign_id' => $campaignId, 'action' => $action, 'actor_id' => $actor, 'snapshot' => F7::json($snapshot), 'created_at' => Ids::now()]);
    }

    /** @return array<string,mixed>|null brandTariffs/{id} */
    public static function tariff(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_tariffs', 'id', $id, $lock);

        return $r === null ? null : ['market' => ['placement' => $r->placement, 'city' => $r->city, 'category' => $r->category], 'enabled' => (bool) $r->enabled, 'dailyMinor' => (int) $r->daily_minor, 'taxBasisPoints' => (int) $r->tax_basis_points, 'version' => (int) $r->version];
    }

    /** @return array<string,mixed>|null brandCapacities/{placement} */
    public static function capacity(string $placement, bool $lock = true): ?array
    {
        $r = self::first('brand_capacities', 'placement', $placement, $lock);

        return $r === null ? null : ['enabled' => (bool) $r->enabled, 'capacity' => (int) $r->capacity, 'version' => (int) $r->version];
    }

    /** @return array{0:bool,1:?array{reservationId:string,expiresAtMs:int}} */
    public static function anchor(string $campaignId, bool $lock = true): array
    {
        $r = self::first('brand_reservation_anchors', 'campaign_id', $campaignId, $lock);

        return [$r !== null, $r === null ? null : ['reservationId' => $r->reservation_id, 'expiresAtMs' => (int) $r->expires_at_ms]];
    }

    public static function putAnchor(string $campaignId, string $reservationId, int $expiresAtMs): void
    {
        DB::table('brand_reservation_anchors')->updateOrInsert(['campaign_id' => $campaignId], ['reservation_id' => $reservationId, 'expires_at_ms' => $expiresAtMs]);
    }

    /** @return array<string,mixed>|null brandReservations/{id} */
    public static function reservation(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_reservations', 'id', $id, $lock);
        if ($r === null) {
            return null;
        }

        return array_filter([
            'kind' => 'estimate', 'campaignId' => $r->campaign_id, 'campaignVersion' => (int) $r->campaign_version, 'tariffVersion' => (int) $r->tariff_version,
            'capacityVersion' => (int) $r->capacity_version, 'placement' => $r->placement,
            'market' => ['placement' => $r->market_placement, 'city' => $r->market_city, 'category' => $r->market_category],
            'startDate' => $r->start_date, 'endDate' => $r->end_date, 'days' => (int) $r->days, 'dates' => F7::list($r->dates), 'currency' => $r->currency,
            'dailyMinor' => (int) $r->daily_minor, 'taxBasisPoints' => (int) $r->tax_basis_points, 'subtotalMinor' => (int) $r->subtotal_minor,
            'taxMinor' => (int) $r->tax_minor, 'totalMinor' => (int) $r->total_minor, 'status' => $r->status, 'actorId' => $r->actor_id,
            'createdAtMs' => (int) $r->created_at_ms, 'expiresAtMs' => (int) $r->expires_at_ms,
            'committedUntilMs' => $r->committed_until_ms === null ? null : (int) $r->committed_until_ms,
            'releasedAtMs' => $r->released_at_ms === null ? null : (int) $r->released_at_ms, 'releaseReason' => $r->release_reason,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string,mixed>|null brandOrders/{id} */
    public static function order(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_orders', 'reservation_id', $id, $lock);

        return $r === null ? null : ['reservationId' => $r->reservation_id, 'campaignId' => $r->campaign_id, 'campaignVersion' => (int) $r->campaign_version, 'tariffVersion' => (int) $r->tariff_version, 'capacityVersion' => (int) $r->capacity_version, 'currency' => $r->currency, 'subtotalMinor' => (int) $r->subtotal_minor, 'taxMinor' => (int) $r->tax_minor, 'totalMinor' => (int) $r->total_minor, 'createdAtMs' => (int) $r->created_at_ms, 'expiresAtMs' => (int) $r->expires_at_ms, 'status' => $r->status];
    }

    /** @return array<string,mixed>|null brandPaymentStates/{id} */
    public static function paymentState(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_payment_states', 'reservation_id', $id, $lock);

        return $r === null ? null : array_filter(['status' => $r->status, 'primaryReceiptId' => $r->primary_receipt_id, 'checkedAtMs' => (int) $r->checked_at_ms], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string,mixed>|null brandPaymentBindings/{id} */
    public static function binding(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_payment_bindings', 'reservation_id', $id, $lock);

        return $r === null ? null : ['provider' => $r->provider, 'merchantId' => $r->merchant_id, 'createdAtMs' => (int) $r->created_at_ms];
    }

    /** @return array<string,mixed>|null brandPaymentReceipts/{id} */
    public static function receipt(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_payment_receipts', 'id', $id, $lock);

        return $r === null ? null : ['recordId' => $r->record_id, 'provider' => $r->provider, 'merchantId' => $r->merchant_id, 'paymentId' => $r->payment_id, 'currency' => $r->currency, 'amountMinor' => (int) $r->amount_minor, 'capturedAtMs' => $r->captured_at_ms === null ? null : (int) $r->captured_at_ms, 'outcome' => $r->outcome, 'reason' => $r->reason, 'checkedAtMs' => (int) $r->checked_at_ms];
    }

    /** @return array<string,mixed>|null brandAcceptances/{id} */
    public static function acceptance(string $id, bool $lock = true): ?array
    {
        $r = self::first('brand_acceptances', 'reservation_id', $id, $lock);

        return $r === null ? null : ['reservationId' => $r->reservation_id, 'campaignId' => $r->campaign_id, 'campaignVersion' => (int) $r->campaign_version, 'receiptId' => $r->receipt_id, 'placement' => $r->placement, 'dates' => F7::list($r->dates), 'currency' => $r->currency, 'totalMinor' => (int) $r->total_minor, 'status' => $r->status, 'acceptedAtMs' => (int) $r->accepted_at_ms, 'committedUntilMs' => (int) $r->committed_until_ms];
    }

    /** @return array{0:bool,1:mixed} */
    public static function day(string $placement, string $date): array
    {
        $r = DB::table('brand_inventory_days')->where('placement', $placement)->where('date', $date)->lockForUpdate()->first();

        return [$r !== null, $r === null ? null : F7::list($r->holds)];
    }

    public static function putDay(string $placement, string $date, array $holds): void
    {
        DB::table('brand_inventory_days')->updateOrInsert(['placement' => $placement, 'date' => $date], ['holds' => F7::json(array_values($holds))]);
    }
}
