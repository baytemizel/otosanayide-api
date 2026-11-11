<?php

namespace App\Domain\Content;

use App\Domain\Account\Membership;
use App\Domain\Catalogue\ReviewSummary;
use App\Support\Js;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * portal-02 (kismi): yalniz sunucuda ZATEN bulunan verilerden salt okunur ozet. Profil goruntuleme, telefon veya yol
 * tarifi dokunusu gibi yeni izleme gerektiren olcumler YOK (gizlilik/cerez ve saklama karari kullanicida).
 */
final class BusinessInsights
{
    public static function read(string $uid, string $businessId, int $days, ?int $nowMs = null): array
    {
        Membership::require($uid, $businessId);
        $now = $nowMs ?? Js::nowMs();
        $tz = new DateTimeZone('Europe/Istanbul');
        $today = (new DateTimeImmutable('@'.intdiv($now, 1000)))->setTimezone($tz)->setTime(0, 0);
        $start = $today->modify('-'.($days - 1).' days');
        $rows = [];
        for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
            $rows[$d->format('Y-m-d')] = 0;
        }
        $since = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $requests = DB::table('requests')->where('business_id', $businessId)->where('created_at', '>=', $since)->orderBy('created_at')->limit(5000)->get(['created_at', 'status', 'kind']);
        $byStatus = [];
        $byKind = ['quotes' => 0, 'appointments' => 0];
        foreach ($requests as $r) {
            $date = (new DateTimeImmutable((string) $r->created_at, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            if (isset($rows[$date])) {
                $rows[$date]++;
            }
            $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + 1;
            $byKind[$r->kind] = ($byKind[$r->kind] ?? 0) + 1;
        }
        ksort($byStatus);
        $summary = ReviewSummary::compute($businessId);
        $awaiting = DB::table('public_reviews as p')->where('p.business_id', $businessId)->whereNull('p.reply_text')
            ->whereNotExists(fn ($q) => $q->from('review_replies as r')->whereColumn('r.review_id', 'p.review_id')->where('r.status', 'pending'))->count();

        return [
            'businessId' => $businessId, 'days' => $days, 'timezone' => 'Europe/Istanbul',
            'favorites' => ['total' => DB::table('user_favorites')->where('business_id', $businessId)->count()],
            'requests' => ['total' => $requests->count(), 'byStatus' => (object) $byStatus, 'byKind' => $byKind, 'rows' => array_map(static fn (string $date, int $n): array => ['date' => $date, 'created' => $n], array_keys($rows), array_values($rows))],
            'reviews' => ReviewSummary::view($summary['count'], $summary['sum']) + ['awaitingReply' => $awaiting],
            'notTracked' => ['profileViews', 'phoneTaps', 'directionTaps'],
            'generatedAtMs' => $now,
        ];
    }
}
