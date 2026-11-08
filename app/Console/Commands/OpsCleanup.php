<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sureli kayit temizligi: jetonlar, oturumlar, e-posta/MFA jetonlari, cache ve hiz siniri.
 * auth_events ve is kayitlari silinmez: saklama suresi kararlastirilmadi (ERA-07).
 */
final class OpsCleanup extends Command
{
    protected $signature = 'ops:cleanup {--dry-run : Yalniz sayar}';

    protected $description = 'Suresi dolmus jeton, oturum, hiz siniri ve cache kayitlarini parcalar halinde siler.';

    private const BATCH = 1000;

    private const MAX_BATCHES = 200;

    public function handle(): int
    {
        $now = gmdate('Y-m-d H:i:s').'.000';
        $epoch = time();
        $targets = [
            'personal_access_tokens' => fn () => DB::table('personal_access_tokens')->where('expires_at', '<', $now),
            // Suresi dolan oturum silinmeden once tekrar kullanim tespiti gerekmez: cerez de gecersizdir.
            'auth_sessions' => fn () => DB::table('auth_sessions')->where('expires_at', '<', $now),
            'auth_email_tokens' => fn () => DB::table('auth_email_tokens')->where('expires_at', '<', $now),
            'auth_mfa_challenges' => fn () => DB::table('auth_mfa_challenges')->where('expires_at', '<', $now),
            'cache' => fn () => DB::table('cache')->where('expiration', '<', $epoch),
            'cache_locks' => fn () => DB::table('cache_locks')->where('expiration', '<', $epoch),
        ];
        foreach ($targets as $name => $query) {
            if ($this->option('dry-run')) {
                $this->line($name.': '.$query()->count());

                continue;
            }
            $total = 0;
            for ($i = 0; $i < self::MAX_BATCHES; $i++) {
                $deleted = $query()->limit(self::BATCH)->delete();
                $total += $deleted;
                if ($deleted < self::BATCH) {
                    break;
                }
            }
            $this->line($name.': '.$total);
        }

        return self::SUCCESS;
    }
}
