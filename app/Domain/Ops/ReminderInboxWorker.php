<?php

namespace App\Domain\Ops;

use App\Domain\Account\AccountFence;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * packages/server/reminder-delivery.ts deliverReminders + core/reminder-delivery.ts reminderDeliveryPlan.
 * Saglayici cagrilmaz: vadesi gelen hatirlatma icin uygulama ici kutu kaydi ve dis kanal kuyruk bayraklari yazilir.
 * Olay anahtari kullanici/hatirlatma/arac/tarih/km/erken gun/evre ozetidir; ayni olay ikinci kayit uretmez.
 */
final class ReminderInboxWorker
{
    public const PAGE = 50;

    private const DAY = 86400000;

    /** @return array{scanned:int,created:int,skipped:int,paused:int,next:?string} */
    public function run(bool $apply, ?int $now = null, ?string $after = null): array
    {
        $now ??= Js::nowMs();
        $q = DB::table('user_reminders')->whereNotNull('next_check_at_ms')->where('next_check_at_ms', '<=', $now);
        if ($after !== null) {
            [$dueMs, $uid, $id] = self::decodeCursor($after, $now);
            $q->where(fn ($w) => $w->where('next_check_at_ms', '>', $dueMs)
                ->orWhere(fn ($x) => $x->where('next_check_at_ms', $dueMs)->where('user_id', '>', $uid))
                ->orWhere(fn ($x) => $x->where('next_check_at_ms', $dueMs)->where('user_id', $uid)->where('id', '>', $id)));
        }
        $rows = $q->orderBy('next_check_at_ms')->orderBy('user_id')->orderBy('id')->limit(self::PAGE)->get(['user_id', 'id', 'next_check_at_ms']);
        $last = $rows->last();
        $next = $rows->count() === self::PAGE && $last !== null ? self::encodeCursor((int) $last->next_check_at_ms, $last->user_id, $last->id) : null;
        $result = ['scanned' => $rows->count(), 'created' => 0, 'skipped' => 0, 'paused' => 0, 'next' => $next];
        if (! $apply) {
            return $result;
        }
        foreach ($rows as $row) {
            $created = Tx::run(fn () => $this->one($row->user_id, $row->id, $now));
            if ($created === -1) {
                $result['paused']++;
            } else {
                $result['created'] += $created;
                if ($created === 0) {
                    $result['skipped']++;
                }
            }
        }

        return $result;
    }

    private function one(string $uid, string $rid, int $now): int
    {
        if (AccountFence::paused($uid)) {
            return -1;
        }
        $r = DB::table('user_reminders')->where('user_id', $uid)->where('id', $rid)->lockForUpdate()->first();
        if ($r === null || $r->next_check_at_ms === null || (int) $r->next_check_at_ms > $now) {
            return 0;
        }
        $reminder = DB::table('user_reminders')->where('user_id', $uid)->where('id', $rid);
        $vehicle = preg_match('/^[A-Za-z0-9_-]{1,120}$/D', (string) $r->vehicle_id) === 1
            ? DB::table('user_vehicles')->where('user_id', $uid)->where('id', $r->vehicle_id)->sharedLock()->first() : null;
        if ($vehicle === null) {
            $reminder->update(['next_check_at_ms' => null]);

            return 0;
        }
        if ((bool) $vehicle->archived && ! (bool) $r->completed) {
            $reminder->update(['next_check_at_ms' => $now + self::DAY]);

            return 0;
        }
        $plan = self::plan($r, (int) $vehicle->mileage, $now);
        $count = 0;
        foreach ($plan['events'] as $event) {
            $key = hash('sha256', (string) json_encode([$uid, $rid, $r->vehicle_id, $r->due_date, $r->due_km === null ? null : (int) $r->due_km, (int) ($r->advance_days ?? 0), $event['phase']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $label = mb_substr(implode(' ', array_filter([$vehicle->brand, $vehicle->model, $vehicle->year], fn ($v) => $v !== null && $v !== '')), 0, 200);
            $count += DB::table('notifications')->insertOrIgnore([
                'id' => 'reminder_'.$key, 'kind' => 'reminder', 'audience_key' => 'user:'.$uid, 'reminder_id' => $rid, 'vehicle_id' => $r->vehicle_id,
                'vehicle_label' => $label, 'title' => mb_substr((string) $r->title, 0, 100), 'phase' => $event['phase'], 'due_date' => $r->due_date,
                'due_km' => $r->due_km, 'created_at_ms' => $event['scheduledAtMs'], 'delivered_at_ms' => $now,
                'email_pending' => (bool) $r->channel_email, 'push_pending' => (bool) $r->channel_push,
            ]);
        }
        $reminder->update(['next_check_at_ms' => $plan['nextCheckAtMs']]);

        return $count;
    }

    /**
     * reminderDeliveryPlan(): tarihler Europe/Istanbul 09:00 (+03:00).
     *
     * @return array{events:list<array{phase:string,scheduledAtMs:int}>,nextCheckAtMs:?int}
     */
    public static function plan(stdClass $r, ?int $mileage, int $now): array
    {
        if ((bool) $r->completed) {
            return ['events' => [], 'nextCheckAtMs' => null];
        }
        $events = [];
        $future = [];
        if ($r->due_date !== null && $r->due_date !== '') {
            $due = strtotime(substr((string) $r->due_date, 0, 10).'T09:00:00+03:00') * 1000;
            $advance = $due - ((int) ($r->advance_days ?? 0)) * self::DAY;
            if ($due <= $now) {
                $events[] = ['phase' => 'due', 'scheduledAtMs' => $due];
            } else {
                $future[] = $due;
                if ($advance < $due) {
                    if ($advance <= $now) {
                        $events[] = ['phase' => 'advance', 'scheduledAtMs' => $advance];
                    } else {
                        $future[] = $advance;
                    }
                }
            }
        }
        if ($r->due_km !== null) {
            if ($mileage !== null && $mileage >= (int) $r->due_km) {
                $events[] = ['phase' => 'mileage', 'scheduledAtMs' => $now];
            } else {
                $future[] = $now + self::DAY;
            }
        }

        return ['events' => $events, 'nextCheckAtMs' => $future === [] ? null : min($future)];
    }

    public static function encodeCursor(int $dueMs, string $uid, string $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'k' => 'reminder', 'd' => $dueMs, 'u' => $uid, 'i' => $id])), '+/', '-_'), '=');
    }

    /** @return array{int,string,string} */
    public static function decodeCursor(string $after, int $now): array
    {
        if (strlen($after) > 1000 || preg_match('/^[A-Za-z0-9_-]+$/D', $after) !== 1) {
            throw new RuntimeException('delivery_cursor');
        }
        $c = json_decode((string) base64_decode(strtr($after, '-_', '+/'), true), true);
        if (! is_array($c) || ($c['v'] ?? null) !== 1 || ($c['k'] ?? null) !== 'reminder' || ! is_int($c['d'] ?? null) || $c['d'] > $now
            || ! is_string($c['u'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $c['u']) !== 1 || ! is_string($c['i'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $c['i']) !== 1) {
            throw new RuntimeException('delivery_cursor');
        }

        return [$c['d'], $c['u'], $c['i']];
    }
}
