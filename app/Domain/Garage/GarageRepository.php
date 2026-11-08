<?php

namespace App\Domain\Garage;

use App\Domain\Account\AccountFence;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/** packages/server/shared-garage.ts ve garage-recycle.ts. */
final class GarageRepository
{
    private function assertUid(string $uid): void
    {
        Z::string()->min(1)->max(128)->regex('/^[^\/]+$/')->parse($uid);
    }

    /** @return array{active:?stdClass,current:?array<string,mixed>} */
    private function context(string $uid): array
    {
        $this->assertUid($uid);
        $active = DB::table('garage_state')->where('user_id', $uid)->lockForUpdate()->first();
        $row = DB::table('garage_backups')->where('user_id', $uid)->lockForUpdate()->first();

        return ['active' => $active, 'current' => $row === null ? null : $this->backupFromRow($row)];
    }

    private function backupFromRow(stdClass $row): array
    {
        return GarageSchemas::backup()->parse((object) ['revision' => (int) $row->revision, 'updatedAt' => $row->updated_at_iso, 'operationId' => $row->operation_id, 'garage' => json_decode($row->garage, false, 512, JSON_THROW_ON_ERROR)]);
    }

    private function vehicleFromRow(stdClass $v): array
    {
        $out = ['brand' => $v->brand, 'model' => $v->model, 'year' => (int) $v->year, 'fuel' => $v->fuel, 'mileage' => (int) $v->mileage];
        if ($v->inspection_due_date !== null) {
            $out['inspectionDueDate'] = $v->inspection_due_date;
        }
        if ($v->photo_id !== null) {
            $out['photoId'] = $v->photo_id;
        }

        return $out + ['id' => $v->id, 'archived' => (bool) $v->archived];
    }

    private function recordFromRow(stdClass $r): array
    {
        $amount = (float) $r->amount;
        $out = ['vehicleId' => $r->vehicle_id, 'title' => $r->title, 'date' => $r->date, 'mileage' => (int) $r->mileage, 'amount' => $amount == floor($amount) ? (int) $amount : $amount, 'note' => $r->note];
        if ($r->attachment_ids !== null) {
            $out['attachmentIds'] = json_decode($r->attachment_ids, true, 8, JSON_THROW_ON_ERROR);
        }

        return $out + ['id' => $r->id];
    }

    private function legacy(string $uid): array
    {
        $vehicles = DB::table('user_vehicles')->where('user_id', $uid)->orderBy('id')->limit(51)->lockForUpdate()->get();
        $records = DB::table('user_maintenance')->where('user_id', $uid)->orderBy('id')->limit(2001)->lockForUpdate()->get();
        if ($vehicles->count() > 50 || $records->count() > 2000) {
            throw new RuntimeException('shared_capacity');
        }

        return GarageSchemas::accountGarage($vehicles->map(fn (stdClass $v) => $this->vehicleFromRow($v))->all(), $records->map(fn (stdClass $r) => $this->recordFromRow($r))->all());
    }

    private function putBackup(string $uid, array $backup): void
    {
        DB::table('garage_backups')->updateOrInsert(['user_id' => $uid], ['revision' => $backup['revision'], 'updated_at_iso' => $backup['updatedAt'], 'operation_id' => $backup['operationId'], 'garage' => Js::stringify($backup['garage'])]);
    }

    /** Hatirlatma islemcileri icin en cok 50 uyumluluk kaydi. Bakim kayitlari cogaltilmaz. */
    private function mirror(string $uid, array $backup, ?array $previous = null): void
    {
        $old = [];
        foreach ($previous['garage']['vehicles'] ?? [] as $v) {
            $old[$v['id']] = Js::stringify($v);
        }
        foreach ($backup['garage']['vehicles'] as $value) {
            if (($old[$value['id']] ?? null) === Js::stringify($value)) {
                continue;
            }
            DB::table('user_vehicles')->updateOrInsert(['user_id' => $uid, 'id' => $value['id']], ['brand' => $value['brand'], 'model' => $value['model'], 'year' => $value['year'], 'fuel' => $value['fuel'], 'mileage' => $value['mileage'], 'inspection_due_date' => $value['inspectionDueDate'] ?? null, 'archived' => $value['archived'], 'updated_at_iso' => $backup['updatedAt']]);
        }
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', Js::stringify($value));
    }

    /** @return array{garage:array<string,mixed>,shared:bool,revision:?int} */
    public function readAccountGarage(string $uid): array
    {
        return Tx::run(function () use ($uid): array {
            $c = $this->context($uid);
            if ($c['active'] !== null) {
                if ($c['current'] === null) {
                    throw new RuntimeException('shared_corrupt');
                }

                return ['garage' => $c['current']['garage'], 'shared' => true, 'revision' => $c['current']['revision']];
            }

            return ['garage' => $this->legacy($uid), 'shared' => false, 'revision' => null];
        });
    }

    public function preview(string $uid): array
    {
        return Tx::run(function () use ($uid): array {
            $c = $this->context($uid);
            if ($c['active'] !== null) {
                if ($c['current'] === null) {
                    throw new RuntimeException('shared_corrupt');
                }

                return ['active' => true, 'revision' => $c['current']['revision'], 'vehicles' => count($c['current']['garage']['vehicles']), 'records' => count($c['current']['garage']['records']), 'fingerprint' => $this->fingerprint($c['current']['garage'])];
            }
            $combined = GarageSchemas::combine($c['current']['garage'] ?? GarageSchemas::emptyGarage(), $this->legacy($uid), false);

            return ['active' => false, 'revision' => $c['current']['revision'] ?? 0, 'vehicles' => count($combined['vehicles']), 'records' => count($combined['records']), 'fingerprint' => $this->fingerprint($combined)];
        });
    }

    private function assertNotTrashed(string $uid): void
    {
        if (DB::table('garage_recycle')->where('user_id', $uid)->lockForUpdate()->value('state') === 'trashed') {
            throw new RuntimeException('shared_conflict');
        }
    }

    public function activate(string $uid, mixed $raw): array
    {
        $input = Z::strictObject([
            'expectedRevision' => Z::number()->int()->min(0)->max(1000000000),
            'operationId' => Z::string()->uuid(),
            'fingerprint' => Z::string()->regex('/^[a-f0-9]{64}$/'),
            'acknowledged' => Z::literal(true),
        ])->parse($raw);
        $now = Js::iso();

        return Tx::run(function () use ($uid, $input, $now): array {
            AccountFence::assertWritable($uid);
            $c = $this->context($uid);
            $this->assertNotTrashed($uid);
            if ($c['active'] !== null) {
                if ($c['active']->migration_id !== $input['operationId'] || $c['active']->migration_digest !== $input['fingerprint'] || (int) $c['active']->migration_revision !== $input['expectedRevision'] || $c['current'] === null) {
                    throw new RuntimeException('shared_conflict');
                }

                return ['revision' => $c['current']['revision'], 'replayed' => true];
            }
            if (($c['current']['revision'] ?? 0) !== $input['expectedRevision']) {
                throw new RuntimeException('shared_conflict');
            }
            $garage = GarageSchemas::combine($c['current']['garage'] ?? GarageSchemas::emptyGarage(), $this->legacy($uid), false);
            if ($this->fingerprint($garage) !== $input['fingerprint']) {
                throw new RuntimeException('shared_conflict');
            }
            $result = GarageSchemas::planBackup($c['current'], ['expectedRevision' => $input['expectedRevision'], 'operationId' => $input['operationId'], 'garage' => $garage], $now);
            $this->putBackup($uid, $result['backup']);
            DB::table('garage_state')->insert(['user_id' => $uid, 'migration_id' => $input['operationId'], 'migration_revision' => $input['expectedRevision'], 'migration_digest' => $input['fingerprint'], 'activated_at_iso' => $now, 'last_request_digest' => null]);
            $this->mirror($uid, $result['backup']);

            return ['revision' => $result['backup']['revision'], 'replayed' => false];
        });
    }

    /** @return array{value:array<string,mixed>,revision:?int} */
    public function writeAccountGarage(string $uid, string $resource, string $id, array $value, bool $update, bool $enabled, ?int $expectedRevision): array
    {
        if (! empty($value['photoId']) || ! empty($value['attachmentIds'])) {
            throw new RuntimeException('shared_media');
        }
        Z::string()->regex('/^[A-Za-z0-9_-]{1,100}$/')->parse($id);
        $operationId = (string) Str::uuid();
        $now = Js::iso();

        return Tx::run(function () use ($uid, $resource, $id, $value, $update, $enabled, $expectedRevision, $operationId, $now): array {
            AccountFence::assertWritable($uid);
            $c = $this->context($uid);
            if ($c['active'] !== null) {
                if (! $enabled) {
                    throw new RuntimeException('shared_disabled');
                }
                if ($c['current'] === null) {
                    throw new RuntimeException('shared_corrupt');
                }
                if ($expectedRevision !== $c['current']['revision']) {
                    throw new RuntimeException('shared_conflict');
                }
                $state = $c['current']['garage'];
                $list = $resource === 'vehicles' ? $state['vehicles'] : $state['records'];
                $existing = collect($list)->firstWhere('id', $id);
                if ($update ? $existing === null : $existing !== null) {
                    throw new RuntimeException('shared_conflict');
                }
                $next = $resource === 'vehicles' ? GarageSchemas::saveVehicle($state, $value, $id) : GarageSchemas::saveRecord($state, $value, $id);
                $plan = GarageSchemas::planBackup($c['current'], ['expectedRevision' => $c['current']['revision'], 'operationId' => $operationId, 'garage' => GarageSchemas::text($next)], $now);
                $this->putBackup($uid, $plan['backup']);
                DB::table('garage_state')->where('user_id', $uid)->update(['last_request_digest' => null]);
                $this->mirror($uid, $plan['backup'], $c['current']);
                $rows = $resource === 'vehicles' ? $plan['backup']['garage']['vehicles'] : $plan['backup']['garage']['records'];

                return ['value' => collect($rows)->firstWhere('id', $id), 'revision' => $plan['backup']['revision']];
            }

            return ['value' => $this->writeLegacy($uid, $resource, $id, $value, $update, $now), 'revision' => null];
        });
    }

    private function writeLegacy(string $uid, string $resource, string $id, array $value, bool $update, string $now): array
    {
        $table = $resource === 'vehicles' ? 'user_vehicles' : 'user_maintenance';
        $existing = DB::table($table)->where('user_id', $uid)->where('id', $id)->lockForUpdate()->first();
        if ($update ? $existing === null : $existing !== null) {
            throw new RuntimeException('shared_conflict');
        }
        if (! $update) {
            $cap = $resource === 'vehicles' ? 50 : 2000;
            if (DB::table($table)->where('user_id', $uid)->lockForUpdate()->count() >= $cap) {
                throw new RuntimeException('shared_capacity');
            }
        }
        if (array_key_exists('vehicleId', $value)) {
            $vehicle = DB::table('user_vehicles')->where('user_id', $uid)->where('id', $value['vehicleId'])->lockForUpdate()->first();
            if ($vehicle === null || $vehicle->archived) {
                throw new RuntimeException('shared_vehicle');
            }
        }
        $stamps = $existing !== null ? ['created_at_iso' => $existing->created_at_iso ?? $now, 'updated_at_iso' => $now] : ['created_at_iso' => $now, 'updated_at_iso' => null];
        $fields = $resource === 'vehicles'
            ? ['brand' => $value['brand'], 'model' => $value['model'], 'year' => $value['year'], 'fuel' => $value['fuel'], 'mileage' => $value['mileage'], 'inspection_due_date' => $value['inspectionDueDate'] ?? null, 'photo_id' => $value['photoId'] ?? null]
            : ['vehicle_id' => $value['vehicleId'], 'title' => $value['title'], 'date' => $value['date'], 'mileage' => $value['mileage'], 'amount' => $value['amount'], 'note' => $value['note'], 'attachment_ids' => array_key_exists('attachmentIds', $value) ? Js::stringify($value['attachmentIds']) : null];
        DB::table($table)->updateOrInsert(['user_id' => $uid, 'id' => $id], $fields + $stamps);

        return $value + ['id' => $id];
    }

    public function writeBackup(string $uid, mixed $raw, bool $enabled): array
    {
        $input = GarageSchemas::backupWrite()->parse($raw);
        $digest = hash('sha256', Js::stringify($input));
        $now = Js::iso();

        return Tx::run(function () use ($uid, $input, $digest, $enabled, $now): array {
            AccountFence::assertWritable($uid);
            $c = $this->context($uid);
            $this->assertNotTrashed($uid);
            if ($c['active'] !== null) {
                if (! $enabled) {
                    throw new RuntimeException('shared_disabled');
                }
                if ($c['current'] === null) {
                    throw new RuntimeException('shared_corrupt');
                }
                if ($c['current']['operationId'] === $input['operationId'] && $c['active']->last_request_digest !== $digest) {
                    throw new RuntimeException('shared_conflict');
                }
                $garage = GarageSchemas::combine($c['current']['garage'], $input['garage'], true);
                $result = GarageSchemas::planBackup($c['current'], array_merge($input, ['garage' => $garage]), $now);
            } else {
                $result = GarageSchemas::planBackup($c['current'], $input, $now);
            }
            if ($result['changed']) {
                $this->putBackup($uid, $result['backup']);
                if ($c['active'] !== null) {
                    DB::table('garage_state')->where('user_id', $uid)->update(['last_request_digest' => $digest]);
                    $this->mirror($uid, $result['backup'], $c['current']);
                }
            }

            return $result['backup'];
        });
    }

    public function readBackup(string $uid): ?array
    {
        $row = DB::table('garage_backups')->where('user_id', $uid)->first();

        return $row === null ? null : $this->backupFromRow($row);
    }

    public function manageRecycle(string $uid, ?array $input): array
    {
        $now = Js::iso();

        return Tx::run(function () use ($uid, $input, $now): array {
            if ($input !== null) {
                AccountFence::assertWritable($uid);
            }
            $this->assertUid($uid);
            $row = DB::table('garage_backups')->where('user_id', $uid)->lockForUpdate()->first();
            $t = DB::table('garage_recycle')->where('user_id', $uid)->lockForUpdate()->first();
            $shared = DB::table('garage_state')->where('user_id', $uid)->lockForUpdate()->exists();
            $backup = $row === null ? null : $this->backupFromRow($row);
            $recycle = $t === null ? null : $this->recycleFromRow($t);
            GarageRecycle::assertValid($recycle);
            if ($input !== null) {
                $plan = GarageRecycle::plan($backup, $recycle, $input, $shared, $now);
                if ($plan['changed']) {
                    $this->putBackup($uid, $plan['backup']);
                    $r = $plan['recycle'];
                    DB::table('garage_recycle')->updateOrInsert(['user_id' => $uid], ['state' => $r['state'], 'operation_id' => $r['operationId'], 'revision' => $r['revision'], 'changed_at_iso' => $r['changedAt'], 'purge_after_iso' => $r['purgeAfter'] ?? null, 'backup' => isset($r['backup']) ? Js::stringify($r['backup']) : null]);
                }
                $backup = $plan['backup'];
                $recycle = $plan['recycle'];
            }

            return GarageRecycle::status($backup, $recycle, $shared, $now);
        });
    }

    private function recycleFromRow(stdClass $t): array
    {
        $out = ['state' => $t->state, 'revision' => (int) $t->revision, 'operationId' => $t->operation_id, 'changedAt' => $t->changed_at_iso];
        if ($t->state === 'trashed') {
            $out['purgeAfter'] = $t->purge_after_iso;
            $out['backup'] = GarageSchemas::backup()->parse(json_decode($t->backup, false, 512, JSON_THROW_ON_ERROR));
        }

        return $out;
    }
}
