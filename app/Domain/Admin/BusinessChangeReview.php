<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Content\Barrier;
use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;

/** /api/admin/business-changes */
final class BusinessChangeReview
{
    /** @return array{changes:list<array<string,mixed>>} */
    public static function pending(bool $v2 = false): array
    {
        $rows = DB::table('business_changes')->where('status', 'pending')->orderBy('business_id')->limit(50)->get();
        $changes = [];
        foreach ($rows as $row) {
            $c = Docs::change($row);
            $v = Docs::business(Rows::business($row->business_id));
            $changes[] = [
                'id' => $row->business_id,
                'revisionId' => $c['revisionId'],
                'profile' => $c['profile'],
                ...($v2 ? \App\Domain\Content\Profiles::hoursView($row->business_id, $row) : []),
                'current' => $v === null ? null : ['name' => $v['name'] ?? null, 'city' => $v['city'] ?? null, 'district' => $v['district'] ?? null, 'address' => $v['address'] ?? null, 'phone' => $v['phone'] ?? '', 'website' => $v['website'] ?? '', 'hours' => $v['hours'] ?? '', 'description' => $v['description'] ?? '', 'categories' => $v['categories'], 'services' => $v['services'], 'brands' => $v['brands'], 'location' => isset($v['latitude'], $v['longitude']) ? ['latitude' => $v['latitude'], 'longitude' => $v['longitude']] : null],
            ];
        }

        return ['changes' => $changes];
    }

    /** @param array<string,mixed> $input profileDecision ciktisi */
    public static function decide(Actor $u, array $input): void
    {
        $id = $input['businessId'];
        Barrier::business($u->uid, $id, 'profile', static function () use ($u, $input, $id): void {
            $row = DB::table('business_changes')->where('business_id', $id)->lockForUpdate()->first();
            $bRow = Rows::business($id, true);
            if ($row === null || $row->status !== 'pending' || $row->revision_id !== $input['revisionId'] || $bRow === null) {
                throw new HttpError(409, 'İnceleme değişti veya sonuçlandı.');
            }
            $d = Docs::change($row);
            if ($d['userId'] === $u->uid) {
                throw new HttpError(403, 'Kendi değişikliğini değerlendiremezsin.');
            }
            $b = Docs::business($bRow);
            $m = Docs::membership(Rows::member($d['userId'], $id, true));
            if ($input['decision'] === 'approve' && (($m['businessId'] ?? null) !== $id || ! Policy::mayEditOwnedProfile($d['userId'], $b, $m))) {
                throw new HttpError(409, 'İşletme yetkisi değişmiş.');
            }
            if ($input['decision'] === 'approve' && ($b['profileVersion'] ?? 0) !== $d['expectedVersion']) {
                throw new HttpError(409, 'Profil sürümü değişmiş.');
            }
            if ($input['decision'] === 'approve') {
                $profile = Schemas::businessProfile()->parse(json_decode(Ids::stringify($d['profile'])));
                $projection = AdminPolicy::profileProjection($profile, $b);
                DB::table('businesses')->where('id', $id)->update(['profile_version' => $d['expectedVersion'] + 1, 'updated_at' => Ids::now()] + BusinessRecords::profileColumns($projection));
                BusinessRecords::writeValues($id, $projection);
                // portal-04: saat eylemi profil onayiyla birlikte uygulanir.
                if (($row->opening_hours_action ?? 'keep') === 'set') {
                    $hours = json_decode((string) $row->opening_hours, true) + ['updatedAtMs' => (int) floor(microtime(true) * 1000)];
                    DB::table('businesses')->where('id', $id)->update(['opening_hours' => json_encode($hours)]);
                } elseif (($row->opening_hours_action ?? 'keep') === 'clear') {
                    DB::table('businesses')->where('id', $id)->update(['opening_hours' => null]);
                }
            }
            DB::table('business_changes')->where('business_id', $id)->update(['status' => $input['decision'] === 'approve' ? 'approved' : 'rejected', 'reason' => $input['reason'], 'reviewed_by' => $u->uid, 'reviewed_at' => Ids::now()]);
            Rows::history('business_history', 'business_id', $id, ['kind' => 'profile_reviewed', 'details' => Ids::stringify(['decision' => $input['decision'], 'revisionId' => $input['revisionId'], 'reason' => $input['reason'], 'actorId' => $u->uid]), 'created_at' => Ids::now()]);
            if ($input['decision'] === 'approve') {
                SearchProjection::sync($id);
            }
        });
    }
}
