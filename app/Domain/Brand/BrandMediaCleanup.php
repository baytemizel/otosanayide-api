<?php

namespace App\Domain\Brand;

use App\Domain\Content\Barrier;
use App\Domain\Content\MediaStore;
use App\Domain\Payments\F7;
use Illuminate\Support\Facades\DB;

/**
 * core/brand-media-cleanup.ts: yalniz bilinen yukleme kayitlari temizlige girer; guncel gorsel ve saklama tutmasi kazanir.
 * Yarim kalan yukleme 24 saat, ayrilmis (detached) gorsel gozlemden itibaren 30 gun saklanir.
 */
final class BrandMediaCleanup
{
    public const UNFINISHED_MS = F7::DAY;

    public const DETACHED_MS = 30 * F7::DAY;

    /**
     * brandCleanupDecision
     *
     * @return 'keep'|'observe'|'purge'
     */
    public static function decision(string $id, array $row, ?array $campaign, int $now): string
    {
        if (! F7::isUuid($id) || ! F7::isUuid($row['campaignId'] ?? null) || (array_key_exists('retentionHold', $row) && $row['retentionHold'] !== false)) {
            return 'keep';
        }
        $created = $row['createdAtMs'] ?? null;
        if (! F7::safeInt($now) || ! F7::safeInt($created) || $created < 0 || $created > $now) {
            return 'keep';
        }
        if ($campaign !== null && array_key_exists('asset', $campaign)) {
            $asset = $campaign['asset'];
            if (! is_array($asset) || ! F7::isUuid($asset['id'] ?? null) || $asset['id'] === $id) {
                return 'keep';
            }
        }
        $status = $row['status'] ?? null;
        if (! in_array($status, ['uploading', 'failed', 'attached', 'purging'], true)) {
            return 'keep';
        }
        if ($status === 'purging') {
            return 'purge';
        }
        if ($status === 'uploading' || $status === 'failed') {
            return $now - $created >= self::UNFINISHED_MS ? 'purge' : 'keep';
        }
        if (! array_key_exists('orphanObservedAtMs', $row)) {
            return 'observe';
        }
        $seen = $row['orphanObservedAtMs'];
        if (! F7::safeInt($seen) || $seen < $created || $seen > $now) {
            return 'keep';
        }

        return $now - $seen >= self::DETACHED_MS ? 'purge' : 'keep';
    }

    /** @return array<string,mixed> */
    public static function doc(\stdClass $r): array
    {
        return array_filter(['campaignId' => $r->campaign_id, 'status' => $r->status, 'createdAtMs' => (int) $r->created_at_ms, 'orphanObservedAtMs' => $r->orphan_observed_at_ms === null ? null : (int) $r->orphan_observed_at_ms, 'retentionHold' => $r->retention_hold === null ? null : (bool) $r->retention_hold], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Bir sayfa: kuru calismada yalniz sayar. --apply ile claim (atomik tekrar okuma + purging) -> sil -> purged.
     *
     * @return array{keep:int,observe:int,purge:int,purged:int,next:?string}
     */
    public static function page(?string $after, bool $apply, int $now, int $limit = 100): array
    {
        $q = DB::table('brand_media_uploads')->whereIn('status', ['uploading', 'failed', 'attached', 'purging'])->orderBy('id')->limit($limit + 1);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->get()->all();
        $counts = ['keep' => 0, 'observe' => 0, 'purge' => 0, 'purged' => 0];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $campaign = BrandStore::campaign($row->campaign_id, false);
            $d = self::decision($row->id, self::doc($row), $campaign, $now);
            $counts[$d]++;
            if (! $apply || $d === 'keep') {
                continue;
            }
            if ($d === 'observe') {
                Barrier::media(null, ['kind' => 'brand', 'id' => $row->id], static function () use ($row, $now): void {
                    $current = F7::lock('brand_media_uploads', 'id', $row->id);
                    $campaign = BrandStore::campaign($row->campaign_id);
                    if ($current !== null && self::decision($row->id, self::doc($current), $campaign, $now) === 'observe') {
                        DB::table('brand_media_uploads')->where('id', $row->id)->update(['orphan_observed_at_ms' => $now]);
                    }
                });

                continue;
            }
            $claimed = Barrier::media(null, ['kind' => 'brand', 'id' => $row->id], static function () use ($row, $now): bool {
                $current = F7::lock('brand_media_uploads', 'id', $row->id);
                $campaign = BrandStore::campaign($row->campaign_id);
                if ($current === null || self::decision($row->id, self::doc($current), $campaign, $now) !== 'purge') {
                    return false;
                }
                DB::table('brand_media_uploads')->where('id', $row->id)->update(['status' => 'purging']);

                return true;
            });
            if (! $claimed) {
                continue;
            }
            MediaStore::delete(BrandMedia::disk(), null, ['kind' => 'brand', 'id' => $row->id], BrandMedia::path($row->id));
            Barrier::media(null, ['kind' => 'brand', 'id' => $row->id], static function () use ($row): void {
                DB::table('brand_media_uploads')->where('id', $row->id)->where('status', 'purging')->update(['status' => 'purged']);
            });
            $counts['purged']++;
        }

        return $counts + ['next' => count($rows) > $limit ? $rows[$limit - 1]->id : null];
    }
}
