<?php

namespace App\Domain\Ads;

use App\Domain\Auth\Actor;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Exceptions\HttpError;
use App\Support\Query;
use Illuminate\Support\Facades\DB;
use Throwable;

/** /api/campaigns GET ve PATCH. Kampanya onayi tek basina stok ayirmaz, odeme almaz, yayin baslatmaz. */
final class Campaigns
{
    public static function list(Actor $u, Query $p): array
    {
        $bid = $p->get('businessId') ?? '';
        $queue = $p->get('queue') === 'true';
        $ownerView = $p->get('scope') === 'owner';
        $after = $p->get('after');
        $business = null;
        $q = DB::table('ad_campaigns')->orderBy('id')->limit(21);
        if ($queue) {
            if (! $u->admin) {
                throw new HttpError(403, 'Yönetici yetkisi gerekli.');
            }
            $q->where('status', $p->get('status') ?? 'pending');
        } else {
            ['b' => $b, 'm' => $m, 'exists' => $exists] = AdStore::business($u->uid, $bid, false);
            if (! $exists || ! CampaignRules::owns($u->uid, $bid, $b, $m)) {
                throw new HttpError(403, 'Reklam işlemleri için sahip yetkisi gerekli.');
            }
            if ($ownerView) {
                $business = CampaignRules::branch($bid, $b);
            }
            $q->where('business_id', $bid);
        }
        $lookup = $p->get('id');
        if ($lookup !== null && $lookup !== '') {
            $found = AdStore::campaign($lookup);
            if ($found !== null && ($found['businessId'] ?? null) !== $bid) {
                throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
            }
            $item = $found !== null ? CampaignRules::view($lookup, $found) : null;
            if ($found !== null && $item === null) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }

            // JSON.stringify `business: undefined` alanini atar.
            return ($business !== null ? ['business' => $business] : []) + ['items' => $item !== null ? [$item] : [], 'next' => null];
        }
        if ($after !== null && $after !== '') {
            $q->where('id', '>', $after);
        }
        $rows = $q->get()->all();
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $v = CampaignRules::view($row->id, AdStore::campaignDoc($row));
            if ($v !== null) {
                $items[] = $v;
            }
        }

        return ($ownerView ? ['business' => $business] : []) + ['items' => $items, 'next' => count($rows) > 20 ? $rows[19]->id : null];
    }

    /** @param array<string,mixed> $a campaignAction ciktisi */
    public static function act(Actor $u, array $a): array
    {
        $now = F7::nowMs();

        return FinanceBarrier::run($u->uid, ['kind' => 'ad_campaign', 'id' => $a['id'], 'businessId' => $a['businessId'], 'allowCreate' => $a['action'] === 'save' && $a['version'] === 0], static function () use ($u, $a, $now): array {
            $saved = AdStore::campaign($a['id'], true);
            ['b' => $b, 'm' => $m] = AdStore::business($u->uid, $a['businessId']);
            $owner = CampaignRules::owns($u->uid, $a['businessId'], $b, $m);
            $affiliated = ($m['active'] ?? null) === true;
            $reviewing = $a['action'] === 'approve' || $a['action'] === 'reject';
            if ($reviewing ? (! $u->admin || $affiliated) : ! $owner) {
                throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
            }
            $previous = $saved !== null ? CampaignRules::view($a['id'], $saved) : null;
            if ($saved !== null && $previous === null) {
                throw new HttpError(409, 'Kayıt değişti. Sayfayı yenile.');
            }
            try {
                $next = CampaignRules::transition($previous, $a, $b, ['owner' => $owner, 'admin' => $u->admin, 'affiliated' => $affiliated], $now);
            } catch (Throwable) {
                throw new HttpError(409, 'Kampanya değişti veya yayın koşulları sağlanmadı. Bilgileri kontrol et.');
            }
            if ($a['action'] === 'cancel') {
                AdInventory::release($a['id'], $now);
            }
            $ownerUid = $saved['ownerUid'] ?? $b['ownerUid'] ?? $u->uid;
            AdStore::putCampaign($a['id'], $next, $ownerUid, $saved !== null);
            AdStore::history($a['id'], $a['action'], $next['version'], $u->uid, $next['reason'], $next);

            return CampaignRules::view($a['id'], $next + ['ownerUid' => $ownerUid]);
        });
    }
}
