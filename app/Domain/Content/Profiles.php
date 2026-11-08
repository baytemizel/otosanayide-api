<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;

/** /api/businesses/manage: dogrudan yayin yok, yalniz bekleyen revizyon. */
final class Profiles
{
    public static function one(Actor $u, string $selected): array
    {
        $memberRow = Rows::member($u->uid, $selected);
        $b = Docs::business(Rows::business($selected));
        $m = Docs::membership($memberRow);
        if ($memberRow === null || $b === null || ($m['businessId'] ?? null) !== $selected || ! Policy::mayEditOwnedProfile($u->uid, $b, $m) || ($b['sample'] ?? null) === true) {
            throw new HttpError(403, 'Bu işletmenin profilini düzenleyemezsin.');
        }
        $change = Docs::change(DB::table('business_changes')->where('business_id', $selected)->first());
        $value = Policy::projectManagedProfile($u->uid, $u->uid.'_'.$selected, $m, $b, $change);
        if ($value === null) {
            throw new HttpError(409, 'Profil verileri incelenmeli.');
        }

        return ['businesses' => [$value]];
    }

    public static function all(Actor $u): array
    {
        $members = DB::table('business_memberships')->where('user_id', $u->uid)->orderBy('business_id')->limit(100)->get();
        $out = [];
        foreach ($members as $row) {
            $m = Docs::membership($row);
            if (($m['active'] ?? null) !== true || ! in_array($m['role'] ?? null, ['owner', 'staff'], true)) {
                continue;
            }
            $id = $m['businessId'];
            if (preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $id) !== 1) {
                continue;
            }
            $b = Docs::business(Rows::business($id));
            if ($b === null || ! Policy::mayEditOwnedProfile($u->uid, $b, $m)) {
                continue;
            }
            $draft = Docs::change(DB::table('business_changes')->where('business_id', $id)->first());
            $value = ['id' => $id, 'role' => $m['role'], 'name' => $b['name'], 'city' => $b['city'], 'district' => $b['district'], 'address' => $b['address'], 'phone' => $b['phone'] ?? ''];
            if (is_string($b['contactEmail'] ?? null)) {
                $value['contactEmail'] = $b['contactEmail'];
            }
            $out[] = $value + [
                'website' => $b['website'] ?? '',
                'hours' => $b['hours'] ?? '',
                'description' => $b['description'] ?? '',
                'categories' => $b['categories'],
                'services' => $b['services'],
                'brands' => $b['brands'],
                'location' => isset($b['latitude'], $b['longitude']) ? ['latitude' => $b['latitude'], 'longitude' => $b['longitude']] : null,
                'version' => $b['profileVersion'] ?? 0,
                'change' => $draft === null ? null : ['revisionId' => $draft['revisionId'], 'status' => $draft['status'], 'profile' => $draft['profile'], 'reason' => $draft['reason'] ?? ''],
            ];
        }

        return ['businesses' => $out];
    }

    /**
     * @param  array{businessId:string,expectedVersion:int,expectedRevisionId:?string,profile:array<string,mixed>}  $input
     * @return array{revisionId:string,status:string}
     */
    public static function submit(Actor $u, array $input): array
    {
        $revisionId = Ids::uuid();
        $id = $input['businessId'];
        Barrier::business($u->uid, $id, 'profile', static function () use ($u, $input, $id, $revisionId): void {
            $bRow = Rows::business($id, true);
            $b = Docs::business($bRow);
            $m = Docs::membership(Rows::member($u->uid, $id, true));
            $previous = DB::table('business_changes')->where('business_id', $id)->lockForUpdate()->first();
            if ($b === null || ($m['businessId'] ?? null) !== $id || ! Policy::mayEditOwnedProfile($u->uid, $b, $m) || ($b['sample'] ?? null) === true) {
                throw new HttpError(403, 'Bu işletmenin profilini düzenleyemezsin.');
            }
            if (($previous->revision_id ?? null) !== $input['expectedRevisionId']) {
                throw new HttpError(409, 'Bekleyen değişiklik güncellendi. Sayfayı yenile.');
            }
            if (($b['profileVersion'] ?? 0) !== $input['expectedVersion']) {
                throw new HttpError(409, 'Profil değişti. Güncel bilgileri yükleyip yeniden dene.');
            }
            $p = $input['profile'];
            $row = [
                'revision_id' => $revisionId, 'expected_revision_id' => $input['expectedRevisionId'], 'expected_version' => $input['expectedVersion'],
                'user_id' => $u->uid, 'status' => 'pending',
                'profile_name' => $p['name'], 'profile_city' => $p['city'], 'profile_district' => $p['district'], 'profile_address' => $p['address'],
                'profile_phone' => $p['phone'], 'profile_website' => $p['website'], 'profile_contact_email' => $p['contactEmail'] ?? null,
                'profile_hours' => $p['hours'], 'profile_description' => $p['description'],
                'profile_latitude' => $p['location']['latitude'] ?? null, 'profile_longitude' => $p['location']['longitude'] ?? null,
                'reason' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => Ids::now(),
            ];
            // tx.set: tek belge tamamen degisir.
            if ($previous !== null) {
                DB::table('business_changes')->where('business_id', $id)->update($row);
                DB::table('business_change_values')->where('business_id', $id)->delete();
            } else {
                Rows::create('business_changes', ['business_id' => $id] + $row, static function (): never {
                    throw new HttpError(409, 'Bekleyen değişiklik güncellendi. Sayfayı yenile.');
                });
            }
            foreach (['category' => $p['categories'], 'service' => $p['services'], 'brand' => $p['brands']] as $kind => $values) {
                foreach (array_values($values) as $position => $value) {
                    DB::table('business_change_values')->insert(['business_id' => $id, 'kind' => $kind, 'position' => $position, 'value' => $value]);
                }
            }
            Rows::history('business_history', 'business_id', $id, ['kind' => 'profile_submitted', 'details' => Ids::stringify(['revisionId' => $revisionId, 'profile' => $p, 'expectedVersion' => $input['expectedVersion'], 'actorId' => $u->uid]), 'created_at' => Ids::now()]);
        });

        return ['revisionId' => $revisionId, 'status' => 'pending'];
    }
}
