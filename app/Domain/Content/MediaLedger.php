<?php

namespace App\Domain\Content;

use App\Support\Tx;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * media-operation-ledger.ts: saglayici cagrisi her zaman open -> dispatched -> closed/uncertain kaydinin icinde gider.
 * Gonderim sonucu bilinemiyorsa (uncertain) yeniden denenmez; sayac, uzlastirma yapilana kadar kapanmaz.
 * Bu sinifin cagrilari disinda ayni nesneye yazilmaz.
 */
final class MediaLedger
{
    private const TABLE = 'account_media_operations';

    private const GATE = 'account_media_barriers';

    private const SUBJECTS = 'account_media_operation_subjects';

    /** @param list<string> $subjects */
    private static function gates(array $subjects): void
    {
        foreach ($subjects as $uid) {
            DB::insert('INSERT INTO `'.self::GATE.'` (`owner_uid`, `active`, `uncertain`, `sequence`) VALUES (?, 0, 0, 0) ON DUPLICATE KEY UPDATE `owner_uid` = VALUES(`owner_uid`)', [$uid]);
        }
    }

    private static function gate(string $uid): stdClass
    {
        return DB::table(self::GATE)->where('owner_uid', $uid)->lockForUpdate()->first();
    }

    /**
     * @param  array{kind:string,id:string,claimId?:string}  $resource
     * @return array<string,mixed>
     */
    private static function begin(?string $actor, array $resource, string $path, string $method): array
    {
        $id = Ids::uuid();
        $now = Ids::nowMs();

        return Tx::run(static function () use ($actor, $resource, $path, $method, $id, $now): array {
            $binding = Barrier::mediaBinding($resource, $actor);
            if (! in_array($path, $binding['paths'], true)) {
                throw new RuntimeException('media_object_path');
            }
            if (DB::table(self::TABLE)->where('path', $path)->whereIn('state', ['open', 'uncertain'])->lockForUpdate()->exists()) {
                throw new RuntimeException('media_object_pending');
            }
            self::gates($binding['subjects']);
            foreach ($binding['subjects'] as $uid) {
                $g = self::gate($uid);
                if ((int) $g->active >= 1000) {
                    throw new RuntimeException('media_operation_capacity');
                }
            }
            $op = [
                'id' => $id,
                'actor_uid' => $actor,
                'resource_kind' => $resource['kind'],
                'resource_id' => $resource['id'],
                'resource_claim_id' => $resource['claimId'] ?? null,
                'path' => $path,
                'method' => $method,
                'binding' => json_encode($binding, JSON_THROW_ON_ERROR),
                'state' => 'open',
                'dispatched' => false,
                'version' => 1,
                'created_at_ms' => $now,
                'updated_at_ms' => $now,
                'resolved_by' => null,
                'evidence_digest' => null,
            ];
            DB::table(self::TABLE)->insert($op);
            foreach ($binding['subjects'] as $uid) {
                DB::table(self::SUBJECTS)->insert(['operation_id' => $id, 'uid' => $uid]);
                DB::table(self::GATE)->where('owner_uid', $uid)->update(['active' => DB::raw('`active` + 1'), 'sequence' => DB::raw('`sequence` + 1')]);
            }

            return ['id' => $id, 'version' => 1, 'binding' => $binding];
        });
    }

    /** @param array<string,mixed> $op */
    private static function dispatch(array $op, array $resource, ?string $actor): void
    {
        $now = Ids::nowMs();
        Tx::run(static function () use ($op, $resource, $actor, $now): void {
            $row = DB::table(self::TABLE)->where('id', $op['id'])->lockForUpdate()->first();
            if ($row === null || $row->state !== 'open' || (bool) $row->dispatched || (int) $row->version !== $op['version'] || $now < (int) $row->updated_at_ms) {
                throw new RuntimeException('media_operation_conflict');
            }
            $binding = Barrier::mediaBinding($resource, $actor);
            if (Digest::of($binding) !== Digest::of(json_decode((string) $row->binding, true, 512, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('media_source_changed');
            }
            DB::table(self::TABLE)->where('id', $op['id'])->update(['dispatched' => true, 'version' => (int) $row->version + 1, 'updated_at_ms' => $now]);
        });
    }

    private static function settle(string $id, bool $uncertain): void
    {
        $now = Ids::nowMs();
        Tx::run(static function () use ($id, $uncertain, $now): void {
            $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('media_operation_missing');
            }
            if ($row->state === 'closed') {
                return;
            }
            if ($row->state === 'uncertain') {
                if ($uncertain) {
                    return;
                }
                throw new RuntimeException('media_reconciliation_required');
            }
            if ($now < (int) $row->updated_at_ms) {
                throw new RuntimeException('media_clock');
            }
            $subjects = DB::table(self::SUBJECTS)->where('operation_id', $id)->pluck('uid')->all();
            sort($subjects, SORT_STRING);
            foreach ($subjects as $uid) {
                if ((int) self::gate($uid)->active < 1) {
                    throw new RuntimeException('media_gate_corrupt');
                }
            }
            DB::table(self::TABLE)->where('id', $id)->update(['state' => $uncertain ? 'uncertain' : 'closed', 'version' => (int) $row->version + 1, 'updated_at_ms' => $now]);
            foreach ($subjects as $uid) {
                DB::table(self::GATE)->where('owner_uid', $uid)->update(
                    $uncertain ? ['uncertain' => DB::raw('`uncertain` + 1')] : ['active' => DB::raw('`active` - 1')]
                );
            }
        });
    }

    /**
     * mediaProviderMutation: $prepare gonderim kapanisini doner; yalniz o kapanis saglayiciya dokunur.
     *
     * @template T
     *
     * @param  array{kind:string,id:string,claimId?:string}  $resource
     * @param  Closure(array<string,mixed>):(Closure():T)  $prepare
     * @return T
     */
    public static function mutate(?string $actor, array $resource, string $path, string $method, Closure $prepare)
    {
        $op = self::begin($actor, $resource, $path, $method);
        $sent = false;
        $settled = false;
        try {
            $send = $prepare($op);
            self::dispatch($op, $resource, $actor);
            $sent = true;
            $value = $send();
            $settled = true;
            self::settle($op['id'], false);

            return $value;
        } catch (Throwable $error) {
            try {
                self::settle($op['id'], $sent && ! $settled);
            } catch (Throwable) {
                // Kaydedilemeyen kapanis acik kalir; hic bir zaman kapanmis sayilmaz.
            }
            throw $error;
        }
    }
}
