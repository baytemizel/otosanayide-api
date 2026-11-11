<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Domain\Account\Membership;
use App\Domain\Auth\Actor;
use App\Domain\Catalogue\BusinessReader;
use App\Domain\Catalogue\OpeningHours;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * portal-03 + web-15 madde 3. Ayar: slot suresi (60/120 dk, tam saatte baslar; mevcut teklif kurali tam saat ister),
 * slot basina kapasite (1-10), onay modeli, randevu alimini duraklatma, kapali gunler.
 * Musaitlik yalniz: isletme randevu ozelligi aktif + ayar var + alim acik + yapilandirilmis calisma saati var. Aksi halde bos.
 * Talep gondermek randevunun onaylandigi anlamina GELMEZ: auto_offer yalniz otomatik teklif uretir, musteri yine kabul eder.
 */
final class AppointmentCapacity
{
    public const TZ = 'Europe/Istanbul';

    public const MAX_DAYS_AHEAD = 90;

    public static function settingsSchema(): Z
    {
        return Z::strictObject(['businessId' => RequestRules::id(), 'expectedVersion' => Z::number()->int()->nonnegative(), 'slotMinutes' => Z::literal(60, 120), 'capacity' => Z::number()->int()->min(1)->max(10), 'approval' => Z::enum(['manual', 'auto_offer']), 'accepting' => Z::boolean()]);
    }

    public static function closureSchema(): Z
    {
        return Z::strictObject(['businessId' => RequestRules::id(), 'from' => Z::string()->isoDate(), 'to' => Z::string()->isoDate(), 'reason' => Z::enum(['holiday', 'leave', 'other']), 'note' => Z::string()->trim()->max(200)->optional()])
            ->refine(static fn (array $v): bool => strcmp($v['from'], $v['to']) <= 0, 'range');
    }

    public static function settings(string $businessId, bool $lock = false): ?stdClass
    {
        $q = DB::table('business_appointment_settings')->where('business_id', $businessId);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function read(string $uid, string $businessId): array
    {
        Membership::require($uid, $businessId);
        $s = self::settings($businessId);
        $closures = DB::table('business_closures')->where('business_id', $businessId)->where('to_date', '>=', self::today())->orderBy('from_date')->limit(100)->get();

        return [
            'businessId' => $businessId, 'timezone' => self::TZ, 'configured' => $s !== null, 'version' => (int) ($s->version ?? 0),
            'slotMinutes' => $s === null ? 60 : (int) $s->slot_minutes, 'capacity' => $s === null ? 1 : (int) $s->capacity,
            'approval' => $s->approval ?? 'manual', 'accepting' => $s === null ? false : (bool) $s->accepting,
            'closures' => $closures->map(fn (stdClass $c) => ['id' => $c->id, 'from' => $c->from_date, 'to' => $c->to_date, 'reason' => $c->reason, 'note' => $c->note])->all(),
        ];
    }

    private static function owner(Actor $u, string $businessId, bool $lock = true): void
    {
        $b = BusinessReader::records([$businessId])[$businessId] ?? null;
        $m = Membership::find($u->uid, $businessId, $lock);
        if ($b === null || ! Requests::mayManage($u->uid, $businessId, $b, $m) || $m->role !== 'owner') {
            throw new HttpError(403, 'Bu işlem için işletme sahibi yetkisi gerekli.');
        }
    }

    /** @param array<string,mixed> $in */
    public static function update(Actor $u, array $in): array
    {
        Tx::run(static function () use ($u, $in): void {
            AccountFence::assertWritable($u->uid);
            self::owner($u, $in['businessId']);
            $s = self::settings($in['businessId'], true);
            if ((int) ($s->version ?? 0) !== $in['expectedVersion']) {
                throw new HttpError(409, 'Ayarlar değişti. Sayfayı yenile.');
            }
            $row = ['slot_minutes' => $in['slotMinutes'], 'capacity' => $in['capacity'], 'approval' => $in['approval'], 'accepting' => $in['accepting'], 'version' => $in['expectedVersion'] + 1, 'updated_by' => $u->uid, 'updated_at_ms' => Js::nowMs()];
            if ($s === null) {
                DB::table('business_appointment_settings')->insert(['business_id' => $in['businessId']] + $row);
            } else {
                DB::table('business_appointment_settings')->where('business_id', $in['businessId'])->update($row);
            }
        });

        return self::read($u->uid, $in['businessId']);
    }

    /** @param array<string,mixed> $in */
    public static function addClosure(Actor $u, array $in): array
    {
        if (strcmp($in['from'], self::today()) < 0 || strcmp($in['to'], self::dayOffset(366)) > 0) {
            throw new HttpError(400, 'Kapalı gün bugünden sonraki bir yıl içinde olmalı.');
        }

        return Tx::run(static function () use ($u, $in): array {
            AccountFence::assertWritable($u->uid);
            self::owner($u, $in['businessId']);
            if (DB::table('business_closures')->where('business_id', $in['businessId'])->where('to_date', '>=', self::today())->count() >= 60) {
                throw new HttpError(409, 'En fazla 60 kapalı dönem tanımlanabilir.');
            }
            $id = (string) Str::uuid();
            DB::table('business_closures')->insert(['business_id' => $in['businessId'], 'id' => $id, 'from_date' => $in['from'], 'to_date' => $in['to'], 'reason' => $in['reason'], 'note' => $in['note'] ?? '', 'created_at_ms' => Js::nowMs()]);

            return ['id' => $id, 'from' => $in['from'], 'to' => $in['to'], 'reason' => $in['reason'], 'note' => $in['note'] ?? ''];
        });
    }

    public static function removeClosure(Actor $u, string $businessId, string $id): void
    {
        Tx::run(static function () use ($u, $businessId, $id): void {
            AccountFence::assertWritable($u->uid);
            self::owner($u, $businessId);
            if (DB::table('business_closures')->where('business_id', $businessId)->where('id', $id)->delete() !== 1) {
                throw new HttpError(404, 'Kapalı gün bulunamadı.');
            }
        });
    }

    public static function closed(string $businessId, string $date): bool
    {
        return DB::table('business_closures')->where('business_id', $businessId)->where('from_date', '<=', $date)->where('to_date', '>=', $date)->exists();
    }

    /** Bir slottaki dolu yer: kapasite defteri + eski tekil slot tablosu. */
    public static function taken(string $businessId, int $startMs): int
    {
        return DB::table('appointment_bookings')->where('business_id', $businessId)->where('starts_at_ms', $startMs)->count()
            + DB::table('appointment_slots')->where('business_id', $businessId)->where('starts_at_ms', $startMs)->whereNotIn('request_id', DB::table('appointment_bookings')->where('business_id', $businessId)->select('request_id'))->count();
    }

    /** @return array{timezone:string,slotMinutes:?int,days:list<array{date:string,slots:list<string>}>} */
    public static function availability(string $businessId, string $from, int $days, ?int $nowMs = null): array
    {
        $empty = ['timezone' => self::TZ, 'slotMinutes' => null, 'days' => []];
        $b = BusinessReader::records([$businessId])[$businessId] ?? null;
        if ($b === null || BusinessReader::publicBusiness($businessId, $b) === null) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }
        $s = self::settings($businessId);
        $hours = OpeningHours::view($b['openingHoursJson'] ?? null);
        if (! RequestRules::accepting($b)['appointments'] || $s === null || ! (bool) $s->accepting || $hours === null) {
            return $empty;
        }
        $now = $nowMs ?? Js::nowMs();
        $tz = new DateTimeZone(self::TZ);
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = (new DateTimeImmutable($from, $tz))->modify("+{$i} days")->format('Y-m-d');
            if (strcmp($date, self::dayOffset(self::MAX_DAYS_AHEAD, $now)) > 0) {
                break;
            }
            $slots = [];
            if (! self::closed($businessId, $date)) {
                foreach (self::slotsOn($hours, $date, (int) $s->slot_minutes) as $hm) {
                    $start = (new DateTimeImmutable($date.' '.$hm, $tz))->getTimestamp() * 1000;
                    if ($start > $now && self::taken($businessId, $start) < (int) $s->capacity) {
                        $slots[] = $hm;
                    }
                }
            }
            $out[] = ['date' => $date, 'slots' => $slots];
        }

        return ['timezone' => self::TZ, 'slotMinutes' => (int) $s->slot_minutes, 'days' => $out];
    }

    /** Tam saatte baslayip araligin icinde biten slotlar. @return list<string> */
    public static function slotsOn(?array $hours, string $date, int $slotMinutes): array
    {
        $slots = [];
        foreach (OpeningHours::intervalsOn($hours, $date) as $i) {
            [$oh, $om] = array_map('intval', explode(':', $i['open']));
            [$ch, $cm] = array_map('intval', explode(':', $i['close']));
            $start = $om === 0 ? $oh * 60 : ($oh + 1) * 60;
            for ($t = $start; $t + $slotMinutes <= $ch * 60 + $cm; $t += $slotMinutes) {
                $slots[] = sprintf('%02d:00', intdiv($t, 60));
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * Talep olusturma denetimi (randevu): duraklatma, kapali gun ve secilen slotun kapasitesi. Ayar yoksa eski davranis.
     *
     * @return int|null secilen slotun baslangici (ms)
     */
    public static function assertRequestable(string $businessId, string $preferredDate, ?string $slot): ?int
    {
        $s = self::settings($businessId, true);
        if ($s === null) {
            if ($slot !== null) {
                throw new HttpError(409, 'Bu işletme saat seçimiyle randevu almıyor.');
            }

            return null;
        }
        if (! (bool) $s->accepting) {
            throw new HttpError(409, 'İşletme şu anda randevu talebi almıyor.');
        }
        if (self::closed($businessId, $preferredDate)) {
            throw new HttpError(409, 'İşletme bu tarihte kapalı.');
        }
        if ($slot === null) {
            return null;
        }
        $b = BusinessReader::records([$businessId])[$businessId] ?? null;
        if (! in_array($slot, self::slotsOn(OpeningHours::view($b['openingHoursJson'] ?? null), $preferredDate, (int) $s->slot_minutes), true)) {
            throw new HttpError(409, 'Seçilen saat uygun değil.');
        }
        $start = (new DateTimeImmutable($preferredDate.' '.$slot, new DateTimeZone(self::TZ)))->getTimestamp() * 1000;
        if ($start <= Js::nowMs() || self::taken($businessId, $start) >= (int) $s->capacity) {
            throw new HttpError(409, 'Seçilen saat doldu. Başka bir saat seç.');
        }

        return $start;
    }

    /** Kabulde kapasite: ayar satiri kilitlenir, sayim + ekleme ayni transaction'da. Ayar yoksa false (eski tekil slot kurali). */
    public static function book(string $businessId, string $requestId, int $startMs): bool
    {
        $s = self::settings($businessId, true);
        if ($s === null) {
            return false;
        }
        if (DB::table('appointment_bookings')->where('business_id', $businessId)->where('request_id', $requestId)->exists()) {
            return true;
        }
        if (self::taken($businessId, $startMs) >= (int) $s->capacity) {
            throw new HttpError(409, 'Bu saat başka bir randevuya ayrıldı. Yeni saat iste.');
        }
        DB::table('appointment_bookings')->insert(['business_id' => $businessId, 'request_id' => $requestId, 'starts_at_ms' => $startMs]);

        return true;
    }

    public static function release(string $businessId, string $requestId): void
    {
        DB::table('appointment_bookings')->where('business_id', $businessId)->where('request_id', $requestId)->delete();
    }

    public static function today(?int $nowMs = null): string
    {
        return self::dayOffset(0, $nowMs);
    }

    public static function dayOffset(int $days, ?int $nowMs = null): string
    {
        return (new DateTimeImmutable('@'.intdiv($nowMs ?? Js::nowMs(), 1000)))->setTimezone(new DateTimeZone(self::TZ))->modify("+{$days} days")->format('Y-m-d');
    }
}
