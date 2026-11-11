<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Domain\Account\Membership;
use App\Domain\Catalogue\BusinessReader;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class Requests
{
    public const AUTO_OFFER_TEXT = 'Seçtiğin saat için işletmenin otomatik randevu önerisi. Kabul edersen randevu kesinleşir.';

    /** v2 (portal-05/portal-03): teklif kalemleri ve secilen saat yalniz ?v=2 ile doner; v1 gorunumu degismez. */
    public static function view(stdClass $r, bool $v2 = false): array
    {
        $out = ['id' => $r->id, 'businessId' => $r->business_id, 'businessName' => $r->business_name, 'kind' => $r->kind, 'service' => $r->service, 'vehicle' => $r->vehicle, 'message' => $r->message];
        if ($r->preferred_date !== null) {
            $out['preferredDate'] = $r->preferred_date;
        }
        $out['status'] = $r->status;
        if ($r->offer_text !== null) {
            $out['offer'] = self::offer($r);
            if ($v2) {
                $out['offer'] += self::offerExtras($r);
            }
        }
        if ($v2 && ($r->preferred_starts_at_ms ?? null) !== null) {
            $out['preferredStartsAt'] = Js::iso(new \DateTimeImmutable('@'.intdiv((int) $r->preferred_starts_at_ms, 1000)));
        }
        if ($r->resolution !== null) {
            $out['resolution'] = $r->resolution;
        }

        return $out + ['version' => (int) $r->version];
    }

    private static function offer(stdClass $r): array
    {
        $offer = ['text' => $r->offer_text];
        if ($r->offer_price !== null) {
            $p = (float) $r->offer_price;
            $offer['price'] = $p == floor($p) ? (int) $p : $p;
        }
        if ($r->offer_starts_at !== null) {
            $offer['startsAt'] = $r->offer_starts_at;
        }

        return $offer + ['expiresAtMs' => (int) $r->offer_expires_at_ms];
    }

    /** @return array<string,mixed> */
    private static function offerExtras(stdClass $r): array
    {
        $out = [];
        if (($r->offer_lines ?? null) !== null) {
            $lines = json_decode((string) $r->offer_lines, true);
            $out['lines'] = is_array($lines) ? $lines : [];
            $out['totalMinor'] = array_sum(array_column($out['lines'], 'amountMinor'));
        }
        if (($r->offer_currency ?? null) !== null) {
            $out['currency'] = $r->offer_currency;
        }
        if (($r->offer_excluded ?? null) !== null) {
            $out['excluded'] = $r->offer_excluded;
        }
        if (($r->offer_may_change ?? null) !== null) {
            $out['mayChangeAfterInspection'] = (bool) $r->offer_may_change;
        }

        return $out;
    }

    /** mayManageRequests() */
    public static function mayManage(string $uid, string $bid, ?array $b, ?stdClass $m): bool
    {
        return $b !== null && $b['sample'] !== true && $b['ownership'] === 'verified' && $m !== null && $m->business_id === $bid && Membership::isMember($uid, $m);
    }

    private static function business(string $bid, bool $lock = false): ?array
    {
        if ($lock) {
            DB::table('businesses')->where('id', $bid)->sharedLock()->first();
        }

        return BusinessReader::records([$bid])[$bid] ?? null;
    }

    public static function page(string $uid, ?string $bid, ?string $after, bool $v2 = false): array
    {
        $accepting = ['quotes' => false, 'appointments' => false];
        if ($bid !== null) {
            $b = self::business($bid);
            $m = Membership::find($uid, $bid);
            if (! self::mayManage($uid, $bid, $b, $m)) {
                throw new HttpError(403, 'Bu işletme için yetkiniz yok.');
            }
            $accepting = RequestRules::accepting($b);
        }
        $q = DB::table('requests')->where($bid !== null ? 'business_id' : 'user_id', $bid ?? $uid);
        if ($after !== null && $after !== '') {
            $q->where('id', '>', $after);
        }
        $rows = $q->orderBy('id')->limit(21)->get();
        $page = $rows->take(20)->values();

        return ($bid !== null ? ['businessId' => $bid, 'accepting' => $accepting] : []) + ['items' => $page->map(fn (stdClass $r) => self::view($r, $v2))->all(), 'next' => $rows->count() > 20 ? $page[19]->id : null];
    }

    /** @param array<string,mixed> $v */
    public static function create(string $uid, array $v): array
    {
        $key = $v['requestKey'];
        $slot = $v['preferredSlot'] ?? null;
        unset($v['requestKey'], $v['preferredSlot']);
        $id = hash('sha256', Js::stringify([$uid, $key]));
        $run = fn () => Tx::run(function () use ($uid, $id, $v, $slot): string {
            AccountFence::assertWritable($uid);
            $existing = DB::table('requests')->where('id', $id)->lockForUpdate()->first();
            $b = self::business($v['businessId'], true);
            $m = Membership::find($uid, $v['businessId'], true);
            if ($existing !== null) {
                $same = $existing->user_id === $uid && $existing->business_id === $v['businessId'] && $existing->kind === $v['kind'] && $existing->service === $v['service'] && $existing->vehicle === $v['vehicle'] && $existing->message === $v['message'] && $existing->preferred_date === ($v['preferredDate'] ?? null);
                if (! $same) {
                    throw new HttpError(409, 'Talep anahtarı farklı içerikte kullanılmış.');
                }

                return $existing->status;
            }
            if ($b === null || ! RequestRules::accepting($b)[$v['kind']]) {
                throw new HttpError(409, 'Bu işletme şu anda bu tür talepleri kabul etmiyor.');
            }
            $ownerUid = DB::table('businesses')->where('id', $v['businessId'])->value('owner_uid');
            if ($ownerUid === $uid || Membership::isMember($uid, $m)) {
                throw new HttpError(403, 'Kendi işletmene talep gönderemezsin.');
            }
            if ($v['kind'] === 'appointments' && (empty($v['preferredDate']) || strcmp($v['preferredDate'], gmdate('Y-m-d', time() + 10800)) < 0)) {
                throw new HttpError(400, 'Randevu tarihi geçmişte olamaz.');
            }
            // portal-03: duraklatma, kapali gun ve secilen saatin kapasitesi.
            $start = $v['kind'] === 'appointments' ? AppointmentCapacity::assertRequestable($v['businessId'], $v['preferredDate'], $slot) : null;
            $row = ['id' => $id, 'business_id' => $v['businessId'], 'business_name' => $b['name'], 'user_id' => $uid, 'kind' => $v['kind'], 'service' => $v['service'], 'vehicle' => $v['vehicle'], 'message' => $v['message'], 'preferred_date' => $v['preferredDate'] ?? null, 'status' => 'requested', 'version' => 1, 'preferred_starts_at_ms' => $start];
            $now = Js::nowMs();
            if ($start !== null && AppointmentCapacity::settings($v['businessId'])?->approval === 'auto_offer') {
                // Otomatik teklif: randevu musteri kabul edene kadar kesin degildir; kapasite kabulde yeniden denetlenir.
                $row = ['status' => 'offered', 'version' => 1, 'offer_text' => self::AUTO_OFFER_TEXT, 'offer_starts_at' => Js::iso(new \DateTimeImmutable('@'.intdiv($start, 1000))), 'offer_expires_at_ms' => min($now + 7 * 86400000, $start)] + $row;
            }
            DB::table('requests')->insert($row);
            self::job($id, $v['businessId'], $uid, 1, $row['status'], $now);

            return $row['status'];
        });
        try {
            $status = $run();
        } catch (UniqueConstraintViolationException) {
            $status = $run(); // Es zamanli ayni anahtar: ikinci geciste mevcut kayit okunur.
        }

        return ['id' => $id, 'status' => $status];
    }

    private static function job(string $requestId, string $businessId, string $customerId, int $version, string $status, int $now): void
    {
        DB::table('notification_jobs')->insert(['id' => $requestId.'_'.$version, 'request_id' => $requestId, 'business_id' => $businessId, 'customer_id' => $customerId, 'request_version' => $version, 'request_status' => $status, 'created_at_ms' => $now, 'state' => 'pending', 'attempts' => 0, 'next_attempt_at_ms' => $now]);
    }

    /** @param array<string,mixed> $input */
    public static function act(string $uid, array $input, ?string $ownedBusinessId): void
    {
        Tx::run(function () use ($uid, $input, $ownedBusinessId): void {
            $row = DB::table('requests')->where('id', $input['id'])->lockForUpdate()->first();
            if ($row === null) {
                throw new HttpError(404, 'Talep bulunamadı.');
            }
            $bid = $row->business_id;
            if ($ownedBusinessId !== null && $ownedBusinessId !== $bid) {
                throw new HttpError(403, 'Bu talep için yetkin yok.');
            }
            $b = self::business($bid, true);
            $m = Membership::find($uid, $bid, true);
            $businessRole = self::mayManage($uid, $bid, $b, $m);
            $role = $ownedBusinessId !== null ? ($businessRole && $row->user_id !== $uid ? 'business' : null) : ($row->user_id === $uid ? 'customer' : ($businessRole ? 'business' : null));
            if ($role === null) {
                throw new HttpError(403, 'Bu talep için yetkin yok.');
            }
            AccountFence::assertWritable($uid);
            if ($row->user_id !== $uid) {
                AccountFence::assertWritable($row->user_id);
            }
            if (in_array($input['action'], ['offer', 'accept'], true) && ! RequestRules::accepting($b)[$row->kind]) {
                throw new HttpError(409, 'Bu işletme şu anda bu tür talepleri kabul etmiyor.');
            }
            $r = ['status' => $row->status, 'version' => (int) $row->version, 'kind' => $row->kind];
            if ($row->offer_text !== null) {
                $r['offer'] = self::offer($row);
            }
            $now = Js::nowMs();
            $next = RequestRules::transition($r, $input, $role, $now);
            $startsAt = $r['offer']['startsAt'] ?? null;
            if ($row->kind === 'appointments' && $startsAt && in_array($input['action'], ['accept', 'cancel'], true)) {
                $ms = Js::ms($startsAt);
                $occupied = DB::table('appointment_slots')->where('business_id', $bid)->where('starts_at_ms', $ms)->lockForUpdate()->first();
                if ($input['action'] === 'accept' && ! AppointmentCapacity::book($bid, $input['id'], $ms)) {
                    // Kapasite ayari yok: eski tekil slot kurali.
                    if ($occupied !== null && $occupied->request_id !== $input['id']) {
                        throw new HttpError(409, 'Bu saat başka bir randevuya ayrıldı. Yeni saat iste.');
                    }
                    DB::table('appointment_slots')->updateOrInsert(['business_id' => $bid, 'starts_at_ms' => $ms], ['request_id' => $input['id'], 'starts_at' => $startsAt]);
                }
                if ($input['action'] === 'cancel' && $row->status === 'confirmed') {
                    AppointmentCapacity::release($bid, $input['id']);
                    if ($occupied?->request_id === $input['id']) {
                        DB::table('appointment_slots')->where('business_id', $bid)->where('starts_at_ms', $ms)->delete();
                    }
                }
            }
            $update = ['status' => $next['status'], 'version' => $next['version'], 'updated_at' => gmdate('Y-m-d H:i:s').'.000'];
            if (isset($next['offer'])) {
                $update += ['offer_text' => $next['offer']['text'], 'offer_price' => $next['offer']['price'] ?? null, 'offer_starts_at' => $next['offer']['startsAt'] ?? null, 'offer_expires_at_ms' => $next['offer']['expiresAtMs'],
                    'offer_lines' => isset($next['offer']['lines']) ? json_encode($next['offer']['lines'], JSON_UNESCAPED_UNICODE) : null, 'offer_currency' => $next['offer']['currency'] ?? null,
                    'offer_excluded' => $next['offer']['excluded'] ?? null, 'offer_may_change' => $next['offer']['mayChangeAfterInspection'] ?? null];
            }
            if (isset($next['resolution'])) {
                $update['resolution'] = $next['resolution'];
            }
            DB::table('requests')->where('id', $input['id'])->update($update);
            self::job($input['id'], $bid, $row->user_id, $next['version'], $next['status'], $now);
            DB::table('request_history')->insert(['request_id' => $input['id'], 'id' => (string) Str::uuid(), 'version' => $input['version'], 'action' => $input['action'], 'text' => $input['text'] ?? null, 'price' => $input['price'] ?? null, 'starts_at' => $input['startsAt'] ?? null, 'actor_id' => $uid, 'role' => $role, 'from_status' => $row->status, 'to_status' => $next['status']]);
        });
    }
}
