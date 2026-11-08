<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Account\AccountFence;
use App\Domain\Auth\ActorResolver;
use App\Domain\Garage\GarageRepository;
use App\Domain\Garage\GarageSchemas;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Js;
use App\Support\RateLimit;
use App\Support\Tx;
use App\Support\Z;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final class MeController
{
    use Guards;

    public function __construct(private readonly GarageRepository $garage) {}

    private const ID = '/^[a-zA-Z0-9_-]{1,120}$/';

    private function garageError(RuntimeException $e): never
    {
        if ($e->getMessage() === 'shared_disabled') {
            throw new HttpError(503, 'Ortak garaj geçişi henüz etkin değil.');
        }
        if (preg_match('/^(shared_|transfer_|garage_vehicle)/', $e->getMessage()) === 1) {
            throw new HttpError(409, 'Garaj kayıtları değişti veya birleştirilemiyor. Önizlemeyi yeniden yükle.');
        }
        throw $e;
    }

    private function revisionHeaders(?int $revision): array
    {
        return Api::PRIVATE + ($revision !== null ? ['X-Garage-Revision' => (string) $revision] : []);
    }

    private function timestamp(string $value): array
    {
        $at = CarbonImmutable::parse($value, 'UTC');

        return ['_seconds' => $at->getTimestamp(), '_nanoseconds' => (int) $at->format('v') * 1000000];
    }

    private function reminderJson(stdClass $r): array
    {
        $out = ['vehicleId' => $r->vehicle_id, 'title' => $r->title];
        if ($r->completed !== null) {
            $out['completed'] = (bool) $r->completed;
        }
        if ($r->due_date !== null) {
            $out['dueDate'] = $r->due_date;
        }
        if ($r->due_km !== null) {
            $out['dueKm'] = (int) $r->due_km;
        }
        if ($r->channels_set) {
            $out['channels'] = array_values(array_filter([$r->channel_push ? 'push' : null, $r->channel_email ? 'email' : null]));
        }
        if ($r->advance_days !== null) {
            $out['advanceDays'] = (int) $r->advance_days;
        }
        if ($r->next_check_at_ms !== null) {
            $out['nextCheckAtMs'] = (int) $r->next_check_at_ms;
        }
        $out['createdAt'] = $this->timestamp($r->created_at);
        if ($r->updated_at !== null) {
            $out['updatedAt'] = $this->timestamp($r->updated_at);
        }

        return $out + ['id' => $r->id];
    }

    /** reminderSchedule(): yalniz planlar, dis kanal cagirmaz. */
    private function reminderSchedule(array $v, int $now): array
    {
        if (! empty($v['completed'])) {
            return [];
        }
        $hasKm = array_key_exists('dueKm', $v);
        $events = false;
        $future = [];
        if (! empty($v['dueDate'])) {
            $due = Js::ms($v['dueDate'].'T09:00:00+03:00');
            $advance = $due - ($v['advanceDays'] ?? 0) * 86400000;
            if ($due <= $now) {
                $events = true;
            } else {
                $future[] = $due;
                if ($advance < $due) {
                    if ($advance <= $now) {
                        $events = true;
                    } else {
                        $future[] = $advance;
                    }
                }
            }
        }
        if ($hasKm) {
            $future[] = $now + 86400000; // mileage undefined: plan yalniz gelecege yazar.
        }

        return ['next_check_at_ms' => ($events || $hasKm) ? $now : ($future !== [] ? min($future) : $now)];
    }

    private function reminderColumns(array $v): array
    {
        $channels = $v['channels'] ?? null;

        return ['vehicle_id' => $v['vehicleId'], 'title' => $v['title'], 'completed' => $v['completed'] ?? null, 'due_date' => $v['dueDate'] ?? null, 'due_km' => $v['dueKm'] ?? null, 'channels_set' => $channels !== null, 'channel_push' => in_array('push', $channels ?? [], true), 'channel_email' => in_array('email', $channels ?? [], true), 'advance_days' => $v['advanceDays'] ?? null];
    }

    private function assertVehicle(string $uid, string $vehicleId): void
    {
        $vehicle = DB::table('user_vehicles')->where('user_id', $uid)->where('id', $vehicleId)->lockForUpdate()->first();
        if ($vehicle === null || $vehicle->archived) {
            throw new HttpError(404, 'Araç bulunamadı.');
        }
    }

    public function show(Request $request): JsonResponse
    {
        $u = ActorResolver::resolve($request);
        RateLimit::consume($u->uid);
        $garage = $this->garage->readAccountGarage($u->uid);
        $reminders = DB::table('user_reminders')->where('user_id', $u->uid)->orderBy('id')->limit(200)->get();
        $favorites = DB::table('user_favorites')->where('user_id', $u->uid)->orderBy('business_id')->limit(200)->pluck('business_id')->all();

        return Api::json(['vehicles' => $garage['garage']['vehicles'], 'maintenance' => $garage['garage']['records'], 'garageShared' => $garage['shared'], 'garageRevision' => $garage['revision'], 'reminders' => $reminders->map(fn (stdClass $r) => $this->reminderJson($r))->all(), 'favorites' => $favorites]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->verified($request);
        $input = Z::strictObject(['resource' => Z::enum(['vehicles', 'maintenance', 'reminders', 'favorites']), 'value' => Z::any(), 'expectedGarageRevision' => Z::number()->int()->positive()->max(1000000001)->optional()])->parse(Body::json($request));
        if ($input['resource'] === 'favorites') {
            $v = Z::strictObject(['businessId' => Z::string()->regex(self::ID), 'adding' => Z::boolean()])->parse($input['value'] ?? null);
            Tx::run(function () use ($u, $v): void {
                AccountFence::assertWritable($u->uid);
                if ($v['adding']) {
                    $published = DB::table('businesses')->where('id', $v['businessId'])->sharedLock()->value('published');
                    if (! $published) {
                        throw new HttpError(404, 'İşletme bulunamadı.');
                    }
                    DB::table('user_favorites')->upsert([['user_id' => $u->uid, 'business_id' => $v['businessId']]], ['user_id', 'business_id'], ['user_id']);
                } else {
                    DB::table('user_favorites')->where('user_id', $u->uid)->where('business_id', $v['businessId'])->delete();
                }
            });

            return Api::json(['ok' => true]);
        }
        $schema = ['vehicles' => GarageSchemas::vehicle(...), 'maintenance' => GarageSchemas::maintenance(...), 'reminders' => GarageSchemas::reminder(...)][$input['resource']]();
        $value = $schema->parse($input['value'] ?? null);
        if (! empty($value['photoId']) || ! empty($value['attachmentIds'])) {
            throw new HttpError(503, 'Özel medya saklama hizmeti henüz etkin değil.');
        }
        if ($input['resource'] === 'vehicles' || $input['resource'] === 'maintenance') {
            try {
                $saved = $this->garage->writeAccountGarage($u->uid, $input['resource'], Str::random(20), $value, false, config('otosanayide.flags.shared_garage'), $input['expectedGarageRevision'] ?? null);
            } catch (RuntimeException $e) {
                $this->garageError($e);
            }

            return Api::json($saved['value'], 201, $this->revisionHeaders($saved['revision']));
        }
        $id = Str::random(20);
        Tx::run(function () use ($u, $value, $id): void {
            AccountFence::assertWritable($u->uid);
            $this->assertVehicle($u->uid, $value['vehicleId']);
            DB::table('user_reminders')->insert(['user_id' => $u->uid, 'id' => $id] + $this->reminderColumns($value) + $this->reminderSchedule($value, Js::nowMs()));
        });

        return Api::json($value + ['id' => $id], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $u = $this->verified($request);
        $idZ = Z::string()->regex(self::ID);
        $input = Z::discriminatedUnion('resource', [
            'vehicles' => Z::strictObject(['resource' => Z::literal('vehicles'), 'id' => $idZ, 'value' => GarageSchemas::vehicle(), 'expectedGarageRevision' => Z::number()->int()->positive()->max(1000000001)->optional()]),
            'reminders' => Z::strictObject(['resource' => Z::literal('reminders'), 'id' => $idZ, 'value' => GarageSchemas::reminder()]),
        ])->parse(Body::json($request));
        if ($input['resource'] === 'vehicles' && ! empty($input['value']['photoId'])) {
            throw new HttpError(503, 'Özel medya saklama hizmeti henüz etkin değil.');
        }
        if ($input['resource'] === 'vehicles') {
            try {
                $saved = $this->garage->writeAccountGarage($u->uid, 'vehicles', $input['id'], $input['value'], true, config('otosanayide.flags.shared_garage'), $input['expectedGarageRevision'] ?? null);
            } catch (RuntimeException $e) {
                $this->garageError($e);
            }

            return Api::json($saved['value'], 200, $this->revisionHeaders($saved['revision']));
        }
        Tx::run(function () use ($u, $input): void {
            AccountFence::assertWritable($u->uid);
            $existing = DB::table('user_reminders')->where('user_id', $u->uid)->where('id', $input['id'])->lockForUpdate()->first();
            if ($existing === null) {
                throw new HttpError(404, 'Kayıt bulunamadı.');
            }
            if ($existing->vehicle_id !== $input['value']['vehicleId']) {
                throw new HttpError(400, 'Hatırlatmanın bağlı olduğu araç değiştirilemez.');
            }
            $this->assertVehicle($u->uid, $input['value']['vehicleId']);
            DB::table('user_reminders')->where('user_id', $u->uid)->where('id', $input['id'])->update($this->reminderColumns($input['value']) + ['next_check_at_ms' => null] + $this->reminderSchedule($input['value'], Js::nowMs()) + ['updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v')]);
        });

        return Api::json($input['value'] + ['id' => $input['id']]);
    }
}
