<?php

namespace App\Console\Commands;

use App\Domain\Brand\BrandMediaCleanup;
use App\Domain\Payments\F7;
use Illuminate\Console\Command;

/** F7 (API-B): marka gorseli saklama temizligi (core/brand-media-cleanup.ts). --apply olmadan yalniz sayar. */
final class BrandMediaCleanupCommand extends Command
{
    protected $signature = 'brand:media-cleanup {--apply : Suresi dolan yuklemeleri gozlemler/siler} {--after= : Sayfa imleci}';

    protected $description = 'Marka gorseli yukleme kayitlarini saklama kuralina gore degerlendirir; varsayilan kuru calisma.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && config('ads.workers.brand_media_cleanup') !== true) {
            $this->error('BRAND_MEDIA_CLEANUP_ENABLED kapali: --apply reddedildi.');

            return self::FAILURE;
        }
        $after = $this->option('after');
        $this->line((string) json_encode(BrandMediaCleanup::page(is_string($after) && F7::isUuid($after) ? $after : null, $apply, F7::nowMs())));

        return self::SUCCESS;
    }
}
