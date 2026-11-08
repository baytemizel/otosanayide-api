<?php

use App\Domain\Garage\GarageRecycle;
use App\Domain\Garage\GarageSchemas;
use App\Domain\User\Privacy;
use App\Domain\User\RequestRules;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\ZodError;
use Illuminate\Support\Str;

function fails(callable $f, string $class = Throwable::class): bool
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
}

// packages/core/request-operations.test.ts
$now = Js::ms('2026-10-19T09:00:00Z');
$base = ['kind' => 'quotes', 'status' => 'requested', 'version' => 1];

it('quote offer, acceptance and completion require correct roles and versions', function () use ($now, $base) {
    $offer = ['id' => 'r', 'version' => 1, 'action' => 'offer', 'text' => 'All parts and labour included', 'price' => 500];
    $offered = array_merge($base, RequestRules::transition($base, $offer, 'business', $now));
    expect($offered['status'])->toBe('offered');
    expect(fails(fn () => RequestRules::transition($base, $offer, 'customer', $now), HttpError::class))->toBeTrue();
    expect(fails(fn () => RequestRules::transition($offered, ['id' => 'r', 'version' => 1, 'action' => 'accept'], 'customer', $now), HttpError::class))->toBeTrue();
    $confirmed = array_merge($offered, RequestRules::transition($offered, ['id' => 'r', 'version' => 2, 'action' => 'accept'], 'customer', $now));
    expect($confirmed['status'])->toBe('confirmed');
    expect(fails(fn () => RequestRules::transition($confirmed, ['id' => 'r', 'version' => 3, 'action' => 'complete'], 'customer', $now)))->toBeTrue();
    expect(RequestRules::transition($confirmed, ['id' => 'r', 'version' => 3, 'action' => 'complete'], 'business', $now)['status'])->toBe('completed');
});

it('appointment offers use future whole hours and a bounded horizon', function () use ($now, $base) {
    $r = array_merge($base, ['kind' => 'appointments']);
    $offer = ['id' => 'r', 'version' => 1, 'action' => 'offer', 'text' => 'Arrival and inspection appointment', 'startsAt' => '2026-10-20T12:00:00+03:00'];
    expect(RequestRules::transition($r, $offer, 'business', $now)['offer']['startsAt'])->toBe('2026-10-20T09:00:00.000Z');
    foreach (['2026-10-18T12:00:00+03:00', '2026-10-20T12:30:00+03:00', '2027-10-20T12:00:00+03:00'] as $s) {
        expect(fails(fn () => RequestRules::transition($r, array_merge($offer, ['startsAt' => $s]), 'business', $now)))->toBeTrue();
    }
    expect(fails(fn () => RequestRules::transition($r, $offer + ['price' => 1], 'business', $now)))->toBeTrue();
});

it('refuses expired proposals and early completion', function () use ($now, $base) {
    expect(fails(fn () => RequestRules::transition(array_merge($base, ['status' => 'offered', 'offer' => ['expiresAtMs' => $now]]), ['id' => 'r', 'version' => 1, 'action' => 'accept'], 'customer', $now)))->toBeTrue();
    expect(fails(fn () => RequestRules::transition(array_merge($base, ['kind' => 'appointments', 'status' => 'confirmed', 'offer' => ['startsAt' => '2026-10-20T12:00:00Z']]), ['id' => 'r', 'version' => 1, 'action' => 'complete'], 'business', $now)))->toBeTrue();
});

it('requires explanation to cancel and locks terminal records', function () use ($now, $base) {
    expect(fails(fn () => RequestRules::transition($base, ['id' => 'r', 'version' => 1, 'action' => 'cancel'], 'customer', $now)))->toBeTrue();
    $text = 'Plans changed today';
    expect(RequestRules::transition($base, ['id' => 'r', 'version' => 1, 'action' => 'cancel', 'text' => $text], 'customer', $now)['status'])->toBe('cancelled');
    foreach (['cancelled', 'completed', 'declined'] as $status) {
        expect(fails(fn () => RequestRules::transition(array_merge($base, ['status' => $status]), ['id' => 'r', 'version' => 1, 'action' => 'cancel', 'text' => $text], 'business', $now)))->toBeTrue();
    }
    expect(fails(fn () => RequestRules::transition($base, ['id' => 'r', 'version' => 1, 'action' => 'decline', 'text' => $text], 'customer', $now)))->toBeTrue();
});

it('keeps request inputs strict and owner writes cannot accept', function () {
    expect(fails(fn () => RequestRules::action()->parse(json_decode('{"id":"r","version":1,"action":"accept","userId":"other"}')), ZodError::class))->toBeTrue();
    expect(fails(fn () => RequestRules::create()->parse(json_decode('{"businessId":"../bad","kind":"quotes","service":"service","vehicle":"vehicle","message":"long enough message","requestKey":"bad"}')), ZodError::class))->toBeTrue();
    try {
        RequestRules::ownerWrite()->parse(json_decode('{"businessId":"b","value":{"id":"r","version":1,"action":"accept"}}'));
        $this->fail();
    } catch (ZodError $e) {
        expect($e->issue)->toBe('custom')->and($e->getMessage())->toBe('owner_cannot_accept');
    }
});

it('schedules notification retries with capped backoff', function () {
    $j = ['state' => 'pending', 'attempts' => 0, 'nextAttemptAtMs' => 100];
    expect(RequestRules::claim($j, 99, 'l'))->toBeNull()
        ->and(RequestRules::claim($j, 100, 'l'))->toMatchArray(['state' => 'processing', 'attempts' => 1, 'leaseUntilMs' => 60100])
        ->and(RequestRules::claim(array_merge($j, ['attempts' => 5]), 100, 'l'))->toBe(['state' => 'failed'])
        ->and(RequestRules::retry(['attempts' => 1], 0))->toBe(['state' => 'retry', 'nextAttemptAtMs' => 60000])
        ->and(RequestRules::retry(['attempts' => 4], 0))->toBe(['state' => 'retry', 'nextAttemptAtMs' => 480000])
        ->and(RequestRules::retry(['attempts' => 5], 0))->toBe(['state' => 'failed']);
});

// packages/core/privacy-requests.test.ts
function submitInput(): array
{
    return ['action' => 'submit', 'scope' => 'account', 'operationId' => (string) Str::uuid(), 'acknowledged' => true, 'confirmedScope' => true];
}

it('privacy requests require exact scope and acknowledgements', function () {
    foreach ([['acknowledged' => false], ['confirmedScope' => false], ['ownerUid' => 'other'], ['scope' => 'everything'], ['action' => 'delete'], ['status' => 'completed']] as $bad) {
        expect(fails(fn () => Privacy::ownerAction()->parse((object) array_merge(submitInput(), $bad)), ZodError::class))->toBeTrue();
    }
});

it('privacy state machine is versioned and idempotent per operation', function () {
    $now = 1792486800000;
    $input = submitInput();
    $r = Privacy::planSubmit(null, 'owner', $input, $now)['request'];
    expect($r['status'])->toBe('submitted');
    expect(Privacy::planSubmit($r, 'owner', $input, $now)['changed'])->toBeFalse();
    expect(fails(fn () => Privacy::planSubmit($r, 'owner', submitInput(), $now)))->toBeTrue();
    $start = ['action' => 'start_review', 'requestId' => $r['id'], 'expectedVersion' => 1, 'operationId' => (string) Str::uuid(), 'ownerUid' => 'owner'];
    $reviewing = Privacy::planAction($r, $start, 'admin', true, $now)['request'];
    expect($reviewing['status'])->toBe('reviewing')->and($reviewing['version'])->toBe(2);
    expect(Privacy::planAction($reviewing, $start, 'admin', true, $now)['changed'])->toBeFalse();
    $stale = ['action' => 'cancel', 'requestId' => $r['id'], 'expectedVersion' => 1, 'operationId' => (string) Str::uuid()];
    expect(fails(fn () => Privacy::planAction($reviewing, $stale, 'owner', false, $now)))->toBeTrue();
    expect(fails(fn () => Privacy::planAction($r, ['action' => 'cancel', 'requestId' => $r['id'], 'expectedVersion' => 1, 'operationId' => (string) Str::uuid()], 'intruder', false, $now)))->toBeTrue();
    expect(Privacy::view($r))->not->toHaveKey('ownerUid')->not->toHaveKey('lastAction');
});

it('recent login window matches', function () {
    $now = 1000000000;
    expect(Privacy::recentLogin((int) (($now - 600000) / 1000), $now))->toBeTrue()
        ->and(Privacy::recentLogin((int) (($now - 601000) / 1000), $now))->toBeFalse()
        ->and(Privacy::recentLogin((int) (($now + 31000) / 1000), $now))->toBeFalse();
});

function garageFixture(): array
{
    return ['version' => 1, 'vehicles' => [['brand' => 'BMW', 'model' => 'X', 'year' => 2020, 'fuel' => 'Dizel', 'mileage' => 5, 'id' => 'v1', 'archived' => false]], 'records' => [], 'selectedVehicleId' => null];
}

function source(): array
{
    return ['revision' => 1, 'updatedAt' => '2026-10-01T00:00:00.000Z', 'operationId' => (string) Str::uuid(), 'garage' => GarageSchemas::transfer(garageFixture())];
}

it('garage transfer rejects device selection, media and bad references', function () {
    expect(GarageSchemas::transfer(garageFixture())['vehicles'][0]['id'])->toBe('v1');
    expect(fails(fn () => GarageSchemas::transfer(array_merge(garageFixture(), ['selectedVehicleId' => 'v1']))))->toBeTrue();
    $g = garageFixture();
    $g['vehicles'][0]['photoId'] = (string) Str::uuid();
    expect(fails(fn () => GarageSchemas::transfer($g)))->toBeTrue();
    $h = garageFixture();
    $h['records'][] = ['vehicleId' => 'missing', 'title' => 'Bakım', 'date' => '2026-01-01', 'mileage' => 1, 'amount' => 1, 'note' => '', 'id' => 'r1'];
    expect(fails(fn () => GarageSchemas::transfer($h)))->toBeTrue();
});

it('garage backup plan is idempotent per operation and rejects stale revisions', function () {
    $before = source();
    $op = (string) Str::uuid();
    $write = ['expectedRevision' => 1, 'operationId' => $op, 'garage' => $before['garage']];
    $plan = GarageSchemas::planBackup($before, $write, '2026-10-02T00:00:00.000Z');
    expect($plan['changed'])->toBeTrue()->and($plan['backup']['revision'])->toBe(2);
    expect(GarageSchemas::planBackup($plan['backup'], $write, '2026-10-02T00:00:00.000Z')['changed'])->toBeFalse();
    expect(fails(fn () => GarageSchemas::planBackup($before, array_merge($write, ['expectedRevision' => 5]), '2026-10-02T00:00:00.000Z')))->toBeTrue();
    expect(fails(fn () => GarageSchemas::planBackup($before, $write, '2025-01-01T00:00:00.000Z')))->toBeTrue();
});

it('combine refuses conflicting text unless replacing', function () {
    $a = garageFixture();
    $b = garageFixture();
    $b['vehicles'][0]['mileage'] = 9;
    expect(fails(fn () => GarageSchemas::combine($a, $b, false)))->toBeTrue();
    expect(GarageSchemas::combine($a, $b, true)['vehicles'][0]['mileage'])->toBe(9);
});

it('rounds maintenance amounts to cents and raises vehicle mileage', function () {
    $state = GarageSchemas::transfer(garageFixture());
    $next = GarageSchemas::saveRecord($state, ['vehicleId' => 'v1', 'title' => 'Bakım', 'date' => '2026-01-01', 'mileage' => 50, 'amount' => 10.456, 'note' => ''], 'r1');
    expect($next['records'][0]['amount'])->toBe(10.46)->and($next['vehicles'][0]['mileage'])->toBe(50);
});

// packages/core/garage-recycle.test.ts
it('trash keeps one reversible copy and restore returns text exactly', function () {
    $now = '2026-10-10T00:00:00.000Z';
    $original = source();
    $trashOp = ['action' => 'trash', 'expectedRevision' => 1, 'operationId' => (string) Str::uuid(), 'acknowledged' => true];
    $plan = GarageRecycle::plan($original, null, $trashOp, false, $now);
    expect($plan['backup']['revision'])->toBe(2)->and($plan['backup']['garage'])->toBe(GarageSchemas::emptyGarage())->and($plan['recycle']['purgeAfter'])->toBe('2026-11-09T00:00:00.000Z');
    GarageRecycle::assertValid($plan['recycle']);
    expect(GarageRecycle::plan($plan['backup'], $plan['recycle'], $trashOp, false, $now)['changed'])->toBeFalse();
    expect(GarageRecycle::status($plan['backup'], $plan['recycle'], false, $now))->toBe(['state' => 'trashed', 'revision' => 2, 'vehicles' => 1, 'records' => 0, 'purgeAfter' => '2026-11-09T00:00:00.000Z']);
    $restoreOp = ['action' => 'restore', 'expectedRevision' => 2, 'operationId' => (string) Str::uuid(), 'acknowledged' => true];
    $restored = GarageRecycle::plan($plan['backup'], $plan['recycle'], $restoreOp, false, $now);
    expect($restored['backup']['revision'])->toBe(3)->and($restored['backup']['garage'])->toBe($original['garage'])->and($restored['recycle'])->not->toHaveKey('backup');
    expect(fails(fn () => GarageRecycle::plan($plan['backup'], $plan['recycle'], array_merge($restoreOp, ['expectedRevision' => 1]), false, $now)))->toBeTrue();
});

it('recycle rejects shared data, missing backups and strict input violations', function () {
    $now = '2026-10-10T00:00:00.000Z';
    $r = ['action' => 'trash', 'expectedRevision' => 1, 'operationId' => (string) Str::uuid(), 'acknowledged' => true];
    expect(fails(fn () => GarageRecycle::plan(source(), null, $r, true, $now), RuntimeException::class))->toBeTrue();
    expect(fails(fn () => GarageRecycle::plan(null, null, $r, false, $now), RuntimeException::class))->toBeTrue();
    foreach ([['acknowledged' => false], ['uid' => 'other'], ['action' => 'purge'], ['expectedRevision' => 0]] as $bad) {
        expect(fails(fn () => GarageRecycle::action()->parse((object) array_merge($r, $bad)), ZodError::class))->toBeTrue();
    }
});

it('vehicle and reminder schemas match source limits', function () {
    expect(GarageSchemas::vehicle()->parse(json_decode('{"brand":" BMW ","model":"X","year":"2020","fuel":"Dizel","mileage":"5"}')))->toMatchArray(['brand' => 'BMW', 'year' => 2020, 'mileage' => 5]);
    expect(fails(fn () => GarageSchemas::vehicle()->parse(json_decode('{"brand":"B","model":"X","year":1900,"fuel":"Dizel","mileage":0}')), ZodError::class))->toBeTrue();
    try {
        GarageSchemas::reminder()->parse(json_decode('{"vehicleId":"v","title":"Yag"}'));
        $this->fail();
    } catch (ZodError $e) {
        expect($e->getMessage())->toBe('Tarih veya kilometre belirtin.');
    }
});
