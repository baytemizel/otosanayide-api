<?php

use App\Domain\Catalogue\BusinessReader;
use App\Domain\Garage\GarageRecycle;
use App\Domain\Garage\GarageSchemas;
use App\Domain\User\Privacy;
use App\Domain\User\Requests;
use Illuminate\Support\Str;

// Veritabani olmadan: yanit nesneleri uretim kodundan (DTO) gelir, sekli gercek TS ayristiricilari denetler.

function business(string $id = 'fx1'): array
{
    return BusinessReader::publicBusiness($id, ['name' => 'Sentetik Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sentetik Sanayi Sitesi No 1', 'phone' => '02161234567', 'contactEmail' => 'iletisim@example.com', 'website' => 'https://example.com', 'hours' => '09-18', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'], 'published' => true, 'sample' => false, 'ownership' => 'verified', 'ownerReady' => true, 'features' => ['quotes' => true, 'appointments' => false], 'coordinateStatus' => 'verified', 'latitude' => 41.02, 'longitude' => 29.1, 'cardPayment' => true, 'wc' => null, 'businessCoverPhotoId' => null, 'publicBusinessPhotoIds' => []]);
}

function requestRow(): stdClass
{
    return (object) ['id' => 'r1', 'business_id' => 'fx1', 'business_name' => 'Sentetik Motor', 'kind' => 'quotes', 'service' => 'Fren', 'vehicle' => 'BMW', 'message' => 'Fren balatası değişimi', 'preferred_date' => null, 'status' => 'offered', 'offer_text' => 'İşçilik dahil teklifimiz', 'offer_price' => 500.0, 'offer_starts_at' => null, 'offer_expires_at_ms' => 1792486800000, 'resolution' => null, 'version' => 2];
}

it('writes contract fixtures from production DTO builders', function () {
    contract('search', 'search', ['items' => [business() + ['distance' => 1.5]], 'districts' => ['Ümraniye'], 'sponsored' => [], 'mode' => 'live']);
    contract('favorites-page', 'favorites-page', ['entries' => [['id' => 'fx1', 'business' => business()], ['id' => 'gone', 'business' => null]], 'next' => null]);
    contract('favorite-status', 'favorite-status', ['saved' => true]);
    $garage = GarageSchemas::transfer(['version' => 1, 'vehicles' => [['brand' => 'BMW', 'model' => 'X', 'year' => 2020, 'fuel' => 'Dizel', 'mileage' => 5, 'id' => 'v1', 'archived' => false]], 'records' => [['vehicleId' => 'v1', 'title' => 'Bakım', 'date' => '2026-01-01', 'mileage' => 1, 'amount' => 10.5, 'note' => '', 'id' => 'm1']], 'selectedVehicleId' => null]);
    contract('account', 'account', ['vehicles' => $garage['vehicles'], 'maintenance' => $garage['records'], 'garageShared' => false, 'garageRevision' => null, 'reminders' => [['vehicleId' => 'v1', 'title' => 'Muayene', 'dueDate' => '2027-01-01', 'nextCheckAtMs' => 1, 'createdAt' => ['_seconds' => 1, '_nanoseconds' => 0], 'id' => 'rm1']], 'favorites' => ['fx1']]);
    $backup = ['revision' => 1, 'updatedAt' => '2026-10-01T00:00:00.000Z', 'operationId' => (string) Str::uuid(), 'garage' => $garage];
    contract('garage-backup', 'garage-backup', ['backup' => $backup]);
    contract('garage-backup-empty', 'garage-backup', ['backup' => null]);
    contract('garage-recycle', 'garage-recycle', ['status' => GarageRecycle::status($backup, null, false, '2026-10-02T00:00:00.000Z')]);
    $p = Privacy::planSubmit(null, 'owner', ['action' => 'submit', 'scope' => 'account', 'operationId' => (string) Str::uuid(), 'acknowledged' => true, 'confirmedScope' => true], 1792486800000)['request'];
    contract('privacy', 'privacy', ['request' => Privacy::view($p), 'execution' => 'not_enabled']);
    contract('privacy-empty', 'privacy', ['request' => null, 'execution' => 'not_enabled']);
    contract('privacy-queue', 'privacy-queue', ['items' => [Privacy::view($p) + ['ownerUid' => 'owner']], 'next' => null, 'execution' => 'not_enabled']);
    contract('push', 'push', ['devices' => [['generation' => (string) Str::uuid(), 'deviceId' => (string) Str::uuid(), 'platform' => 'web', 'locale' => 'tr', 'enabled' => true, 'version' => 1, 'updatedAtMs' => 1]]]);
    contract('requests', 'requests', ['items' => [Requests::view(requestRow())], 'next' => null]);
    contract('owner-requests', 'owner-requests', ['businessId' => 'fx1', 'accepting' => ['quotes' => true, 'appointments' => false], 'items' => [Requests::view(requestRow())], 'next' => null], ['businessId' => 'fx1']);
    expect(glob(storage_path('contract/*.json')))->not->toBeEmpty();
});
