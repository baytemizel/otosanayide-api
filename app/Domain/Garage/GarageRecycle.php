<?php

namespace App\Domain\Garage;

use App\Support\Js;
use App\Support\Z;
use App\Support\ZodError;
use RuntimeException;

/** packages/core/garage-recycle.ts */
final class GarageRecycle
{
    public const DAYS = 30;

    public static function action(): Z
    {
        return Z::strictObject([
            'action' => Z::enum(['trash', 'restore']),
            'expectedRevision' => Z::number()->int()->min(1)->max(1000000001),
            'operationId' => Z::string()->uuid(),
            'acknowledged' => Z::literal(true),
        ]);
    }

    /** garageRecycleSchema.parse(saklanan) */
    public static function assertValid(?array $r): void
    {
        if ($r === null) {
            return;
        }
        if ($r['state'] === 'trashed') {
            $b = $r['backup'];
            if ($r['revision'] !== $b['revision'] + 1 || Js::ms($r['changedAt']) < Js::ms($b['updatedAt']) || Js::ms($r['purgeAfter']) !== Js::ms($r['changedAt']) + self::DAYS * 86400000) {
                throw new ZodError('custom', 'Invalid recycle metadata.');
            }
        }
    }

    public static function status(?array $backup, ?array $recycle, bool $shared, string $now): array
    {
        if ($shared) {
            return ['state' => 'shared', 'revision' => $backup['revision'] ?? null, 'vehicles' => 0, 'records' => 0, 'purgeAfter' => null];
        }
        if (($recycle['state'] ?? null) === 'trashed') {
            self::assertEmpty($backup, $recycle['revision'], $recycle['operationId']);

            return ['state' => Js::ms($now) >= Js::ms($recycle['purgeAfter']) ? 'expired' : 'trashed', 'revision' => $backup['revision'], 'vehicles' => count($recycle['backup']['garage']['vehicles']), 'records' => count($recycle['backup']['garage']['records']), 'purgeAfter' => $recycle['purgeAfter']];
        }
        $has = $backup !== null && ($backup['garage']['vehicles'] !== [] || $backup['garage']['records'] !== []);

        return ['state' => $has ? 'available' : 'none', 'revision' => $backup['revision'] ?? null, 'vehicles' => count($backup['garage']['vehicles'] ?? []), 'records' => count($backup['garage']['records'] ?? []), 'purgeAfter' => null];
    }

    private static function assertEmpty(?array $backup, int $rev, string $operationId): void
    {
        if ($backup === null || $backup['revision'] !== $rev || $backup['operationId'] !== $operationId || $backup['garage']['vehicles'] !== [] || $backup['garage']['records'] !== []) {
            throw new RuntimeException('recycle_corrupt');
        }
    }

    /** @return array{backup:array<string,mixed>,recycle:array<string,mixed>,changed:bool} */
    public static function plan(?array $backup, ?array $recycle, array $input, bool $shared, string $now): array
    {
        if ($shared) {
            throw new RuntimeException('recycle_shared');
        }
        if ($backup === null) {
            throw new RuntimeException('recycle_conflict');
        }
        if (($recycle['operationId'] ?? null) === $input['operationId']) {
            $state = $input['action'] === 'trash' ? 'trashed' : 'restored';
            if ($recycle['state'] === $state && $recycle['revision'] === $input['expectedRevision'] + 1 && $backup['revision'] === $recycle['revision'] && $backup['operationId'] === $input['operationId']) {
                if ($recycle['state'] === 'trashed') {
                    self::assertEmpty($backup, $recycle['revision'], $recycle['operationId']);
                }

                return ['backup' => $backup, 'recycle' => $recycle, 'changed' => false];
            }
            throw new RuntimeException('recycle_conflict');
        }
        if ($backup['revision'] !== $input['expectedRevision'] || Js::ms($now) < Js::ms($backup['updatedAt'])) {
            throw new RuntimeException('recycle_conflict');
        }
        $next = $backup['revision'] + 1;
        if ($input['action'] === 'trash') {
            if (($recycle['state'] ?? null) === 'trashed' || ($backup['garage']['vehicles'] === [] && $backup['garage']['records'] === [])) {
                throw new RuntimeException('recycle_conflict');
            }
            $purge = Js::iso((new \DateTimeImmutable('@'.intdiv(Js::ms($now) + self::DAYS * 86400000, 1000)))->modify('+'.((Js::ms($now) + self::DAYS * 86400000) % 1000).' milliseconds'));

            return ['backup' => ['revision' => $next, 'operationId' => $input['operationId'], 'updatedAt' => $now, 'garage' => GarageSchemas::emptyGarage()], 'recycle' => ['state' => 'trashed', 'revision' => $next, 'operationId' => $input['operationId'], 'changedAt' => $now, 'purgeAfter' => $purge, 'backup' => $backup], 'changed' => true];
        }
        if (($recycle['state'] ?? null) !== 'trashed' || Js::ms($now) < Js::ms($recycle['changedAt']) || Js::ms($now) >= Js::ms($recycle['purgeAfter'])) {
            throw new RuntimeException('recycle_conflict');
        }
        self::assertEmpty($backup, $recycle['revision'], $recycle['operationId']);

        return ['backup' => array_merge($recycle['backup'], ['revision' => $next, 'operationId' => $input['operationId'], 'updatedAt' => $now]), 'recycle' => ['state' => 'restored', 'revision' => $next, 'operationId' => $input['operationId'], 'changedAt' => $now], 'changed' => true];
    }
}
