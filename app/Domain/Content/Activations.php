<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Domain\Catalogue\SearchProjection;
use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** /api/businesses/activation */
final class Activations
{
    private const NOT_ALLOWED = 'Yetkiniz yok.';

    /** @return array{items:list<array<string,mixed>>,next:?string} */
    public static function queue(string $status, ?string $after): array
    {
        $q = DB::table('feature_activations')->where('status', $status)->orderBy('business_id')->limit(21);
        if ($after !== null) {
            $q->where('business_id', '>', $after);
        }
        $list = $q->get()->all();
        $items = [];
        foreach (array_slice($list, 0, 20) as $s) {
            $b = Rows::business($s->business_id);
            $v = Docs::activation($s);
            $items[] = [
                'id' => $s->business_id,
                'name' => $b->name ?? $s->business_id,
                'version' => $b !== null ? (int) $b->activation_version : 0,
                'features' => $b !== null ? ['quotes' => (bool) $b->feature_quotes, 'appointments' => (bool) $b->feature_appointments] : ['quotes' => false, 'appointments' => false],
                'ownerReady' => $b !== null && (bool) $b->owner_ready,
                'request' => ['status' => $v['status']] + array_filter(['features' => $v['features'] ?? null, 'monitorInbox' => $v['monitorInbox'] ?? null, 'capacityReady' => $v['capacityReady'] ?? null], static fn (mixed $x): bool => $x !== null) + ['reason' => $v['reason'] ?? ''],
            ];
        }

        return ['items' => $items, 'next' => count($list) > 20 ? $list[19]->business_id : null];
    }

    /** @return array<string,mixed> */
    public static function view(Actor $u, string $bid): array
    {
        $b = Docs::business(Rows::business($bid));
        $m = Docs::membership(Rows::member($u->uid, $bid));
        if ($b === null || ! Policy::mayManageActivation($u->uid, $bid, $b, $m)) {
            throw new HttpError(403, self::NOT_ALLOWED);
        }
        $r = Docs::activation(DB::table('feature_activations')->where('business_id', $bid)->first());
        $value = Policy::projectActivation($u->uid, $bid, $b, $m, $r);
        if ($value === null) {
            throw new HttpError(409, 'Aktivasyon bilgileri incelenmeli.');
        }

        return $value;
    }

    /** @param array<string,mixed> $a activationAction ciktisi */
    public static function act(Actor $u, array $a): void
    {
        $bid = $a['businessId'];
        Barrier::business($u->uid, $bid, 'activation', static function () use ($u, $a, $bid): void {
            $bRow = Rows::business($bid, true);
            if ($bRow === null) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            $b = Docs::business($bRow);
            $m = Docs::membership(Rows::member($u->uid, $bid, true));
            $rs = DB::table('feature_activations')->where('business_id', $bid)->lockForUpdate()->first();
            $previous = Docs::activation($rs);
            $applicant = $a['action'] === 'approve' && isset($previous['applicantId']) ? Docs::membership(Rows::member($previous['applicantId'], $bid, true)) : null;
            try {
                $next = Policy::activationTransition($b, $previous, $a, [
                    'uid' => $u->uid,
                    'owner' => Policy::mayManageActivation($u->uid, $bid, $b, $m),
                    'admin' => $u->admin,
                    'affiliated' => ($b['ownerUid'] ?? null) === $u->uid || (($m['active'] ?? null) === true && ($m['businessId'] ?? null) === $bid),
                    'applicantStillOwner' => $previous !== null && isset($previous['applicantId']) && Policy::activationOwner($previous['applicantId'], $bid, $b, $applicant),
                ]);
            } catch (RuntimeException $e) {
                throw new HttpError($e->getMessage() === 'forbidden' ? 403 : 409, $e->getMessage() === 'forbidden' ? self::NOT_ALLOWED : 'Aktivasyon durumu değişti veya koşullar sağlanmıyor. Yenileyip tekrar dene.');
            }
            $update = ['activation_version' => $next['business']['activationVersion'], 'updated_at' => Ids::now()];
            if (array_key_exists('ownerReady', $next['business'])) {
                $update['owner_ready'] = $next['business']['ownerReady'];
            }
            if (array_key_exists('features', $next['business'])) {
                $update['feature_quotes'] = $next['business']['features']['quotes'];
                $update['feature_appointments'] = $next['business']['features']['appointments'];
            }
            DB::table('businesses')->where('id', $bid)->update($update);
            if ($a['action'] === 'request') {
                $value = ['applicant_id' => $u->uid, 'feature_quotes' => $a['features']['quotes'], 'feature_appointments' => $a['features']['appointments'], 'monitor_inbox' => true, 'capacity_ready' => true, 'reason' => ''];
                $details = ['applicantId' => $u->uid, 'features' => $a['features'], 'monitorInbox' => true, 'capacityReady' => true, 'reason' => ''];
            } else {
                $value = ['reason' => $a['reason']];
                $details = ['reason' => $a['reason']];
            }
            // tx.set(..., {merge:true}): yalniz yazilan alanlar degisir.
            $row = $value + ['status' => $next['status'], 'version' => $next['version'], 'updated_at' => Ids::now()];
            if ($rs !== null) {
                DB::table('feature_activations')->where('business_id', $bid)->update($row);
            } else {
                Rows::create('feature_activations', ['business_id' => $bid] + $row, static function (): never {
                    throw new HttpError(409, 'Aktivasyon durumu değişti veya koşullar sağlanmıyor. Yenileyip tekrar dene.');
                });
            }
            Rows::history('business_history', 'business_id', $bid, ['kind' => 'feature_activation', 'details' => Ids::stringify(['action' => $a['action'], 'actorId' => $u->uid, 'version' => $next['version']] + $details), 'created_at' => Ids::now()]);
            if (array_key_exists('ownerReady', $next['business']) || array_key_exists('features', $next['business'])) {
                SearchProjection::sync($bid);
            }
        });
    }
}
