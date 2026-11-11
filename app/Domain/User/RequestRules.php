<?php

namespace App\Domain\User;

use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Z;

/** packages/core/request-operations.ts, owner-requests-client.ts, request-notifications.ts */
final class RequestRules
{
    public const ID = '/^[a-zA-Z0-9_-]{1,120}$/';

    public static function id(): Z
    {
        return Z::string()->regex(self::ID);
    }

    public static function create(): Z
    {
        return Z::strictObject([
            'businessId' => self::id(),
            'kind' => Z::enum(['quotes', 'appointments']),
            'service' => Z::string()->trim()->min(2)->max(120),
            'vehicle' => Z::string()->trim()->min(2)->max(120),
            'message' => Z::string()->trim()->min(10)->max(2000),
            'preferredDate' => Z::string()->isoDate()->optional(),
            'requestKey' => Z::string()->uuid(),
            // portal-03: musaitlikten secilen saat (yalniz randevu). Onay degildir.
            'preferredSlot' => Z::string()->regex('/^(?:[01]\d|2[0-3]):00$/')->optional(),
        ])->refine(static fn (array $v): bool => ! isset($v['preferredSlot']) || ($v['kind'] === 'appointments' && isset($v['preferredDate'])), 'slot');
    }

    public static function action(): Z
    {
        $simple = Z::strictObject(['id' => self::id(), 'version' => Z::number()->int()->positive(), 'action' => Z::enum(['accept', 'cancel', 'decline', 'complete']), 'text' => Z::string()->trim()->min(10)->max(1000)->optional()]);

        return Z::discriminatedUnion('action', [
            'offer' => Z::strictObject(['id' => self::id(), 'version' => Z::number()->int()->positive(), 'action' => Z::literal('offer'), 'text' => Z::string()->trim()->min(10)->max(2000), 'price' => Z::number()->positive()->max(10000000)->optional(), 'startsAt' => Z::string()->isoDatetime(true)->optional()] + self::quoteFields()),
            'accept' => $simple, 'cancel' => $simple, 'decline' => $simple, 'complete' => $simple,
        ]);
    }

    /** portal-05: teklif kalemleri (kurus), para birimi, gecerlilik, haric tutulanlar, inceleme sonrasi degisebilir. */
    private static function quoteFields(): array
    {
        return [
            'lines' => Z::array(Z::strictObject(['kind' => Z::enum(['labour', 'parts', 'tax', 'other']), 'label' => Z::string()->trim()->min(1)->max(120), 'amountMinor' => Z::number()->int()->min(1)->max(1000000000)]))->min(1)->max(20)->optional(),
            'currency' => Z::literal('TRY')->optional(),
            'validDays' => Z::literal(1, 3, 7)->optional(),
            'excluded' => Z::string()->trim()->max(500)->optional(),
            'mayChangeAfterInspection' => Z::boolean()->optional(),
        ];
    }

    public static function ownerWrite(): Z
    {
        return Z::strictObject(['businessId' => self::id(), 'value' => self::action()])->refine(static fn (array $v): bool => $v['value']['action'] !== 'accept', 'owner_cannot_accept');
    }

    /** @param array<string,mixed>|null $b */
    public static function accepting(?array $b): array
    {
        $ready = $b !== null && ($b['sample'] ?? false) !== true && ($b['published'] ?? false) === true && ($b['ownership'] ?? null) === 'verified' && ($b['ownerReady'] ?? false) === true;

        return ['quotes' => $ready && ($b['features']['quotes'] ?? false) === true, 'appointments' => $ready && ($b['features']['appointments'] ?? false) === true];
    }

    /**
     * requestTransition(): hata metni 409 yaniti olarak doner.
     *
     * @param  array<string,mixed>  $r
     * @param  array<string,mixed>  $a
     * @return array<string,mixed>
     */
    public static function transition(array $r, array $a, string $role, int $now): array
    {
        $fail = static fn (string $message): never => throw new HttpError(409, $message);
        if (($r['version'] ?? 1) !== $a['version']) {
            $fail('Talep değişti. Listeyi yenile.');
        }
        if ($a['action'] === 'offer' && $role === 'business' && in_array($r['status'], ['requested', 'offered'], true)) {
            $quoteExtras = array_intersect_key($a, array_flip(['lines', 'currency', 'validDays', 'excluded', 'mayChangeAfterInspection']));
            if ($quoteExtras !== [] && $r['kind'] !== 'quotes') {
                $fail('Teklif kalemleri yalnız fiyat teklifinde kullanılır.');
            }
            if (isset($a['lines'])) {
                $sum = array_sum(array_column($a['lines'], 'amountMinor'));
                if (array_key_exists('price', $a) && (int) round($a['price'] * 100) !== $sum) {
                    $fail('Teklif toplamı kalemlerle uyuşmuyor.');
                }
                if ($sum > 1000000000) {
                    $fail('Teklif toplamı çok yüksek.');
                }
                $a['price'] = $sum / 100;
            }
            $hasPrice = array_key_exists('price', $a);
            $starts = $a['startsAt'] ?? null;
            if ($r['kind'] === 'quotes' && (! $hasPrice || $starts)) {
                $fail('Teklif için fiyat gerekli; randevu saati eklenemez.');
            }
            if ($r['kind'] === 'appointments' && (! $starts || $hasPrice)) {
                $fail('Randevu için saat gerekli; fiyat ayrı teklifte belirtilir.');
            }
            $start = $starts !== null ? Js::ms($starts) : null;
            if ($start !== null && ($start <= $now || $start > $now + 90 * 86400000 || $start % 3600000 !== 0)) {
                $fail('Randevu gelecek 90 gün içinde tam saatte olmalı.');
            }
            $offer = ['text' => $a['text']];
            if ($hasPrice) {
                $offer['price'] = $a['price'];
            }
            if ($start !== null) {
                $offer['startsAt'] = Js::iso(new \DateTimeImmutable('@'.intdiv($start, 1000)));
            }
            $offer['expiresAtMs'] = min($now + ($a['validDays'] ?? 7) * 86400000, $start ?? PHP_INT_MAX);
            if (isset($a['lines'])) {
                $offer['lines'] = array_map(static fn (array $l): array => ['kind' => $l['kind'], 'label' => $l['label'], 'amountMinor' => $l['amountMinor']], $a['lines']);
            }
            foreach (['currency', 'excluded', 'mayChangeAfterInspection'] as $k) {
                if (array_key_exists($k, $a)) {
                    $offer[$k] = $a[$k];
                }
            }
            if ($quoteExtras !== [] && ! isset($offer['currency'])) {
                $offer['currency'] = 'TRY';
            }

            return ['status' => 'offered', 'version' => $a['version'] + 1, 'offer' => $offer];
        }
        if ($a['action'] === 'accept' && $role === 'customer' && $r['status'] === 'offered') {
            if (empty($r['offer']) || $r['offer']['expiresAtMs'] <= $now) {
                $fail('Önerinin süresi doldu; işletmeden yeni yanıt bekle.');
            }

            return ['status' => 'confirmed', 'version' => $a['version'] + 1];
        }
        if (($a['action'] === 'cancel' && in_array($r['status'], ['requested', 'offered', 'confirmed'], true)) || ($a['action'] === 'decline' && $role === 'business' && in_array($r['status'], ['requested', 'offered'], true))) {
            if (empty($a['text'])) {
                $fail('İptal veya ret gerekçesi gerekli.');
            }

            return ['status' => $a['action'] === 'cancel' ? 'cancelled' : 'declined', 'version' => $a['version'] + 1, 'resolution' => $a['text']];
        }
        if ($a['action'] === 'complete' && $role === 'business' && $r['status'] === 'confirmed') {
            if ($r['kind'] === 'appointments' && isset($r['offer']['startsAt']) && Js::ms($r['offer']['startsAt']) > $now) {
                $fail('Randevu saati gelmeden tamamlanamaz.');
            }

            return ['status' => 'completed', 'version' => $a['version'] + 1];
        }
        $fail('Bu talep için işlem kullanılamıyor.');
    }

    /** @param array<string,mixed> $j */
    public static function claim(array $j, int $now, string $leaseId): ?array
    {
        if (! in_array($j['state'], ['pending', 'retry', 'processing'], true) || ($j['nextAttemptAtMs'] ?? PHP_INT_MAX) > $now) {
            return null;
        }
        if ($j['attempts'] >= 5) {
            return ['state' => 'failed'];
        }

        return ['state' => 'processing', 'attempts' => $j['attempts'] + 1, 'leaseId' => $leaseId, 'leaseUntilMs' => $now + 60000, 'nextAttemptAtMs' => $now + 60000];
    }

    /** @param array<string,mixed> $j */
    public static function retry(array $j, int $now): array
    {
        if ($j['attempts'] >= 5) {
            return ['state' => 'failed'];
        }

        return ['state' => 'retry', 'nextAttemptAtMs' => $now + (int) min(60000 * (2 ** max(0, $j['attempts'] - 1)), 3600000)];
    }
}
