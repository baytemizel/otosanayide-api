<?php

namespace App\Console\Commands;

use App\Domain\Ops\ScheduledRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * recoverScheduledRun(): running/uncertain kaydi elle idle yapar. Kaynakta MFA operator sarmalayicisiydi;
 * burada sunucu kabugu yetkisi + tam surum + "calisan durdu, dis etkiler incelendi" beyanidir.
 */
final class RecoverScheduledRun extends Command
{
    protected $signature = 'ops:scheduled-recover {job} {--expected-version= : Incelenen kaydin surumu} {--reset-cursor : Cursoru bastan baslatir} {--effects-settled : Calisan durdu ve belirsiz dis etkiler incelendi} {--apply}';

    protected $description = 'Zamanlanmis isin durumunu gosterir; --apply ile incelenmis running/uncertain kaydi idle yapar.';

    public function handle(): int
    {
        $job = (string) $this->argument('job');
        if (! in_array($job, ScheduledRuns::JOBS, true)) {
            $this->error('Bilinmeyen is.');

            return self::FAILURE;
        }
        $row = DB::table('scheduled_runs')->where('job', $job)->first(['state', 'version', 'cursor', 'updated_at_ms', 'lease_until_ms', 'completed_pages', 'completed_sweeps', 'last_counts']);
        $view = $row === null ? null : ['state' => $row->state, 'version' => (int) $row->version, 'hasCursor' => $row->cursor !== null, 'updatedAtMs' => (int) $row->updated_at_ms, 'leaseUntilMs' => (int) $row->lease_until_ms, 'pages' => (int) $row->completed_pages, 'sweeps' => (int) $row->completed_sweeps, 'lastCounts' => json_decode((string) $row->last_counts, true)];
        $this->line((string) json_encode(['job' => $job, 'run' => $view]));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $expected = filter_var($this->option('expected-version'), FILTER_VALIDATE_INT);
        if ($expected === false || ! $this->option('effects-settled')) {
            $this->error('--expected-version ve --effects-settled zorunlu.');

            return self::FAILURE;
        }
        try {
            ScheduledRuns::recover($job, $expected, (bool) $this->option('reset-cursor'), 'cli');
        } catch (Throwable) {
            $this->error('Kayit degisti veya zaten bosta; yeniden inceleyin.');

            return self::FAILURE;
        }
        $this->info('idle');

        return self::SUCCESS;
    }
}
