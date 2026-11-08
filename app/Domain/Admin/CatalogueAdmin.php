<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Content\Barrier;
use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\Rows;
use App\Exceptions\HttpError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/** /api/admin/catalogue, /draft, /history */
final class CatalogueAdmin
{
    /** @return array{uid:string,admin:bool,affiliated:bool} */
    private static function who(Actor $u, ?array $member): array
    {
        return ['uid' => $u->uid, 'admin' => true, 'affiliated' => ($member['active'] ?? null) === true];
    }

    /** @return array{items:list<array<string,mixed>>,next:?string} */
    public static function page(bool $published, ?string $after): array
    {
        $q = DB::table('businesses')->where('published', $published)->orderBy('id')->limit(21);
        if ($after !== null) {
            $q->where('id', '>', $after);
        }
        $rows = $q->get()->all();
        $items = array_map(static function (stdClass $row): array {
            $b = Docs::business($row);

            return ['id' => $row->id, 'name' => (string) ($b['name'] ?? ''), 'city' => (string) ($b['city'] ?? ''), 'district' => (string) ($b['district'] ?? ''), 'address' => (string) ($b['address'] ?? ''), 'phone' => (string) ($b['phone'] ?? ''), 'website' => (string) ($b['website'] ?? ''), 'categories' => $b['categories'], 'services' => $b['services'], 'latitude' => $b['latitude'] ?? null, 'longitude' => $b['longitude'] ?? null, 'published' => $b['published'] === true, 'version' => $b['publicationVersion'] ?? 0, 'profileVersion' => $b['profileVersion'] ?? 0];
        }, array_slice($rows, 0, 20));

        return ['items' => $items, 'next' => count($rows) > 20 ? $rows[19]->id : null];
    }

    /** @param array<string,mixed> $input publicationAction ciktisi */
    public static function publication(Actor $u, array $input): void
    {
        $id = $input['businessId'];
        Barrier::business($u->uid, $id, 'business', static function () use ($u, $input, $id): void {
            $b = Docs::business(Rows::business($id, true));
            $member = Docs::membership(Rows::member($u->uid, $id, true));
            if ($b === null) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            try {
                $next = AdminPolicy::publicationTransition($b, $input, self::who($u, $member));
            } catch (Throwable) {
                throw new HttpError(409, 'Yayın koşulları sağlanmadı veya kayıt değişti. Bilgileri yeniden kontrol et.');
            }
            $update = ['published' => $next['published'], 'publication_version' => $next['publicationVersion'], 'owner_ready' => false, 'feature_quotes' => false, 'feature_appointments' => false, 'activation_version' => $next['activationVersion'], 'updated_at' => Ids::now()];
            if (array_key_exists('coordinateStatus', $next)) {
                $update['coordinate_status'] = $next['coordinateStatus'];
            }
            DB::table('businesses')->where('id', $id)->update($update);
            Rows::history('business_history', 'business_id', $id, ['kind' => 'catalogue_publication', 'details' => Ids::stringify(['action' => $input['action'], 'reason' => $input['reason'], 'checks' => $input['action'] === 'publish' ? $input['checks'] : null, 'actorId' => $u->uid, 'version' => $next['publicationVersion'], 'profileVersion' => $input['profileVersion']]), 'created_at' => Ids::now()]);
            SearchProjection::sync($id);
        });
    }

    /** catalogueSources.source belgesinin karsiligi. */
    private static function sourceDocument(string $sourceId, string $businessId): ?array
    {
        $row = DB::table('catalogue_sources')->where('id', $sourceId)->first();
        if ($row === null || $row->business_id !== $businessId) {
            return null;
        }
        $values = DB::table('catalogue_source_values')->where('source_id', $sourceId)->orderBy('position')->get()->groupBy('kind');
        $list = static fn (string $kind): array => collect($values[$kind] ?? [])->pluck('value')->all();
        $extra = $row->extra === null ? [] : json_decode((string) $row->extra, true);

        return (is_array($extra) ? $extra : []) + [
            'name' => $row->name, 'address' => $row->address, 'phone' => $row->phone, 'contactEmail' => $row->contact_email, 'website' => $row->website,
            'categoryCandidates' => $list('category'), 'specialtyCandidates' => $list('specialty'), 'flags' => $list('flag'),
            'rawSource' => json_decode((string) $row->raw_source, true),
        ];
    }

    /** @return array<string,mixed> */
    public static function draft(Actor $u, string $id): array
    {
        $b = Docs::business(Rows::business($id));
        $member = Docs::membership(Rows::member($u->uid, $id));
        if ($b === null) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }
        if (! AdminPolicy::mayPrepareCatalogue($b, self::who($u, $member))) {
            throw new HttpError(403, 'Bu kayıt taslak düzenlemeye uygun değil.');
        }
        $sourceId = is_string($b['importSourceId'] ?? null) && preg_match('/^[a-f0-9]{24}$/D', $b['importSourceId']) === 1 ? $b['importSourceId'] : null;
        $evidence = $sourceId !== null ? self::sourceDocument($sourceId, $id) : null;
        $profile = ['name' => (string) ($b['name'] ?? ''), 'city' => (string) ($b['city'] ?? ''), 'district' => (string) ($b['district'] ?? ''), 'address' => (string) ($b['address'] ?? ''), 'phone' => (string) ($b['phone'] ?? '')];
        if (is_string($b['contactEmail'] ?? null)) {
            $profile['contactEmail'] = $b['contactEmail'];
        }
        $profile += ['website' => (string) ($b['website'] ?? ''), 'hours' => (string) ($b['hours'] ?? ''), 'description' => (string) ($b['description'] ?? ''), 'categories' => $b['categories'], 'services' => $b['services'], 'brands' => $b['brands'], 'location' => isset($b['latitude'], $b['longitude']) ? ['latitude' => $b['latitude'], 'longitude' => $b['longitude']] : null];

        return ['profile' => $profile, 'profileVersion' => $b['profileVersion'] ?? 0, 'publicationVersion' => $b['publicationVersion'] ?? 0, 'evidence' => AdminPolicy::catalogueEvidenceProjection($evidence)];
    }

    /** @param array<string,mixed> $input catalogueDraft ciktisi */
    public static function saveDraft(Actor $u, array $input): void
    {
        $id = $input['businessId'];
        Barrier::business($u->uid, $id, 'business', static function () use ($u, $input, $id): void {
            $b = Docs::business(Rows::business($id, true));
            $member = Docs::membership(Rows::member($u->uid, $id, true));
            if ($b === null) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            try {
                $next = AdminPolicy::catalogueDraftTransition($b, $input, self::who($u, $member));
            } catch (Throwable) {
                throw new HttpError(409, 'Kayıt değişti veya düzenleme yetkisi yok. Yeniden yükle.');
            }
            DB::table('businesses')->where('id', $id)->update(['profile_version' => $next['profileVersion'], 'owner_ready' => false, 'feature_quotes' => false, 'feature_appointments' => false, 'activation_version' => $next['activationVersion'], 'updated_at' => Ids::now()] + BusinessRecords::profileColumns($next));
            BusinessRecords::writeValues($id, $next);
            Rows::history('business_history', 'business_id', $id, ['kind' => 'catalogue_draft', 'details' => Ids::stringify(['reason' => $input['reason'], 'actorId' => $u->uid, 'profileVersion' => $next['profileVersion']]), 'created_at' => Ids::now()]);
            SearchProjection::sync($id);
        });
    }

    /** @return array{items:list<array<string,mixed>>,next:?string} */
    public static function history(string $businessId, ?string $after): array
    {
        if (Rows::business($businessId) === null) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }
        $q = DB::table('business_history')->where('business_id', $businessId)->orderByDesc('created_at')->orderByDesc('id')->limit(21);
        if ($after !== null) {
            $cursor = DB::table('business_history')->where('business_id', $businessId)->where('id', $after)->first();
            if ($cursor === null) {
                throw new HttpError(400, 'Geçmiş sayfası değişti. İlk sayfadan yeniden başla.');
            }
            $q->where(static function ($w) use ($cursor): void {
                $w->where('created_at', '<', $cursor->created_at)->orWhere(static function ($x) use ($cursor): void {
                    $x->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id);
                });
            });
        }
        $rows = $q->get()->all();
        $items = array_map(static function (stdClass $d): array {
            $details = $d->details === null ? [] : json_decode((string) $d->details, true);
            $iso = CarbonImmutable::parse($d->created_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z');

            return AdminPolicy::catalogueHistoryProjection($d->id, ['kind' => $d->kind] + (is_array($details) ? $details : []), $iso);
        }, array_slice($rows, 0, 20));

        return ['items' => $items, 'next' => count($rows) > 20 ? $rows[19]->id : null];
    }
}
