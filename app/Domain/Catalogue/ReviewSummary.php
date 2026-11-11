<?php

namespace App\Domain\Catalogue;

use Illuminate\Support\Facades\DB;

/**
 * web-05/web-08: yayimlanmis yorum ozeti (public_reviews) arama projeksiyonunda tutulur.
 * Yorum yayimlama/geri cekme ile AYNI transaction'da cagrilir; projeksiyon satiri yoksa (arama disi) bir sey yazilmaz.
 * Siralama puani Bayes agirlikli ortalamadir: (C*m + toplam) / (C + n). C ve m ONERIDIR, kullanici kabulu bekler.
 */
final class ReviewSummary
{
    /** Onsel agirlik (sanal yorum sayisi). */
    public const PRIOR_WEIGHT = 5;

    /** Onsel ortalama (1-5). */
    public const PRIOR_MEAN = 3.5;

    /** @return array{count:int,sum:int} */
    public static function compute(string $businessId): array
    {
        $row = DB::table('public_reviews')->where('business_id', $businessId)->selectRaw('COUNT(*) as n, COALESCE(SUM(rating), 0) as s')->first();

        return ['count' => (int) ($row->n ?? 0), 'sum' => (int) ($row->s ?? 0)];
    }

    public static function score(int $count, int $sum): float
    {
        return round((self::PRIOR_WEIGHT * self::PRIOR_MEAN + $sum) / (self::PRIOR_WEIGHT + $count), 6);
    }

    /** @return array{count:int,average:?float} istemciye giden bicim; average bir ondalik. */
    public static function view(int $count, int $sum): array
    {
        return ['count' => $count, 'average' => $count === 0 ? null : round($sum / $count, 1)];
    }

    public static function refresh(string $businessId): void
    {
        $s = self::compute($businessId);
        DB::table('business_search')->where('business_id', $businessId)->update(['review_count' => $s['count'], 'review_sum' => $s['sum'], 'review_score' => self::score($s['count'], $s['sum'])]);
    }
}
