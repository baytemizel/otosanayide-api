<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Exceptions\WriteConflict;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/** packages/core/privacy-requests.ts + packages/server/privacy-requests.ts */
final class Privacy
{
    private const UID = '/^[A-Za-z0-9_-]+$/';

    public static function ownerAction(): Z
    {
        return Z::union([self::submitSchema(), Z::strictObject(self::existing() + ['action' => Z::literal('cancel')]), Z::strictObject(self::existing() + ['action' => Z::literal('confirm'), 'acknowledged' => Z::literal(true), 'confirmedScope' => Z::literal(true)])]);
    }

    public static function adminAction(): Z
    {
        $owner = ['ownerUid' => self::uid()];

        return Z::union([Z::strictObject(self::existing() + $owner + ['action' => Z::literal('start_review')]), Z::strictObject(self::existing() + $owner + ['action' => Z::literal('request_confirmation'), 'reason' => Z::enum(['scope_confirmation', 'linked_business', 'billing_records'])])]);
    }

    public static function uid(): Z
    {
        return Z::string()->min(1)->max(128)->regex(self::UID);
    }

    private static function submitSchema(): Z
    {
        return Z::strictObject(['action' => Z::literal('submit'), 'operationId' => Z::string()->uuid(), 'scope' => Z::enum(['garage', 'account']), 'acknowledged' => Z::literal(true), 'confirmedScope' => Z::literal(true)]);
    }

    /** @return array<string,Z> */
    private static function existing(): array
    {
        return ['requestId' => Z::string()->uuid(), 'expectedVersion' => Z::number()->int()->min(1)->max(1000000000), 'operationId' => Z::string()->uuid()];
    }

    public static function recentLogin(int $authTime, int $nowMs): bool
    {
        return $authTime * 1000 <= $nowMs + 30000 && $authTime * 1000 >= $nowMs - 600000;
    }

    /** @return array<string,mixed>|null */
    public static function view(?array $v): ?array
    {
        if ($v === null) {
            return null;
        }

        return ['id' => $v['id'], 'scope' => $v['scope'], 'status' => $v['status'], 'version' => $v['version'], 'createdAtMs' => $v['createdAtMs'], 'updatedAtMs' => $v['updatedAtMs'], 'reason' => $v['reason']];
    }

    /** @return array{request:array<string,mixed>,changed:bool} */
    public static function planSubmit(?array $previous, string $ownerUid, array $input, int $nowMs): array
    {
        if ($previous !== null) {
            if ($previous['ownerUid'] !== $ownerUid) {
                throw new RuntimeException('privacy_owner');
            }
            if ($previous['id'] === $input['operationId']) {
                if ($previous['scope'] !== $input['scope'] || $previous['lastAction']['action'] !== 'submit' || $previous['version'] !== 1) {
                    throw new RuntimeException('privacy_conflict');
                }

                return ['request' => $previous, 'changed' => false];
            }
            if ($previous['status'] !== 'cancelled') {
                throw new RuntimeException('privacy_active');
            }
            if ($nowMs < $previous['updatedAtMs']) {
                throw new RuntimeException('privacy_clock');
            }
        }

        return ['request' => ['id' => $input['operationId'], 'ownerUid' => $ownerUid, 'scope' => $input['scope'], 'status' => 'submitted', 'version' => 1, 'createdAtMs' => $nowMs, 'updatedAtMs' => $nowMs, 'reason' => null, 'lastAction' => ['operationId' => $input['operationId'], 'action' => 'submit', 'expectedVersion' => 0, 'actorUid' => $ownerUid]], 'changed' => true];
    }

    /** @return array{request:array<string,mixed>,changed:bool} */
    public static function planAction(array $previous, array $input, string $actorUid, bool $admin, int $nowMs): array
    {
        if ($input['action'] === 'submit') {
            throw new RuntimeException('privacy_conflict');
        }
        if ($previous['id'] !== $input['requestId'] || (! $admin && $previous['ownerUid'] !== $actorUid) || (array_key_exists('ownerUid', $input) && $previous['ownerUid'] !== $input['ownerUid'])) {
            throw new RuntimeException('privacy_owner');
        }
        $last = $previous['lastAction'];
        if ($last['operationId'] === $input['operationId']) {
            if ($last['action'] === $input['action'] && $last['actorUid'] === $actorUid && $last['expectedVersion'] === $input['expectedVersion'] && $previous['version'] === $input['expectedVersion'] + 1 && (! array_key_exists('reason', $input) || $input['reason'] === $previous['reason'])) {
                return ['request' => $previous, 'changed' => false];
            }
            throw new RuntimeException('privacy_conflict');
        }
        if ($previous['version'] !== $input['expectedVersion'] || $nowMs < $previous['updatedAtMs']) {
            throw new RuntimeException('privacy_conflict');
        }
        $status = match (true) {
            $input['action'] === 'cancel' && $previous['status'] !== 'cancelled' => 'cancelled',
            $input['action'] === 'confirm' && $previous['status'] === 'needs_confirmation' => 'submitted',
            $input['action'] === 'start_review' && $previous['status'] === 'submitted' => 'reviewing',
            $input['action'] === 'request_confirmation' && $previous['status'] === 'reviewing' => 'needs_confirmation',
            default => throw new RuntimeException('privacy_conflict'),
        };

        return ['request' => array_merge($previous, ['status' => $status, 'reason' => $input['action'] === 'request_confirmation' ? $input['reason'] : null, 'version' => $previous['version'] + 1, 'updatedAtMs' => $nowMs, 'lastAction' => ['operationId' => $input['operationId'], 'action' => $input['action'], 'expectedVersion' => $input['expectedVersion'], 'actorUid' => $actorUid]]), 'changed' => true];
    }

    private static function fromRow(stdClass $r): array
    {
        $v = ['id' => $r->id, 'ownerUid' => $r->owner_uid, 'scope' => $r->scope, 'status' => $r->status, 'version' => (int) $r->version, 'createdAtMs' => (int) $r->created_at_ms, 'updatedAtMs' => (int) $r->updated_at_ms, 'reason' => $r->reason, 'lastAction' => ['operationId' => $r->last_operation_id, 'action' => $r->last_action, 'expectedVersion' => (int) $r->last_expected_version, 'actorUid' => $r->last_actor_uid]];
        if ($v['updatedAtMs'] < $v['createdAtMs'] || ($v['status'] === 'needs_confirmation') !== ($v['reason'] !== null)) {
            throw new RuntimeException('privacy_corrupt');
        }

        return $v;
    }

    private static function current(string $uid): ?array
    {
        $id = DB::table('privacy_state')->where('owner_uid', $uid)->lockForUpdate()->value('request_id');
        if ($id === null) {
            return null;
        }
        $row = DB::table('privacy_requests')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || $row->owner_uid !== $uid) {
            throw new RuntimeException('privacy_corrupt');
        }

        return self::fromRow($row);
    }

    private static function save(array $r): void
    {
        DB::table('privacy_requests')->updateOrInsert(['id' => $r['id']], ['owner_uid' => $r['ownerUid'], 'scope' => $r['scope'], 'status' => $r['status'], 'version' => $r['version'], 'created_at_ms' => $r['createdAtMs'], 'updated_at_ms' => $r['updatedAtMs'], 'reason' => $r['reason'], 'last_operation_id' => $r['lastAction']['operationId'], 'last_action' => $r['lastAction']['action'], 'last_expected_version' => $r['lastAction']['expectedVersion'], 'last_actor_uid' => $r['lastAction']['actorUid']]);
    }

    public static function readOwn(string $uid): ?array
    {
        return Tx::run(fn () => self::view(self::current($uid)));
    }

    public static function changeOwn(string $uid, array $input, int $nowMs): ?array
    {
        return Tx::run(function () use ($uid, $input, $nowMs): ?array {
            $fence = AccountFence::read($uid);
            if ($fence !== null && ($fence === 'deleted' || $input['action'] !== 'cancel')) {
                throw new WriteConflict('account_erasing');
            }
            $before = self::current($uid);
            if ($input['action'] === 'submit') {
                if (DB::table('privacy_requests')->where('id', $input['operationId'])->lockForUpdate()->exists() && ($before['id'] ?? null) !== $input['operationId']) {
                    throw new RuntimeException('privacy_conflict');
                }
                $plan = self::planSubmit($before, $uid, $input, $nowMs);
            } else {
                if ($before === null) {
                    throw new RuntimeException('privacy_conflict');
                }
                $plan = self::planAction($before, $input, $uid, false, $nowMs);
            }
            if ($plan['changed']) {
                self::save($plan['request']);
                DB::table('privacy_state')->updateOrInsert(['owner_uid' => $uid], ['request_id' => $plan['request']['id']]);
                DB::table('privacy_request_history')->insert(['request_id' => $plan['request']['id'], 'operation_id' => $input['operationId'], 'version' => $plan['request']['version'], 'action' => $input['action'], 'actor_uid' => $uid, 'at_ms' => $nowMs, 'status' => $plan['request']['status']]);
            }

            return self::view($plan['request']);
        });
    }

    public static function changeAdmin(string $adminUid, array $input, int $nowMs): array
    {
        return Tx::run(function () use ($adminUid, $input, $nowMs): array {
            AccountFence::assertWritable($adminUid);
            if ($input['ownerUid'] !== $adminUid) {
                AccountFence::assertWritable($input['ownerUid']);
            }
            $before = self::current($input['ownerUid']);
            if ($before === null) {
                throw new RuntimeException('privacy_conflict');
            }
            $plan = self::planAction($before, $input, $adminUid, true, $nowMs);
            if ($plan['changed']) {
                self::save($plan['request']);
                DB::table('privacy_request_history')->insert(['request_id' => $plan['request']['id'], 'operation_id' => $input['operationId'], 'version' => $plan['request']['version'], 'action' => $input['action'], 'actor_uid' => $adminUid, 'at_ms' => $nowMs, 'status' => $plan['request']['status'], 'reason' => $input['reason'] ?? null]);
            }

            return self::view($plan['request']) + ['ownerUid' => $input['ownerUid']];
        });
    }

    /** listPrivacyQueue(): status/createdAtMs/kimlik sirali, 20'lik sayfa. */
    public static function queue(string $status, ?array $cursor): array
    {
        $q = DB::table('privacy_requests')->where('status', $status);
        if ($cursor !== null) {
            $q->where(function ($w) use ($cursor): void {
                $w->where('created_at_ms', '>', $cursor['createdAtMs'])->orWhere(function ($e) use ($cursor): void {
                    $e->where('created_at_ms', $cursor['createdAtMs'])->where(function ($o) use ($cursor): void {
                        $o->where('owner_uid', '>', $cursor['ownerUid'])->orWhere(fn ($i) => $i->where('owner_uid', $cursor['ownerUid'])->where('id', '>', $cursor['requestId']));
                    });
                });
            });
        }
        $rows = $q->orderBy('created_at_ms')->orderBy('owner_uid')->orderBy('id')->limit(21)->get();
        $items = $rows->take(20)->map(fn (stdClass $r) => self::view(self::fromRow($r)) + ['ownerUid' => $r->owner_uid])->values()->all();
        $last = $items === [] ? null : $items[array_key_last($items)];

        return ['items' => $items, 'next' => $rows->count() > 20 && $last !== null ? ['createdAtMs' => $last['createdAtMs'], 'ownerUid' => $last['ownerUid'], 'requestId' => $last['id']] : null, 'execution' => 'not_enabled'];
    }
}
