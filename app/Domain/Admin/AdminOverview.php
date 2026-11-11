<?php

namespace App\Domain\Admin;

use App\Support\Js;
use Illuminate\Support\Facades\DB;

/** web-16 madde 1 ve 4: kuyruk sayilari ve salt okunur bayrak listesi. Gelir/finans sayisi ve sir yok. */
final class AdminOverview
{
    /** @return array{queues:array<string,int>,generatedAtMs:int} */
    public static function queues(): array
    {
        $count = static fn (string $table, string $column, array $values): int => DB::table($table)->whereIn($column, $values)->count();

        return ['queues' => [
            'claims' => $count('claims', 'status', ['submitted']),
            'reviews' => $count('reviews', 'status', ['pending']),
            'reviewPhotos' => $count('review_photos', 'status', ['pending']),
            'replies' => $count('review_replies', 'status', ['pending']),
            'businessChanges' => $count('business_changes', 'status', ['pending']),
            'reviewCases' => $count('review_cases', 'status', ['open']),
            'businessPhotos' => $count('business_photos', 'status', ['pending']),
            'privacy' => $count('privacy_requests', 'status', ['submitted']),
            'privacyExports' => $count('privacy_exports', 'status', ['submitted', 'in_progress']),
            'businessReports' => $count('business_reports', 'status', ['submitted']),
        ], 'generatedAtMs' => Js::nowMs()];
    }

    /** Yalniz ad ve acik/kapali. Deger degistirme yok: bayraklar dagitim yapilandirmasidir. */
    public static function flags(): array
    {
        $flags = [];
        foreach ((array) config('otosanayide.flags') as $key => $on) {
            $flags[] = ['name' => strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key)).'_ENABLED', 'key' => $key, 'enabled' => $on === true, 'source' => 'env'];
        }
        $workers = [];
        foreach ((array) config('otosanayide.workers') as $key => $on) {
            $workers[] = ['key' => $key, 'enabled' => $on === true, 'source' => 'env'];
        }

        return ['flags' => $flags, 'workers' => $workers, 'pushDriver' => config('otosanayide.push.driver') === 'live' ? 'live' : 'log', 'writable' => false];
    }
}
