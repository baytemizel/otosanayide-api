<?php

namespace App\Console\Commands;

use App\Domain\Account\AccountFence;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** planGaragePurge(): suresi dolan cop kutusu yedegi; ortak garaj ve askidaki hesaplar atlanir. */
final class PurgeGarageRecycle extends Command
{
    protected $signature = 'garage:purge-recycle {--apply : Yedegi kalici siler}';

    protected $description = 'Suresi dolan garaj cop kutusu kopyalarini siler; --apply olmadan yalniz sayar.';

    private const PAGE = 100;

    public function handle(): int
    {
        $now = Js::iso();
        $uids = DB::table('garage_recycle')->where('state', 'trashed')->where('purge_after_iso', '<=', $now)->orderBy('purge_after_iso')->limit(self::PAGE)->pluck('user_id');
        $counts = ['eligible' => 0, 'purged' => 0, 'paused' => 0, 'stale' => 0, 'shared' => 0];
        foreach ($uids as $uid) {
            $outcome = Tx::run(function () use ($uid, $now): string {
                if (AccountFence::paused($uid)) {
                    return 'paused';
                }
                $t = DB::table('garage_recycle')->where('user_id', $uid)->lockForUpdate()->first();
                if ($t === null || $t->state !== 'trashed' || strcmp($now, $t->purge_after_iso) < 0) {
                    return 'stale';
                }
                if (DB::table('garage_state')->where('user_id', $uid)->lockForUpdate()->exists()) {
                    return 'shared';
                }
                if (! $this->option('apply')) {
                    return 'eligible';
                }
                DB::table('garage_recycle')->where('user_id', $uid)->update(['state' => 'purged', 'changed_at_iso' => $now, 'purge_after_iso' => null, 'backup' => null]);

                return 'purged';
            });
            $counts[$outcome]++;
        }
        $this->line((string) json_encode($counts));

        return self::SUCCESS;
    }
}
