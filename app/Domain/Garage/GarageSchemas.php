<?php

namespace App\Domain\Garage;

use App\Support\Js;
use App\Support\Z;
use App\Support\ZodError;
use Collator;
use RuntimeException;

/** packages/core index.ts (vehicle/maintenance/reminder), local-garage.ts, garage-transfer.ts. */
final class GarageSchemas
{
    public const TRANSFER_BYTES = 500000;

    private const ID = '/^[a-zA-Z0-9_-]{1,100}$/';

    /** @return array<string,Z> */
    private static function vehicleShape(): array
    {
        return [
            'brand' => Z::string()->trim()->min(1)->max(50),
            'model' => Z::string()->trim()->min(1)->max(80),
            'year' => Z::coerceNumber()->int()->min(1950)->max((int) gmdate('Y') + 1),
            'fuel' => Z::enum(['Benzin', 'Dizel', 'Hibrit', 'Elektrikli', 'LPG']),
            'mileage' => Z::coerceNumber()->int()->min(0)->max(2000000),
            'inspectionDueDate' => Z::string()->isoDate()->optional(),
            'photoId' => Z::string()->uuid()->optional(),
        ];
    }

    public static function vehicle(): Z
    {
        return Z::strictObject(self::vehicleShape());
    }

    /** @return array<string,Z> */
    private static function maintenanceShape(): array
    {
        return [
            'vehicleId' => Z::string()->min(1),
            'title' => Z::string()->trim()->min(2)->max(120),
            'date' => Z::string()->isoDate(),
            'mileage' => Z::coerceNumber()->int()->min(0)->max(2000000),
            'amount' => Z::coerceNumber()->min(0)->max(10000000),
            'note' => Z::string()->trim()->max(1000)->default(''),
            'attachmentIds' => Z::array(Z::string()->uuid())->max(3)->optional(),
        ];
    }

    private static function notFuture(Z $schema): Z
    {
        return $schema->refine(static fn (array $v): bool => strcmp($v['date'], gmdate('Y-m-d')) <= 0, 'Gelecekteki bir işlem bakım geçmişine kaydedilemez.');
    }

    public static function maintenance(): Z
    {
        return self::notFuture(Z::strictObject(self::maintenanceShape()));
    }

    public static function reminder(): Z
    {
        return Z::strictObject([
            'vehicleId' => Z::string()->min(1),
            'title' => Z::string()->trim()->min(2)->max(100),
            'completed' => Z::boolean()->optional(),
            'dueDate' => Z::string()->isoDate()->optional(),
            'dueKm' => Z::coerceNumber()->int()->min(0)->optional(),
            'channels' => Z::array(Z::enum(['push', 'email']))->max(2)->optional(),
            'advanceDays' => Z::coerceNumber()->int()->refine(static fn (int $n): bool => in_array($n, [0, 1, 3, 7], true))->optional(),
        ])->refine(static fn (array $v): bool => ! empty($v['dueDate']) || array_key_exists('dueKm', $v), 'Tarih veya kilometre belirtin.');
    }

    private static function snapshot(): Z
    {
        $idZ = Z::string()->regex(self::ID);
        $vehicle = Z::strictObject(self::vehicleShape() + ['id' => $idZ, 'archived' => Z::boolean()]);
        $record = self::notFuture(Z::strictObject(self::maintenanceShape() + ['id' => $idZ]));

        return Z::strictObject([
            'version' => Z::literal(1),
            'vehicles' => Z::array($vehicle)->max(50),
            'records' => Z::array($record)->max(2000),
            'selectedVehicleId' => $idZ->nullable(),
        ])->refine(static function (array $v): bool {
            $ids = array_column($v['vehicles'], 'id');
            $records = array_column($v['records'], 'id');

            return count(array_unique($ids)) === count($ids)
                && count(array_unique($records)) === count($records)
                && array_diff(array_column($v['records'], 'vehicleId'), $ids) === []
                && ($v['selectedVehicleId'] === null || in_array($v['selectedVehicleId'], $ids, true));
        }, 'Invalid garage references')->refine(static function (array $v): bool {
            $seen = [];
            foreach ($v['vehicles'] as $x) {
                if (isset($x['photoId'])) {
                    if (isset($seen[$x['photoId']])) {
                        return false;
                    }
                    $seen[$x['photoId']] = true;
                }
            }
            foreach ($v['records'] as $x) {
                foreach ($x['attachmentIds'] ?? [] as $a) {
                    if (isset($seen[$a])) {
                        return false;
                    }
                    $seen[$a] = true;
                }
            }

            return true;
        }, 'Invalid media references');
    }

    /** @return array{version:int,vehicles:list<array<string,mixed>>,records:list<array<string,mixed>>,selectedVehicleId:?string} */
    public static function emptyGarage(): array
    {
        return ['version' => 1, 'vehicles' => [], 'records' => [], 'selectedVehicleId' => null];
    }

    /** decodeGarage(JSON metni). */
    public static function decode(string $raw): array
    {
        if (Js::length($raw) > 1000000) {
            throw new RuntimeException('garage_size');
        }

        return self::snapshot()->parse(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
    }

    /** garageText(): secili arac ve ozel medya kimligi yedege girmez; kimlige gore siralanir. */
    public static function text(array $state): array
    {
        $valid = self::decode(Js::stringify($state));
        $collator = new Collator('root');
        $sort = static function (array $rows) use ($collator): array {
            usort($rows, static fn (array $a, array $b): int => (int) $collator->compare($a['id'], $b['id']));

            return $rows;
        };
        $valid['selectedVehicleId'] = null;
        $valid['vehicles'] = $sort(array_map(static function (array $v): array {
            unset($v['photoId']);

            return $v;
        }, $valid['vehicles']));
        $valid['records'] = $sort(array_map(static function (array $r): array {
            unset($r['attachmentIds']);

            return $r;
        }, $valid['records']));

        return $valid;
    }

    /** parseGarageTransfer() */
    public static function transfer(mixed $value): array
    {
        $raw = Js::stringify($value);
        if (strlen($raw) > self::TRANSFER_BYTES) {
            throw new RuntimeException('transfer_size');
        }
        $valid = self::decode($raw);
        if ($valid['selectedVehicleId'] !== null
            || collect($valid['vehicles'])->contains(static fn (array $v): bool => array_key_exists('photoId', $v))
            || collect($valid['records'])->contains(static fn (array $r): bool => array_key_exists('attachmentIds', $r))) {
            throw new RuntimeException('transfer_media');
        }

        return self::text($valid);
    }

    public static function transferField(): Z
    {
        return Z::any()->transform(static function (mixed $v): array {
            try {
                return self::transfer($v);
            } catch (\Throwable) {
                throw new ZodError('custom', 'Geçersiz garaj yedeği.');
            }
        });
    }

    public static function backupWrite(): Z
    {
        return Z::strictObject([
            'expectedRevision' => Z::number()->int()->min(0)->max(1000000000),
            'operationId' => Z::string()->uuid(),
            'garage' => self::transferField(),
        ]);
    }

    public static function backup(): Z
    {
        return Z::strictObject([
            'revision' => Z::number()->int()->min(1)->max(1000000001),
            'updatedAt' => Z::string()->isoDatetime(),
            'operationId' => Z::string()->uuid(),
            'garage' => self::transferField(),
        ]);
    }

    /**
     * planGarageBackup()
     *
     * @return array{backup:array<string,mixed>,changed:bool}
     */
    public static function planBackup(?array $before, array $write, string $now): array
    {
        if ($before !== null && $before['operationId'] === $write['operationId']) {
            if ($before['revision'] === $write['expectedRevision'] + 1 && Js::stringify($before['garage']) === Js::stringify($write['garage'])) {
                return ['backup' => $before, 'changed' => false];
            }
            throw new RuntimeException('transfer_conflict');
        }
        if (($before['revision'] ?? 0) !== $write['expectedRevision']) {
            throw new RuntimeException('transfer_conflict');
        }
        if ($before !== null && strcmp($now, $before['updatedAt']) < 0) {
            throw new RuntimeException('transfer_clock');
        }

        return ['backup' => ['garage' => $write['garage'], 'operationId' => $write['operationId'], 'revision' => $write['expectedRevision'] + 1, 'updatedAt' => $now], 'changed' => true];
    }

    /** accountGarage(): saklama metadatasi atilmis satirlardan. */
    public static function accountGarage(array $vehicles, array $maintenance): array
    {
        return self::decode(Js::stringify(['version' => 1, 'vehicles' => $vehicles, 'records' => $maintenance, 'selectedVehicleId' => null]));
    }

    /** combineSharedGarage() */
    public static function combine(array $base, array $incoming, bool $replace): array
    {
        $left = self::transfer($base);
        $right = self::transfer($incoming);
        $merge = static function (array $old, array $next) use ($replace): array {
            $map = [];
            foreach ($old as $v) {
                $map[$v['id']] = $v;
            }
            foreach ($next as $v) {
                $existing = $map[$v['id']] ?? null;
                if ($existing !== null) {
                    if (($existing['vehicleId'] ?? null) !== ($v['vehicleId'] ?? null)) {
                        throw new RuntimeException('shared_conflict');
                    }
                    if (! $replace && Js::stringify($existing) !== Js::stringify($v)) {
                        throw new RuntimeException('shared_conflict');
                    }
                }
                $map[$v['id']] = $v;
            }

            return array_values($map);
        };

        return self::transfer(['version' => 1, 'vehicles' => $merge($left['vehicles'], $right['vehicles']), 'records' => $merge($left['records'], $right['records']), 'selectedVehicleId' => null]);
    }

    /** saveGarageVehicle() */
    public static function saveVehicle(array $state, array $parsed, string $id): array
    {
        $existing = collect($state['vehicles'])->firstWhere('id', $id);
        $vehicle = $parsed + ['id' => $id, 'archived' => $existing['archived'] ?? false];
        $vehicles = $existing !== null
            ? array_map(static fn (array $v): array => $v['id'] === $id ? $vehicle : $v, $state['vehicles'])
            : [...$state['vehicles'], $vehicle];

        return self::decode(Js::stringify(['version' => 1, 'vehicles' => $vehicles, 'records' => $state['records'], 'selectedVehicleId' => $id]));
    }

    /** saveGarageRecord() */
    public static function saveRecord(array $state, array $parsed, string $id): array
    {
        $vehicle = collect($state['vehicles'])->firstWhere('id', $parsed['vehicleId']);
        $existing = collect($state['records'])->firstWhere('id', $id);
        if ($vehicle === null || $vehicle['archived'] || ($existing !== null && $existing['vehicleId'] !== $vehicle['id'])) {
            throw new RuntimeException('garage_vehicle');
        }
        $record = $parsed;
        $record['amount'] = self::roundCents($parsed['amount']);
        $record['id'] = $id;
        $records = $existing !== null ? array_map(static fn (array $r): array => $r['id'] === $id ? $record : $r, $state['records']) : [...$state['records'], $record];
        $vehicles = array_map(static fn (array $v): array => $v['id'] === $vehicle['id'] ? array_merge($v, ['mileage' => max($v['mileage'], $parsed['mileage'])]) : $v, $state['vehicles']);

        return self::decode(Js::stringify(['version' => 1, 'vehicles' => $vehicles, 'records' => $records, 'selectedVehicleId' => $state['selectedVehicleId']]));
    }

    private static function roundCents(int|float $n): int|float
    {
        // Math.round(x*100)/100: JS Math.round pozitifte yarimi yukari yuvarlar.
        $r = floor($n * 100 + 0.5) / 100;

        return $r == floor($r) ? (int) $r : $r;
    }
}
