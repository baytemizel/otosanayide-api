<?php

use App\Domain\Ops\RequestNotificationWorker;
use Database\Seeders\AdminAccountSeeder;
use Database\Seeders\LocalFixtureSeeder;
use Illuminate\Support\Facades\DB;

function job(string $id, array $over = []): void
{
    DB::table('notification_jobs')->insert($over + ['id' => $id, 'request_id' => 'r', 'business_id' => 'b', 'customer_id' => 'c', 'request_version' => 1, 'request_status' => 'requested', 'created_at_ms' => 1, 'state' => 'pending', 'attempts' => 0, 'next_attempt_at_ms' => 1]);
}

it('delivers in-app once, supersedes stale jobs and only counts without apply', function () {
    DB::table('requests')->insert(['id' => 'r', 'business_id' => 'b', 'business_name' => 'x', 'user_id' => 'c', 'kind' => 'quotes', 'service' => 'x', 'vehicle' => 'x', 'message' => 'x', 'status' => 'requested', 'version' => 1]);
    job('r_1');
    job('r_stale', ['request_version' => 9]);
    $w = new RequestNotificationWorker;
    expect($w->run(false)['delivered'])->toBe(0)->and(DB::table('notifications')->count())->toBe(0);
    $out = $w->run(true);
    expect($out['delivered'])->toBe(1)->and($out['superseded'])->toBe(1)->and(DB::table('notifications')->count())->toBe(2);
});

it('removes only expired credentials', function () {
    DB::table('cache')->insert([['key' => 'old', 'value' => 'x', 'expiration' => 1], ['key' => 'new', 'value' => 'x', 'expiration' => time() + 100]]);
    $this->artisan('ops:cleanup')->assertSuccessful();
    expect(DB::table('cache')->pluck('key')->all())->toBe(['new']);
});

it('seeds an admin without storing the plain password and promotes existing accounts', function () {
    putenv('SEED_ADMIN_EMAIL=Admin@Example.com');
    putenv('SEED_ADMIN_PASSWORD=long-enough-pass-1');
    $this->seed(AdminAccountSeeder::class);
    $row = DB::table('auth_accounts')->first();
    expect($row->email)->toBe('admin@example.com')->and((bool) $row->is_admin)->toBeTrue()->and($row->password_hash)->toStartWith('$argon2id$');
    putenv('SEED_ADMIN_EMAIL');
    putenv('SEED_ADMIN_PASSWORD');
});

it('makes synthetic fixtures searchable', function () {
    $this->seed(LocalFixtureSeeder::class);
    expect(DB::table('business_search')->count())->toBe(4);
});
