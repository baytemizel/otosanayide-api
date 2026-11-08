<?php

use App\Domain\Auth\AuthService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Es zamanli yazim: tek surecte, ikinci baglanti rakip transaction'i oynar.
 * Gercek yuk testi degildir; kilit ve kisit davranisini gosterir.
 */
function raceAccount(string $email): array
{
    Queue::fake();
    $s = new AuthService('127.0.0.1');
    $s->register($email, 'correct-horse-1');
    DB::table('auth_accounts')->where('email', $email)->update(['email_verified_at' => now('UTC')]);

    return [DB::table('auth_accounts')->where('email', $email)->value('id'), ['HTTP_AUTHORIZATION' => 'Bearer '.$s->login($email, 'correct-horse-1')['session']->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => '']];
}

function rival()
{
    config(['database.connections.rival' => config('database.connections.mysql')]);

    return DB::connection('rival');
}

it('second writer with the same revision loses with 409', function () {
    config(['otosanayide.flags.mobile_garage_backup' => true]);
    [, $auth] = raceAccount('g@example.com');
    $garage = ['version' => 1, 'vehicles' => [], 'records' => [], 'selectedVehicleId' => null];
    $this->call('PUT', '/api/me/garage', [], [], [], $auth, json_encode(['expectedRevision' => 0, 'operationId' => (string) Str::uuid(), 'garage' => $garage]))->assertOk();
    $this->call('PUT', '/api/me/garage', [], [], [], $auth, json_encode(['expectedRevision' => 0, 'operationId' => (string) Str::uuid(), 'garage' => $garage]))->assertStatus(409);
});

it('a held row lock makes the writer fail without partial writes', function () {
    config(['otosanayide.flags.mobile_garage_backup' => true]);
    [$uid, $auth] = raceAccount('l@example.com');
    $empty = ['version' => 1, 'vehicles' => [], 'records' => [], 'selectedVehicleId' => null];
    $other = rival();
    // Rakip satiri commit eder: test transaction'indaki kayit ikinci baglantiya gorunmez.
    $other->table('garage_backups')->insert(['user_id' => $uid, 'revision' => 1, 'updated_at_iso' => '2026-01-01T00:00:00.000Z', 'operation_id' => (string) Str::uuid(), 'garage' => json_encode($empty)]);
    try {
        $other->beginTransaction();
        $other->table('garage_backups')->where('user_id', $uid)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $r = $this->call('PUT', '/api/me/garage', [], [], [], $auth, json_encode(['expectedRevision' => 1, 'operationId' => (string) Str::uuid(), 'garage' => $empty]));
        $other->rollBack();
        expect($r->getStatusCode())->toBe(409);
        expect((int) $other->table('garage_backups')->where('user_id', $uid)->value('revision'))->toBe(1);
    } finally {
        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }
        $other->table('garage_backups')->where('user_id', $uid)->delete();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }
});

it('two appointments cannot hold one slot (primary key equals appointmentSlots)', function () {
    DB::table('appointment_slots')->insert(['business_id' => 'b', 'starts_at_ms' => 1, 'request_id' => 'r1', 'starts_at' => 'x']);
    expect(fn () => DB::table('appointment_slots')->insert(['business_id' => 'b', 'starts_at_ms' => 1, 'request_id' => 'r2', 'starts_at' => 'x']))->toThrow(UniqueConstraintViolationException::class);
});

it('one open claim per lock key, reopen allowed after close (claimLocks equivalence)', function () {
    $row = fn (string $id, string $status) => ['id' => $id, 'user_id' => 'u', 'name' => 'n', 'city' => 'c', 'address' => 'a', 'contact_name' => 'c', 'phone' => 'p', 'note' => '', 'status' => $status, 'lock_key' => str_repeat('a', 64)];
    DB::table('claims')->insert($row('c1', 'submitted'));
    expect(fn () => DB::table('claims')->insert($row('c2', 'submitted')))->toThrow(UniqueConstraintViolationException::class);
    DB::table('claims')->where('id', 'c1')->update(['status' => 'rejected']);
    DB::table('claims')->insert($row('c2', 'submitted'));
    expect(DB::table('claims')->count())->toBe(2);
});

it('one business per identity key (businessIdentityKeys equivalence)', function () {
    $b = fn (string $id) => ['id' => $id, 'name' => 'n', 'city' => 'c', 'district' => 'd', 'address' => 'a', 'phone' => '', 'website' => '', 'description' => '', 'identity_key' => str_repeat('b', 64)];
    DB::table('businesses')->insert($b('x1'));
    expect(fn () => DB::table('businesses')->insert($b('x2')))->toThrow(UniqueConstraintViolationException::class);
});

it('a push token has one owner (pushTokenClaims equivalence)', function () {
    $p = fn (string $u, string $d) => ['user_id' => $u, 'device_id' => $d, 'generation' => (string) Str::uuid(), 'app_id' => 'a', 'platform' => 'web', 'locale' => 'tr', 'enabled' => true, 'version' => 1, 'updated_at_ms' => 1, 'refresh_after_ms' => 1, 'token_hash' => str_repeat('c', 64)];
    DB::table('push_devices')->insert($p('u1', (string) Str::uuid()));
    expect(fn () => DB::table('push_devices')->insert($p('u2', (string) Str::uuid())))->toThrow(UniqueConstraintViolationException::class);
});
