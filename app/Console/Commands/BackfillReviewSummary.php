<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\ReviewSummary;
use App\Support\Tx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** web-08: mevcut projeksiyon satirlarinin yorum ozetini public_reviews'tan hesaplar. Varsayilan kuru calisir. */
final class BackfillReviewSummary extends Command
{
    protected $signature = 'reviews:backfill-summary {--apply : Farkli satirlari yazar} {--after= : Bu isletme kimliginden sonra baslar} {--limit=500 : Calisma basina en cok satir}';

    protected $description = 'Arama projeksiyonundaki yorum sayisi/ortalamasini yeniden hesaplar; --apply olmadan yalniz sayar.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $q = DB::table('business_search')->orderBy('business_id')->limit($limit);
        if (is_string($this->option('after')) && $this->option('after') !== '') {
            $q->where('business_id', '>', $this->option('after'));
        }
        $rows = $q->get(['business_id', 'review_count', 'review_sum']);
        $counts = ['scanned' => $rows->count(), 'different' => 0, 'written' => 0];
        foreach ($rows as $row) {
            $s = ReviewSummary::compute($row->business_id);
            if ($s['count'] === (int) $row->review_count && $s['sum'] === (int) $row->review_sum) {
                continue;
            }
            $counts['different']++;
            if ($this->option('apply')) {
                Tx::run(static function () use ($row): void {
                    DB::table('business_search')->where('business_id', $row->business_id)->lockForUpdate()->first();
                    ReviewSummary::refresh($row->business_id);
                });
                $counts['written']++;
            }
        }
        $last = $rows->last();
        $this->line((string) json_encode(['apply' => (bool) $this->option('apply')] + $counts + ['next' => $rows->count() === $limit && $last !== null ? $last->business_id : null]));

        return self::SUCCESS;
    }
}
