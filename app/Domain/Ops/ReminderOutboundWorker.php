<?php

namespace App\Domain\Ops;

use App\Domain\Account\AccountFence;
use App\Domain\Content\ExternalCalls;
use App\Domain\Push\PushMessage;
use App\Domain\Push\PushSenders;
use App\Domain\User\Preferences;
use App\Support\I18n;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * notification-outbound-page.ts + reminder-email.ts + reminder-push.ts.
 * Yalniz reminder_inbox'in yeni kuyruga aldigi olaylar taranir; eski kutu gecmisi dis kanala oynatilmaz.
 * Her teslim prepared -> dispatched -> accepted/invalid_token; hata veya belirsiz sonuc `uncertain` kalir ve
 * ayni bildirim/cihaz icin bir daha GONDERILMEZ (ExternalCalls defteri ayrica ayni kaynak anahtarini kilitler).
 * Kabul (accepted) terminal teslim degildir: defter kaydi saglayici mutabakati icin uncertain birakilir (kaynakla ayni).
 */
final class ReminderOutboundWorker
{
    public const PAGE = 25;

    private const FRESH_MS = 7 * 86400000;

    private ?\Closure $clock;

    public function __construct(private readonly PushSenders $senders, ?\Closure $clock = null)
    {
        $this->clock = $clock;
    }

    /** @return array{next:?string,counts:array<string,int>} */
    public function page(string $channel, ?string $after, bool $apply, int $maxAttempts = 10): array
    {
        if (! in_array($channel, ['email', 'push'], true) || $maxAttempts < 1 || $maxAttempts > 20) {
            throw new RuntimeException('outbound_channel');
        }
        if ($after !== null && preg_match('/^reminder_[a-f0-9]{64}(?:\/[a-f0-9-]{36})?$/D', $after) !== 1) {
            throw new RuntimeException('outbound_cursor');
        }
        [$notificationAfter, $deviceAfter] = array_pad($after === null ? [] : explode('/', $after, 2), 2, null);
        $field = $channel.'_pending';
        $q = DB::table('notifications')->where($field, true)->orderBy('id')->limit(self::PAGE);
        if ($notificationAfter !== null) {
            $q->where('id', '>', $notificationAfter);
        }
        $rows = $q->pluck('id')->all();
        if ($deviceAfter !== null && DB::table('notifications')->where('id', $notificationAfter)->where($field, true)->exists()) {
            array_unshift($rows, $notificationAfter);
        }
        $pageFull = count($rows) - ($deviceAfter !== null ? 1 : 0) >= self::PAGE;
        $counts = ['scanned' => 0, 'attempts' => 0, 'accepted' => 0, 'invalid' => 0, 'blocked' => 0, 'closed' => 0];
        if (! $apply) {
            return ['next' => $after, 'counts' => ['scanned' => count($rows)] + $counts];
        }
        $last = $after;
        foreach ($rows as $id) {
            $counts['scanned']++;
            try {
                $closed = $this->close($channel, $id, false);
            } catch (Throwable) {
                $counts['blocked']++;
                $last = $id;

                continue;
            }
            if ($closed) {
                $counts['closed']++;
                $last = $id;

                continue;
            }
            if ($channel === 'email') {
                if ($counts['attempts'] >= $maxAttempts) {
                    return ['next' => $last, 'counts' => $counts];
                }
                $counts['attempts']++;
                try {
                    $this->email($id);
                    $counts['accepted']++;
                    if ($this->close('email', $id, true)) {
                        $counts['closed']++;
                    }
                } catch (Throwable) {
                    $counts['blocked']++;
                }
                $last = $id;

                continue;
            }
            $uid = $this->audience($id);
            if ($uid === null) {
                $counts['blocked']++;
                $last = $id;

                continue;
            }
            $devices = DB::table('push_devices')->where('user_id', $uid)->orderBy('device_id')->limit(21);
            if ($deviceAfter !== null && $id === $notificationAfter) {
                $devices->where('device_id', '>', $deviceAfter);
            }
            $list = $devices->get(['device_id', 'enabled']);
            if ($list->count() > 20) {
                $counts['blocked']++;
                $last = $id;

                continue;
            }
            $all = true;
            foreach ($list as $device) {
                if (! (bool) $device->enabled) {
                    continue;
                }
                if ($counts['attempts'] >= $maxAttempts) {
                    return ['next' => $last, 'counts' => $counts];
                }
                $counts['attempts']++;
                try {
                    $state = $this->push($id, $uid, $device->device_id);
                    $counts[$state === 'accepted' ? 'accepted' : 'invalid']++;
                } catch (Throwable) {
                    $counts['blocked']++;
                    $all = false;
                }
                $last = $id.'/'.$device->device_id;
            }
            if ($all) {
                try {
                    if ($this->close('push', $id, true)) {
                        $counts['closed']++;
                    }
                } catch (Throwable) {
                    $counts['blocked']++;
                }
            }
            $last = $id;
        }

        return ['next' => $pageFull ? $last : null, 'counts' => $counts];
    }

    private function audience(string $id): ?string
    {
        $audience = DB::table('notifications')->where('id', $id)->value('audience_key');

        return is_string($audience) && preg_match('/^user:([A-Za-z0-9_-]{1,128})$/D', $audience, $m) === 1 ? $m[1] : null;
    }

    /** Kuyruk kapanisi: islenmis, tercih/kaynak degismis veya 7 gunden eski. */
    private function close(string $channel, string $id, bool $completed): bool
    {
        return Tx::run(function () use ($channel, $id, $completed): bool {
            $n = DB::table('notifications')->where('id', $id)->lockForUpdate()->first();
            if ($n === null || ! (bool) $n->{$channel.'_pending'}) {
                return false;
            }
            $uid = $this->uid($n);
            AccountFence::assertWritable($uid);
            [$r, $v] = $this->reminderAndVehicle($uid, $n);
            $expired = $this->now() - (int) $n->created_at_ms > self::FRESH_MS;
            $revoked = $r === null || $v === null || (bool) $v->archived || (bool) $r->completed || ! (bool) $r->{'channel_'.$channel} || ! $this->sameSource($r, $n)
                || ($channel === 'email' && ! Preferences::reminderEmailAllowed($uid)); // web-09 hesap duzeyi ust anahtar
            $processed = $completed;
            if ($completed && $channel === 'push') {
                $devices = DB::table('push_devices')->where('user_id', $uid)->limit(21)->get(['device_id', 'enabled']);
                $states = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'push')->pluck('state', 'device_id');
                $processed = $devices->count() <= 20 && $devices->every(fn (stdClass $d) => ! (bool) $d->enabled || in_array($states[$d->device_id] ?? null, ['accepted', 'invalid_token'], true));
            }
            if ($completed && $channel === 'email') {
                $processed = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'email')->where('device_id', '')->value('state') === 'accepted';
            }
            if (! $processed && ! $revoked && ! $expired) {
                return false;
            }
            DB::table('notifications')->where('id', $id)->update([$channel.'_pending' => false, $channel.'_queue_closed_at_ms' => $this->now(), $channel.'_queue_outcome' => $expired ? 'expired' : ($revoked ? 'preference_changed' : 'processed')]);

            return true;
        });
    }

    private function uid(stdClass $n): string
    {
        if ($n->kind !== 'reminder' || preg_match('/^user:([A-Za-z0-9_-]{1,128})$/D', (string) $n->audience_key, $m) !== 1) {
            throw new RuntimeException('outbound_subject');
        }

        return $m[1];
    }

    /** @return array{?stdClass,?stdClass} */
    private function reminderAndVehicle(string $uid, stdClass $n): array
    {
        $r = is_string($n->reminder_id) ? DB::table('user_reminders')->where('user_id', $uid)->where('id', $n->reminder_id)->sharedLock()->first() : null;
        $v = $r !== null ? DB::table('user_vehicles')->where('user_id', $uid)->where('id', $r->vehicle_id)->sharedLock()->first() : null;

        return [$r, $v];
    }

    private function sameSource(stdClass $r, stdClass $n): bool
    {
        return $r->vehicle_id === $n->vehicle_id && ($r->due_date ?? null) === ($n->due_date ?? null)
            && ($r->due_km === null ? null : (int) $r->due_km) === ($n->due_km === null ? null : (int) $n->due_km);
    }

    /**
     * Teslimin kaynagi: bildirimin degismez alanlari, hatirlatma, arac (+ cihaz). Herhangi biri degisirse teslim durur.
     *
     * @return array{uid:string,digest:string,current:?stdClass,device:?stdClass,email:?string,locale:string}
     */
    private function source(string $channel, string $id, string $deviceId): array
    {
        $n = DB::table('notifications')->where('id', $id)->lockForUpdate()->first();
        if ($n === null) {
            throw new RuntimeException($channel.'_source');
        }
        $uid = $this->uid($n);
        AccountFence::assertWritable($uid);
        [$r, $v] = $this->reminderAndVehicle($uid, $n);
        if ($r === null || (bool) $r->completed || ! (bool) $r->{'channel_'.$channel} || ! $this->sameSource($r, $n)) {
            throw new RuntimeException($channel.'_preference_or_source');
        }
        if ($v === null || (bool) $v->archived) {
            throw new RuntimeException($channel.'_vehicle');
        }
        $device = null;
        $email = null;
        if ($channel === 'push') {
            $device = DB::table('push_devices')->where('user_id', $uid)->where('device_id', $deviceId)->lockForUpdate()->first();
            if ($device === null || ! (bool) $device->enabled || ! is_string($device->token) || $device->token === '') {
                throw new RuntimeException('push_device_changed');
            }
            $locale = $device->locale;
        } else {
            if (! Preferences::reminderEmailAllowed($uid)) {
                throw new RuntimeException('email_preference_or_source');
            }
            // Kaynakta alici denetimi dis cagri icindeydi (hata belirsiz kalirdi); burada hazirliktan once yapilir.
            $account = DB::table('auth_accounts')->where('id', $uid)->first(['email', 'email_verified_at', 'status']);
            if ($account === null || $account->status !== 'active' || $account->email_verified_at === null) {
                throw new RuntimeException('email_recipient');
            }
            $email = (string) $account->email;
            $locale = DB::table('push_devices')->where('user_id', $uid)->orderByDesc('updated_at_ms')->value('locale') ?? 'tr';
        }
        $immutable = collect((array) $n)->except(['email_pending', 'push_pending', 'email_queue_closed_at_ms', 'push_queue_closed_at_ms', 'email_queue_outcome', 'push_queue_outcome'])->all();
        $digest = hash('sha256', (string) json_encode(['notification' => $immutable, 'reminder' => (array) $r, 'vehicle' => (array) $v, 'device' => $device === null ? null : ['generation' => $device->generation, 'version' => (int) $device->version, 'token_hash' => $device->token_hash, 'provider' => $device->provider ?? 'fcm']]));
        $current = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', $channel)->where('device_id', $deviceId)->lockForUpdate()->first();

        return ['uid' => $uid, 'digest' => $digest, 'current' => $current, 'device' => $device, 'email' => $email, 'locale' => $locale === 'en' ? 'en' : 'tr'];
    }

    private function email(string $id): void
    {
        $this->deliver('email', $id, '', function (array $s, string $key): array {
            $sent = Mail::raw($this->text($s['locale']), function ($message) use ($s): void {
                $message->to($s['email'])->subject(I18n::t('Otosanayide araç hatırlatması', [], $s['locale']));
            });
            if (! $sent instanceof SentMessage) {
                throw new RuntimeException('email_provider');
            }

            return ['state' => 'accepted', 'messageId' => substr((string) $sent->getMessageId(), 0, 500)];
        }, static fn (): bool => false, $this->emailBinding());
    }

    private function push(string $id, string $uid, string $deviceId): string
    {
        $provider = (string) (DB::table('push_devices')->where('user_id', $uid)->where('device_id', $deviceId)->value('provider') ?? 'fcm');
        $sender = $this->senders->for($provider);

        return $this->deliver('push', $id, $deviceId, function (array $s, string $key) use ($sender): array {
            $d = $s['device'];
            $result = $sender->send(new PushMessage((string) ($d->provider ?? 'fcm'), (string) $d->token, (string) $d->app_id, $d->apns_environment ?? null, $s['locale'], $key, 'Otosanayide', I18n::t('Araç bakım veya muayene hatırlatmanı kontrol et.', [], $s['locale'])));

            return ['state' => $result->status, 'messageId' => $result->messageId];
        }, static fn (array $r): bool => $r['state'] === 'invalid_token', $sender->bindingDigest());
    }

    /**
     * @param  \Closure(array{uid:string,digest:string,current:?stdClass,device:?stdClass,email:?string,locale:string},string):array{state:string,messageId:?string}  $send
     * @param  \Closure(array{state:string,messageId:?string}):bool  $settled
     */
    private function deliver(string $channel, string $id, string $deviceId, \Closure $send, \Closure $settled, string $binding): string
    {
        $key = hash('sha256', (string) json_encode($channel === 'email' ? ['id' => $id, 'channel' => 'email'] : ['notificationId' => $id, 'deviceId' => $deviceId, 'channel' => 'push']));
        $where = ['notification_id' => $id, 'channel' => $channel, 'device_id' => $deviceId];
        $prepared = Tx::run(function () use ($channel, $id, $deviceId, $key, $where): array {
            $s = $this->source($channel, $id, $deviceId);
            if ($s['current'] !== null && in_array($s['current']->state, ['accepted', 'invalid_token'], true)) {
                return $s + ['done' => $s['current']->state];
            }
            if ($s['current'] !== null) {
                throw new RuntimeException($channel.'_recovery_required');
            }
            DB::table('notification_deliveries')->insert($where + ['state' => 'prepared', 'source_digest' => $s['digest'], 'idempotency_key' => $key, 'updated_at_ms' => $this->now()]);

            return $s + ['done' => null];
        });
        if ($prepared['done'] !== null) {
            return $prepared['done'];
        }
        try {
            Tx::run(function () use ($channel, $id, $deviceId, $prepared, $where): void {
                $s = $this->source($channel, $id, $deviceId);
                if ($s['digest'] !== $prepared['digest'] || $s['current']?->state !== 'prepared') {
                    throw new RuntimeException($channel.'_changed');
                }
                DB::table('notification_deliveries')->where($where)->update(['state' => 'dispatched', 'updated_at_ms' => $this->now()]);
            });
            $result = ExternalCalls::call(['kind' => $channel, 'bindingDigest' => $binding, 'resourceKey' => $key, 'resolve' => function () use ($channel, $id, $deviceId, $prepared): array {
                $s = $this->source($channel, $id, $deviceId);
                if ($s['digest'] !== $prepared['digest'] || $s['current']?->state !== 'dispatched') {
                    throw new RuntimeException($channel.'_changed');
                }

                return ['subjects' => [$s['uid']], 'sourceDigest' => $s['digest']];
            }], fn () => $send($prepared, $key), $settled);
            Tx::run(function () use ($channel, $id, $deviceId, $prepared, $where, $result): void {
                $s = $this->source($channel, $id, $deviceId);
                if ($s['digest'] !== $prepared['digest'] || $s['current']?->state !== 'dispatched') {
                    throw new RuntimeException($channel.'_changed');
                }
                DB::table('notification_deliveries')->where($where)->update(['state' => $result['state'], 'message_id' => $result['messageId'], 'updated_at_ms' => $this->now()]);
                if ($result['state'] === 'invalid_token') {
                    DB::table('push_devices')->where('user_id', $s['uid'])->where('device_id', $deviceId)->delete();
                }
            });

            return $result['state'];
        } catch (Throwable) {
            try {
                Tx::run(function () use ($where): void {
                    $row = DB::table('notification_deliveries')->where($where)->lockForUpdate()->first();
                    if ($row !== null && ! in_array($row->state, ['accepted', 'invalid_token'], true)) {
                        DB::table('notification_deliveries')->where($where)->update(['state' => 'uncertain', 'updated_at_ms' => $this->now()]);
                    }
                });
            } catch (Throwable) {
                // Dondurulmus veya degismis kaynak kilitli kalir; korlemesine tekrar yok.
            }
            throw new RuntimeException($channel.'_recovery_required');
        }
    }

    /** Sabit metin: arac, plaka, ozel baslik veya kimlik icermez (kaynakla ayni). */
    public function text(string $locale): string
    {
        $link = rtrim((string) config('otosanayide.public_web_origin'), '/').'/hesabim';

        return I18n::t('Araç bakım veya muayene hatırlatmanı kontrol et: {link}', ['link' => $link], $locale)."\n\n"
            .I18n::t('Bu e-postayı hatırlatmada e-posta kanalını seçtiğin için aldın. Kanalı hatırlatma ayarlarından kapatabilirsin.', [], $locale);
    }

    private function emailBinding(): string
    {
        return hash('sha256', (string) json_encode(['mailer' => config('mail.default'), 'from' => config('mail.from.address'), 'contract' => 'reminder-email-v1']));
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : Js::nowMs();
    }
}
