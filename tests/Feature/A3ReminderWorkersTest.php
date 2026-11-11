<?php

use App\Domain\Ops\ReminderInboxWorker;
use App\Domain\Ops\ReminderOutboundWorker;
use App\Domain\Ops\ScheduledRuns;
use App\Domain\Push\PushMessage;
use App\Domain\Push\PushResult;
use App\Domain\Push\PushSender;
use App\Domain\Push\PushSenders;
use App\Domain\Push\PushUncertain;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A3 hatirlatma isleri. Uretim veritabanina baglanilmaz (mysql DB_PORT=1 korunur): bu dosya surec ici
 * SQLite bellek veritabani kullanir. Tablolar yalniz islerin dokundugu kolonlarla elle kurulur.
 * Bu bir MySQL kabulu DEGILDIR: kilit, izolasyon ve ON DUPLICATE KEY davranisi gercek motorda dogrulanmadi.
 * ExternalCalls'taki MySQL'e ozgu `ON DUPLICATE KEY UPDATE` yalniz bu test PDO'sunda SQLite karsiligina cevrilir.
 */

final class A3SqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('ON DUPLICATE KEY UPDATE `owner_uid` = VALUES(`owner_uid`)', 'ON CONFLICT(`owner_uid`) DO NOTHING', $query);

        return parent::prepare($query, $options);
    }
}

final class A3FakeSender implements PushSender
{
    /** @var list<PushMessage> */
    public array $sent = [];

    /** @param array<string,string> $plan token => accepted|invalid|uncertain */
    public function __construct(public array $plan = []) {}

    public function bindingDigest(): string
    {
        return hash('sha256', 'a3-fake');
    }

    public function send(PushMessage $message): PushResult
    {
        $this->sent[] = $message;

        return match ($this->plan[$message->token] ?? 'accepted') {
            'invalid' => PushResult::invalidToken(),
            'uncertain' => throw new PushUncertain('push_provider_uncertain'),
            default => PushResult::accepted('msg-'.count($this->sent)),
        };
    }
}

final class A3Senders extends PushSenders
{
    public function __construct(public A3FakeSender $sender) {}

    public function for(string $provider): PushSender
    {
        return $this->sender;
    }
}

const A3_NOW = 1_800_000_000_000; // 2027-01-15
const A3_DAY = 86400000;
const A3_DEVICE_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const A3_DEVICE_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

beforeEach(function () {
    config(['database.connections.a3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false], 'database.default' => 'a3']);
    DB::purge('a3');
    DB::connection('a3')->setPdo(new A3SqlitePdo('sqlite::memory:'));
    $s = Schema::connection('a3');
    $s->create('account_fences', fn (Blueprint $t) => [$t->string('owner_uid')->primary(), $t->string('state')]);
    $s->create('auth_accounts', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('email'), $t->string('email_verified_at')->nullable(), $t->string('status')->default('active')]);
    $s->create('user_vehicles', fn (Blueprint $t) => [$t->string('user_id'), $t->string('id'), $t->string('brand'), $t->string('model'), $t->integer('year'), $t->integer('mileage'), $t->boolean('archived')->default(false), $t->primary(['user_id', 'id'])]);
    $s->create('user_reminders', fn (Blueprint $t) => [$t->string('user_id'), $t->string('id'), $t->string('vehicle_id'), $t->string('title'), $t->boolean('completed')->nullable(), $t->string('due_date')->nullable(), $t->integer('due_km')->nullable(), $t->boolean('channel_push')->default(false), $t->boolean('channel_email')->default(false), $t->integer('advance_days')->nullable(), $t->bigInteger('next_check_at_ms')->nullable(), $t->primary(['user_id', 'id'])]);
    $s->create('notifications', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('audience_key'), $t->string('business_id')->nullable(), $t->string('request_id')->nullable(), $t->string('request_status')->nullable(), $t->integer('request_version')->nullable(), $t->string('reminder_id')->nullable(), $t->string('vehicle_id')->nullable(), $t->string('kind')->nullable(), $t->string('vehicle_label')->nullable(), $t->string('title')->nullable(), $t->string('phase')->nullable(), $t->string('due_date')->nullable(), $t->integer('due_km')->nullable(), $t->bigInteger('created_at_ms'), $t->bigInteger('delivered_at_ms')->nullable(), $t->boolean('email_pending')->default(false), $t->boolean('push_pending')->default(false), $t->bigInteger('email_queue_closed_at_ms')->nullable(), $t->bigInteger('push_queue_closed_at_ms')->nullable(), $t->string('email_queue_outcome')->nullable(), $t->string('push_queue_outcome')->nullable()]);
    $s->create('notification_deliveries', fn (Blueprint $t) => [$t->string('notification_id'), $t->string('channel'), $t->string('device_id')->default(''), $t->string('state'), $t->string('source_digest'), $t->string('idempotency_key'), $t->string('message_id')->nullable(), $t->bigInteger('updated_at_ms'), $t->primary(['notification_id', 'channel', 'device_id'])]);
    $s->create('push_devices', fn (Blueprint $t) => [$t->string('user_id'), $t->string('device_id'), $t->string('generation'), $t->string('app_id'), $t->string('platform'), $t->string('provider')->default('fcm'), $t->string('apns_environment')->nullable(), $t->string('locale'), $t->boolean('enabled'), $t->integer('version'), $t->bigInteger('updated_at_ms'), $t->bigInteger('refresh_after_ms'), $t->text('token')->nullable(), $t->string('token_hash')->nullable()->unique(), $t->primary(['user_id', 'device_id'])]);
    $s->create('scheduled_runs', fn (Blueprint $t) => [$t->string('job')->primary(), $t->string('binding_digest'), $t->string('run_id'), $t->string('state'), $t->integer('version'), $t->string('cursor')->nullable(), $t->bigInteger('updated_at_ms'), $t->bigInteger('lease_until_ms'), $t->integer('completed_pages')->default(0), $t->integer('completed_sweeps')->default(0), $t->text('last_counts')->nullable(), $t->string('recovered_by')->nullable()]);
    $s->create('user_preferences', fn (Blueprint $t) => [$t->string('user_id')->primary(), $t->integer('version'), $t->boolean('reminder_email')->default(true), $t->bigInteger('updated_at_ms')]);
    $s->create('account_external_barriers', fn (Blueprint $t) => [$t->string('owner_uid')->primary(), $t->integer('active')->default(0), $t->integer('uncertain')->default(0), $t->integer('sequence')->default(0)]);
    $s->create('account_external_operations', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('kind'), $t->string('binding_digest'), $t->string('resource_key'), $t->string('source_digest'), $t->string('state'), $t->integer('version')->default(1), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms')]);
    $s->create('account_external_operation_subjects', fn (Blueprint $t) => [$t->string('operation_id'), $t->string('uid'), $t->primary(['operation_id', 'uid'])]);

    DB::table('auth_accounts')->insert(['id' => 'u1', 'email' => 'u1@example.com', 'email_verified_at' => '2026-01-01 00:00:00.000']);
    DB::table('user_vehicles')->insert(['user_id' => 'u1', 'id' => 'v1', 'brand' => 'Fiat', 'model' => 'Egea', 'year' => 2020, 'mileage' => 50000]);
});

function a3Reminder(array $over = []): void
{
    DB::table('user_reminders')->insert($over + ['user_id' => 'u1', 'id' => 'r1', 'vehicle_id' => 'v1', 'title' => 'Periyodik bakım', 'completed' => false, 'due_date' => '2027-01-10', 'due_km' => null, 'channel_push' => true, 'channel_email' => true, 'advance_days' => 7, 'next_check_at_ms' => A3_NOW - 1000]);
}

function a3Device(string $id, string $token, array $over = []): void
{
    DB::table('push_devices')->insert($over + ['user_id' => 'u1', 'device_id' => $id, 'generation' => $id, 'app_id' => 'com.otosanayide.app', 'platform' => 'ios', 'provider' => 'apns', 'apns_environment' => 'production', 'locale' => 'en', 'enabled' => true, 'version' => 1, 'updated_at_ms' => A3_NOW, 'refresh_after_ms' => A3_NOW, 'token' => $token, 'token_hash' => hash('sha256', $token)]);
}

function a3Outbound(?A3FakeSender $sender = null, int $now = A3_NOW): ReminderOutboundWorker
{
    return new ReminderOutboundWorker(new A3Senders($sender ?? new A3FakeSender), fn () => $now);
}

it('writes the due reminder to the inbox once and queues the chosen channels', function () {
    a3Reminder();
    $worker = new ReminderInboxWorker;
    expect($worker->run(false, A3_NOW))->toMatchArray(['scanned' => 1, 'created' => 0]);
    expect(DB::table('notifications')->count())->toBe(0);

    expect($worker->run(true, A3_NOW))->toMatchArray(['scanned' => 1, 'created' => 1, 'paused' => 0, 'next' => null]);
    $n = DB::table('notifications')->first();
    expect($n->id)->toStartWith('reminder_')->and($n->kind)->toBe('reminder')->and($n->phase)->toBe('due')
        ->and($n->audience_key)->toBe('user:u1')->and($n->vehicle_label)->toBe('Fiat Egea 2020')
        ->and((bool) $n->email_pending)->toBeTrue()->and((bool) $n->push_pending)->toBeTrue()
        ->and(DB::table('user_reminders')->value('next_check_at_ms'))->toBeNull();

    // Ayni olay ikinci kayit uretmez.
    DB::table('user_reminders')->update(['next_check_at_ms' => A3_NOW - 1]);
    expect($worker->run(true, A3_NOW)['created'])->toBe(0)->and(DB::table('notifications')->count())->toBe(1);
});

it('plans advance, due and mileage events like the source', function () {
    $r = (object) ['completed' => false, 'due_date' => '2027-01-20', 'advance_days' => 7, 'due_km' => 60000];
    $due = strtotime('2027-01-20T09:00:00+03:00') * 1000;
    expect(ReminderInboxWorker::plan($r, 50000, A3_NOW))->toBe(['events' => [['phase' => 'advance', 'scheduledAtMs' => $due - 7 * A3_DAY]], 'nextCheckAtMs' => A3_NOW + A3_DAY]);
    expect(ReminderInboxWorker::plan($r, 60000, A3_NOW)['events'][1])->toBe(['phase' => 'mileage', 'scheduledAtMs' => A3_NOW]);
    expect(ReminderInboxWorker::plan((object) (['completed' => true] + (array) $r), 70000, A3_NOW))->toBe(['events' => [], 'nextCheckAtMs' => null]);
});

it('skips paused accounts and parks archived vehicles for a day', function () {
    a3Reminder();
    DB::table('account_fences')->insert(['owner_uid' => 'u1', 'state' => 'deleting']);
    expect((new ReminderInboxWorker)->run(true, A3_NOW))->toMatchArray(['paused' => 1, 'created' => 0]);
    DB::table('account_fences')->delete();
    DB::table('user_vehicles')->update(['archived' => true]);
    expect((new ReminderInboxWorker)->run(true, A3_NOW)['created'])->toBe(0)
        ->and(DB::table('user_reminders')->value('next_check_at_ms'))->toBe(A3_NOW + A3_DAY);
});

it('rejects a forged inbox cursor', function () {
    expect(fn () => ReminderInboxWorker::decodeCursor('not base64!', A3_NOW))->toThrow(RuntimeException::class)
        ->and(fn () => ReminderInboxWorker::decodeCursor(ReminderInboxWorker::encodeCursor(A3_NOW + 1, 'u1', 'r1'), A3_NOW))->toThrow(RuntimeException::class)
        ->and(ReminderInboxWorker::decodeCursor(ReminderInboxWorker::encodeCursor(A3_NOW, 'u1', 'r1'), A3_NOW))->toBe([A3_NOW, 'u1', 'r1']);
});

it('sends the reminder e-mail once, records acceptance and closes the queue', function () {
    a3Reminder();
    (new ReminderInboxWorker)->run(true, A3_NOW);
    $transport = app('mail.manager')->mailer()->getSymfonyTransport();
    $transport->flush();

    $page = a3Outbound()->page('email', null, true);
    expect($page['counts'])->toMatchArray(['scanned' => 1, 'attempts' => 1, 'accepted' => 1, 'closed' => 1, 'blocked' => 0]);
    $mail = $transport->messages()->first()->getOriginalMessage();
    // Push cihazi yok: dil varsayilan tr. Govdede arac/baslik yok.
    expect($mail->getSubject())->toBe('Otosanayide araç hatırlatması')
        ->and($mail->getTextBody())->toContain('https://otosanayide.test/hesabim')->not->toContain('Egea')->not->toContain('Periyodik');
    expect(DB::table('notifications')->value('email_queue_outcome'))->toBe('processed')
        ->and(DB::table('notification_deliveries')->value('state'))->toBe('accepted')
        ->and(DB::table('account_external_operations')->value('state'))->toBe('uncertain'); // kabul terminal teslim degildir

    // Ikinci calisma: kuyruk kapali, ikinci e-posta yok.
    expect(a3Outbound()->page('email', null, true)['counts']['scanned'])->toBe(0)->and($transport->messages())->toHaveCount(1);
});

it('uses the English template when the latest device is English', function () {
    a3Reminder();
    a3Device(A3_DEVICE_A, str_repeat('ab', 32));
    (new ReminderInboxWorker)->run(true, A3_NOW);
    expect((new ReminderOutboundWorker(new A3Senders(new A3FakeSender)))->text('en'))->toContain('Check your vehicle maintenance or inspection reminder: https://otosanayide.test/hesabim');
    $transport = app('mail.manager')->mailer()->getSymfonyTransport();
    $transport->flush();
    a3Outbound()->page('email', null, true);
    expect($transport->messages()->first()->getOriginalMessage()->getSubject())->toBe('Otosanayide vehicle reminder');
});

it('does not e-mail an unverified recipient and leaves no uncertain record', function () {
    a3Reminder();
    DB::table('auth_accounts')->update(['email_verified_at' => null]);
    (new ReminderInboxWorker)->run(true, A3_NOW);
    expect(a3Outbound()->page('email', null, true)['counts'])->toMatchArray(['attempts' => 1, 'blocked' => 1, 'accepted' => 0])
        ->and(DB::table('notification_deliveries')->count())->toBe(0)
        ->and(DB::table('account_external_operations')->count())->toBe(0);
});

it('pushes to every enabled device, removes invalid tokens and closes the queue', function () {
    a3Reminder(['channel_email' => false]);
    a3Device(A3_DEVICE_A, str_repeat('aa', 32));
    a3Device(A3_DEVICE_B, str_repeat('bb', 32));
    (new ReminderInboxWorker)->run(true, A3_NOW);
    $sender = new A3FakeSender([str_repeat('bb', 32) => 'invalid']);

    $page = a3Outbound($sender)->page('push', null, true);
    expect($page['counts'])->toMatchArray(['attempts' => 2, 'accepted' => 1, 'invalid' => 1, 'closed' => 1, 'blocked' => 0]);
    expect($sender->sent[0]->body)->toBe('Check your vehicle maintenance or inspection reminder.')
        ->and($sender->sent[0]->route)->toBe('/hesabim')
        ->and($sender->sent[0]->provider)->toBe('apns')
        ->and($sender->sent[0]->environment)->toBe('production');
    expect(DB::table('push_devices')->pluck('device_id')->all())->toBe([A3_DEVICE_A])
        ->and(DB::table('notifications')->value('push_queue_outcome'))->toBe('processed');
});

it('never resends an uncertain push and keeps the queue open for review', function () {
    a3Reminder(['channel_email' => false]);
    a3Device(A3_DEVICE_A, str_repeat('cc', 32));
    (new ReminderInboxWorker)->run(true, A3_NOW);
    $sender = new A3FakeSender([str_repeat('cc', 32) => 'uncertain']);

    expect(a3Outbound($sender)->page('push', null, true)['counts'])->toMatchArray(['attempts' => 1, 'blocked' => 1, 'closed' => 0]);
    expect(DB::table('notification_deliveries')->value('state'))->toBe('uncertain')
        ->and(DB::table('account_external_barriers')->value('uncertain'))->toBe(1);

    $sender->plan = [];
    expect(a3Outbound($sender)->page('push', null, true)['counts'])->toMatchArray(['blocked' => 1, 'accepted' => 0])
        ->and($sender->sent)->toHaveCount(1)
        ->and((bool) DB::table('notifications')->value('push_pending'))->toBeTrue();
});

it('closes stale or changed queues without calling a provider', function () {
    a3Reminder(['channel_email' => false]);
    a3Device(A3_DEVICE_A, str_repeat('dd', 32));
    (new ReminderInboxWorker)->run(true, A3_NOW);
    $sender = new A3FakeSender;
    DB::table('user_reminders')->update(['channel_push' => false]);
    expect(a3Outbound($sender)->page('push', null, true)['counts'])->toMatchArray(['closed' => 1, 'attempts' => 0]);
    expect(DB::table('notifications')->value('push_queue_outcome'))->toBe('preference_changed')->and($sender->sent)->toBe([]);

    DB::table('notifications')->update(['push_pending' => true, 'push_queue_outcome' => null]);
    DB::table('user_reminders')->update(['channel_push' => true]);
    expect(a3Outbound($sender, A3_NOW + 8 * A3_DAY)->page('push', null, true)['counts'])->toMatchArray(['closed' => 1, 'attempts' => 0]);
    expect(DB::table('notifications')->value('push_queue_outcome'))->toBe('expired')->and($sender->sent)->toBe([]);
});

it('runs one page per scheduled run and blocks after a failure until recovery', function () {
    $clock = fn () => A3_NOW;
    $r = ScheduledRuns::page('reminder_inbox', fn (?string $c) => ['next' => 'c1', 'counts' => ['scanned' => 50]], 1000, $clock);
    expect($r)->toBe(['state' => 'completed_page', 'next' => 'c1', 'counts' => ['scanned' => 50]]);
    $seen = null;
    ScheduledRuns::page('reminder_inbox', function (?string $c) use (&$seen) {
        $seen = $c;

        return ['next' => null, 'counts' => []];
    }, 1000, $clock);
    expect($seen)->toBe('c1');
    $row = DB::table('scheduled_runs')->first();
    expect($row->state)->toBe('idle')->and((int) $row->completed_pages)->toBe(2)->and((int) $row->completed_sweeps)->toBe(1)->and($row->cursor)->toBeNull();

    expect(fn () => ScheduledRuns::page('reminder_inbox', fn () => throw new RuntimeException('boom'), 1000, $clock))->toThrow(RuntimeException::class, 'scheduler_recovery_required');
    $version = (int) DB::table('scheduled_runs')->value('version');
    expect(DB::table('scheduled_runs')->value('state'))->toBe('uncertain');
    $ran = false;
    expect(fn () => ScheduledRuns::page('reminder_inbox', function () use (&$ran) {
        $ran = true;

        return ['next' => null, 'counts' => []];
    }, 1000, $clock))->toThrow(RuntimeException::class, 'scheduler_recovery_required');
    expect($ran)->toBeFalse();

    expect(fn () => ScheduledRuns::recover('reminder_inbox', $version + 1, false, 'cli'))->toThrow(RuntimeException::class, 'scheduler_review_changed');
    ScheduledRuns::recover('reminder_inbox', $version, false, 'cli');
    expect(DB::table('scheduled_runs')->value('state'))->toBe('idle')->and(DB::table('scheduled_runs')->value('recovered_by'))->toBe('cli');
});

it('marks a run uncertain when it outlives its lease and refuses a stalled cursor', function () {
    $t = A3_NOW;
    expect(fn () => ScheduledRuns::page('reminder_push', function () use (&$t) {
        $t += 10_000;

        return ['next' => null, 'counts' => []];
    }, 1000, function () use (&$t) {
        return $t;
    }))->toThrow(RuntimeException::class);
    expect(DB::table('scheduled_runs')->value('state'))->toBe('uncertain');

    ScheduledRuns::page('reminder_email', fn () => ['next' => 'x', 'counts' => []], 1000, fn () => A3_NOW);
    expect(fn () => ScheduledRuns::page('reminder_email', fn () => ['next' => 'x', 'counts' => []], 1000, fn () => A3_NOW))->toThrow(RuntimeException::class);
});

it('refuses --apply while the worker flags are off and registers no reminder schedule', function () {
    $this->artisan('reminders:deliver-inbox --apply')->assertFailed();
    $this->artisan('reminders:deliver-email --apply')->assertFailed();
    $this->artisan('reminders:deliver-push --apply')->assertFailed();
    $this->artisan('ops:scheduled-recover nope')->assertFailed();
    $events = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
    expect($events)->not->toContain('reminders:');
});

it('requires both the worker and the provider flag before scheduling outbound work', function () {
    config(['otosanayide.workers.reminder_inbox' => true, 'otosanayide.workers.reminder_push' => true, 'otosanayide.workers.reminder_email' => true]);
    require base_path('routes/console.php');
    $events = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
    expect($events)->toContain('reminders:deliver-inbox --apply')->not->toContain('reminders:deliver-push')->not->toContain('reminders:deliver-email');
    config(['otosanayide.flags.push_delivery' => true, 'otosanayide.reminder_email_delivery' => true]);
    require base_path('routes/console.php');
    $events = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
    expect($events)->toContain('reminders:deliver-push --apply')->toContain('reminders:deliver-email --apply');
});
