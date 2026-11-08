<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/** packages/server/push-devices.ts. pushTokenClaims yerine push_devices.token_hash UNIQUE. */
final class PushDevices
{
    public static function command(): Z
    {
        return Z::discriminatedUnion('action', [
            'register' => Z::strictObject(['action' => Z::literal('register'), 'deviceId' => Z::string()->uuid(), 'token' => Z::string()->regex('/^[A-Za-z0-9_:.-]{20,4096}$/'), 'provider' => Z::literal('fcm'), 'platform' => Z::enum(['web', 'android', 'ios']), 'locale' => Z::enum(['tr', 'en']), 'appId' => Z::string()->regex('/^1:\d+:(?:web|android|ios):[a-f0-9]+$/')]),
            'revoke' => Z::strictObject(['action' => Z::literal('revoke'), 'deviceId' => Z::string()->uuid(), 'expectedVersion' => Z::number()->int()->positive(), 'expectedGeneration' => Z::string()->uuid()]),
        ]);
    }

    private static function view(stdClass $r): array
    {
        return ['generation' => $r->generation, 'deviceId' => $r->device_id, 'platform' => $r->platform, 'locale' => $r->locale, 'enabled' => (bool) $r->enabled, 'version' => (int) $r->version, 'updatedAtMs' => (int) $r->updated_at_ms];
    }

    public static function list(string $uid): array
    {
        return Tx::run(function () use ($uid): array {
            AccountFence::assertWritable($uid);
            $rows = DB::table('push_devices')->where('user_id', $uid)->orderBy('device_id')->limit(21)->lockForUpdate()->get();
            if ($rows->count() > 20) {
                throw new RuntimeException('push_capacity');
            }

            return ['devices' => $rows->map(fn (stdClass $r) => self::view($r))->values()->all()];
        });
    }

    /** @param list<string> $appIds */
    public static function write(string $uid, mixed $raw, array $appIds): array
    {
        $input = self::command()->parse($raw);
        if ($input['action'] === 'register' && ! in_array($input['appId'], $appIds, true)) {
            throw new RuntimeException('push_app');
        }
        $now = Js::nowMs();
        try {
            Tx::run(function () use ($uid, $input, $now): void {
                AccountFence::assertWritable($uid);
                $old = DB::table('push_devices')->where('user_id', $uid)->where('device_id', $input['deviceId'])->lockForUpdate()->first();
                $count = DB::table('push_devices')->where('user_id', $uid)->lockForUpdate()->count();
                if ($count > 20 || ($old === null && $count >= 20)) {
                    throw new RuntimeException('push_capacity');
                }
                if ($input['action'] === 'revoke') {
                    if ($old === null || (int) $old->version !== $input['expectedVersion'] || $old->generation !== $input['expectedGeneration']) {
                        throw new RuntimeException('push_version');
                    }
                    DB::table('push_devices')->where('user_id', $uid)->where('device_id', $input['deviceId'])->delete();

                    return;
                }
                $hash = hash('sha256', $input['token']);
                $owner = DB::table('push_devices')->where('token_hash', $hash)->lockForUpdate()->first();
                if ($owner !== null && ($owner->user_id !== $uid || $owner->device_id !== $input['deviceId'])) {
                    throw new RuntimeException('push_token_bound');
                }
                DB::table('push_devices')->updateOrInsert(['user_id' => $uid, 'device_id' => $input['deviceId']], ['generation' => $old->generation ?? (string) Str::uuid(), 'app_id' => $input['appId'], 'platform' => $input['platform'], 'locale' => $input['locale'], 'enabled' => true, 'version' => ((int) ($old->version ?? 0)) + 1, 'updated_at_ms' => $now, 'refresh_after_ms' => $now + 30 * 86400000, 'token' => $input['token'], 'token_hash' => $hash]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('push_token_bound');
        }

        return self::list($uid);
    }
}
