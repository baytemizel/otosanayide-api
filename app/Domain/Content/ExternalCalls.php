<?php

namespace App\Domain\Content;

use App\Domain\Account\AccountFence;
use App\Support\Tx;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * account-external-operations.ts: prepared -> dispatched -> settled/uncertain.
 * Belirsiz sonuc (zaman asimi, transport hatasi) yeniden gonderilmez; ayni kaynak
 * anahtarindaki bir sonraki cagri `external_recovery_required` ile durur.
 * Yuk, anahtar veya saglayici yaniti saklanmaz.
 */
final class ExternalCalls
{
    private const TABLE = 'account_external_operations';

    private const GATE = 'account_external_barriers';

    private const SUBJECTS = 'account_external_operation_subjects';

    /**
     * @param  array{kind:string,bindingDigest:string,resourceKey:string,resolve:Closure():array{subjects:list<string>,sourceDigest:string}}  $policy
     * @return array{subjects:list<string>,sourceDigest:string}
     */
    private static function source(array $policy): array
    {
        $raw = ($policy['resolve'])();
        $subjects = array_values(array_unique($raw['subjects']));
        sort($subjects, SORT_STRING);
        if ($subjects === [] || count($subjects) > 12) {
            throw new RuntimeException('external_subjects');
        }
        foreach ($subjects as $uid) {
            AccountFence::assertWritable($uid);
        }

        return ['subjects' => $subjects, 'sourceDigest' => $raw['sourceDigest']];
    }

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

    /** @param array{kind:string,bindingDigest:string,resourceKey:string,resolve:Closure():array{subjects:list<string>,sourceDigest:string}} $policy */
    private static function begin(array $policy, string $id): void
    {
        Tx::run(static function () use ($policy, $id): void {
            $s = self::source($policy);
            if (DB::table(self::TABLE)->where('resource_key', $policy['resourceKey'])->whereIn('state', ['prepared', 'dispatched', 'uncertain'])->lockForUpdate()->exists()) {
                throw new RuntimeException('external_recovery_required');
            }
            $now = Ids::nowMs();
            DB::table(self::TABLE)->insert(['id' => $id, 'kind' => $policy['kind'], 'binding_digest' => $policy['bindingDigest'], 'resource_key' => $policy['resourceKey'], 'source_digest' => $s['sourceDigest'], 'state' => 'prepared', 'version' => 1, 'created_at_ms' => $now, 'updated_at_ms' => $now]);
            self::gates($s['subjects']);
            foreach ($s['subjects'] as $uid) {
                DB::table(self::SUBJECTS)->insert(['operation_id' => $id, 'uid' => $uid]);
                self::gate($uid);
                DB::table(self::GATE)->where('owner_uid', $uid)->update(['active' => DB::raw('`active` + 1'), 'sequence' => DB::raw('`sequence` + 1')]);
            }
        });
    }

    /** @param array{kind:string,bindingDigest:string,resourceKey:string,resolve:Closure():array{subjects:list<string>,sourceDigest:string}} $policy */
    private static function dispatch(array $policy, string $id): void
    {
        Tx::run(static function () use ($policy, $id): void {
            $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            $s = self::source($policy);
            $subjects = DB::table(self::SUBJECTS)->where('operation_id', $id)->orderBy('uid')->pluck('uid')->all();
            $now = Ids::nowMs();
            if ($row === null || $row->state !== 'prepared' || $row->source_digest !== $s['sourceDigest'] || $subjects !== $s['subjects'] || $now < (int) $row->updated_at_ms) {
                throw new RuntimeException('external_source_changed');
            }
            DB::table(self::TABLE)->where('id', $id)->update(['state' => 'dispatched', 'version' => (int) $row->version + 1, 'updated_at_ms' => $now]);
        });
    }

    private static function settle(string $id, bool $uncertain): void
    {
        Tx::run(static function () use ($id, $uncertain): void {
            $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('external_operation_corrupt');
            }
            if ($row->state === 'settled') {
                return;
            }
            if ($row->state === 'uncertain') {
                throw new RuntimeException('external_recovery_required');
            }
            $now = Ids::nowMs();
            if ($now < (int) $row->updated_at_ms) {
                throw new RuntimeException('external_clock');
            }
            $subjects = DB::table(self::SUBJECTS)->where('operation_id', $id)->pluck('uid')->all();
            sort($subjects, SORT_STRING);
            foreach ($subjects as $uid) {
                if ((int) self::gate($uid)->active < 1) {
                    throw new RuntimeException('external_gate_corrupt');
                }
            }
            DB::table(self::TABLE)->where('id', $id)->update(['state' => $uncertain ? 'uncertain' : 'settled', 'version' => (int) $row->version + 1, 'updated_at_ms' => $now]);
            foreach ($subjects as $uid) {
                DB::table(self::GATE)->where('owner_uid', $uid)->update(
                    $uncertain ? ['uncertain' => DB::raw('`uncertain` + 1')] : ['active' => DB::raw('`active` - 1')]
                );
            }
        });
    }

    /**
     * @template T
     *
     * @param  array{kind:string,bindingDigest:string,resourceKey:string,resolve:Closure():array{subjects:list<string>,sourceDigest:string}}  $policy
     * @param  Closure():T  $send
     * @return T
     */
    public static function call(array $policy, Closure $send)
    {
        $id = Ids::uuid();
        self::begin($policy, $id);
        $sent = false;
        $known = false;
        try {
            self::dispatch($policy, $id);
            $sent = true;
            $result = $send();
            $known = true;
            self::settle($id, false);

            return $result;
        } catch (Throwable $error) {
            try {
                self::settle($id, $sent && ! $known);
            } catch (Throwable) {
                // Kapatilamayan kayit acik kalir; hic bir zaman bosaltilmis sayilmaz.
            }
            throw $error;
        }
    }
}
