<?php

use App\Domain\Auth\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function adminAuth(bool $admin = true): array
{
    Queue::fake();
    $s = new AuthService('127.0.0.1');
    $email = $admin ? 'a@example.com' : 'u@example.com';
    $s->register($email, 'correct-horse-1');
    DB::table('auth_accounts')->where('email', $email)->update(['email_verified_at' => now('UTC'), 'is_admin' => $admin]);

    return ['HTTP_AUTHORIZATION' => 'Bearer '.$s->login($email, 'correct-horse-1')['session']->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''];
}

it('forbids non-admins and requeues only failed jobs', function () {
    DB::table('notification_jobs')->insert(['id' => 'j1', 'request_id' => 'r', 'business_id' => 'b', 'customer_id' => 'c', 'request_version' => 1, 'request_status' => 'requested', 'created_at_ms' => 1, 'state' => 'failed', 'attempts' => 5]);
    $body = json_encode(['id' => 'j1', 'reason' => 'Elle yeniden deneme kaydı']);
    $this->call('PATCH', '/api/admin/notifications', [], [], [], adminAuth(false), $body)->assertStatus(403);
    $admin = adminAuth();
    $this->call('GET', '/api/admin/notifications', [], [], [], $admin)->assertOk()->assertJsonPath('items.0.id', 'j1');
    $this->call('PATCH', '/api/admin/notifications', [], [], [], $admin, $body)->assertOk();
    $this->call('PATCH', '/api/admin/notifications', [], [], [], $admin, $body)->assertStatus(409);
    expect(DB::table('notification_job_history')->count())->toBe(1)->and(DB::table('notification_jobs')->value('state'))->toBe('pending');
});

it('closes privacy queue behind the flag', function () {
    $this->call('GET', '/api/admin/privacy', [], [], [], adminAuth())->assertStatus(503);
});
