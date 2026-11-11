<?php

namespace App\Domain\Content;

use App\Domain\Account\AccountFence;
use App\Domain\Account\Membership;
use App\Domain\Auth\Actor;
use App\Domain\Catalogue\BusinessReader;
use App\Exceptions\HttpError;
use App\Jobs\SendAuthEmail;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * portal-01 (muhafazakar): rol modeli business_memberships (owner/staff) aynen. Davet yalniz `staff` verir;
 * sahiplik, finans veya reklam satin alma yetkisi davetle verilmez. Sahiplik devri ve rol yukseltme YOK.
 * Yalniz dogrulanmis sahip (aktif owner, isletme sahipligi dogrulanmis, ornek degil) yazar; staff yalniz okur.
 * Her yazim business_team_audit'e islenir.
 */
final class BusinessTeam
{
    public const INVITE_TTL_MS = 7 * 86400000;

    private static function business(string $businessId): array
    {
        $b = BusinessReader::records([$businessId])[$businessId] ?? null;
        if ($b === null) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }

        return $b;
    }

    private static function owner(Actor $u, string $businessId, bool $lock = false): void
    {
        $b = self::business($businessId);
        $m = Membership::find($u->uid, $businessId, $lock);
        if (! $u->emailVerified || ! Membership::isMember($u->uid, $m) || $m->role !== 'owner' || $b['ownership'] !== 'verified' || $b['sample'] === true) {
            throw new HttpError(403, 'Bu işlem için işletme sahibi yetkisi gerekli.');
        }
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public static function read(Actor $u, string $businessId): array
    {
        $me = Membership::require($u->uid, $businessId);
        $owner = $me->role === 'owner';
        $rows = DB::table('business_memberships as m')->leftJoin('auth_accounts as a', 'a.id', '=', 'm.user_id')
            ->where('m.business_id', $businessId)->orderBy('m.role')->orderBy('m.user_id')->limit(100)
            ->get(['m.user_id', 'm.role', 'm.active', 'm.version', 'm.created_at', 'a.email']);
        $members = $rows->map(fn (stdClass $r) => [
            'memberId' => $r->user_id, 'email' => $r->email === null ? null : ($owner || $r->user_id === $u->uid ? $r->email : self::mask($r->email)),
            'role' => $r->role, 'status' => (bool) $r->active ? 'active' : 'suspended', 'version' => (int) $r->version, 'you' => $r->user_id === $u->uid,
        ])->all();
        $invitations = $owner ? DB::table('business_invitations')->where('business_id', $businessId)->where('status', 'pending')->where('expires_at_ms', '>', Js::nowMs())
            ->orderBy('created_at_ms')->limit(50)->get()->map(fn (stdClass $i) => ['invitationId' => $i->id, 'email' => $i->email, 'role' => 'staff', 'expiresAtMs' => (int) $i->expires_at_ms, 'createdAtMs' => (int) $i->created_at_ms])->all() : [];

        return ['businessId' => $businessId, 'yourRole' => $me->role, 'members' => $members, 'invitations' => $invitations];
    }

    public static function invite(Actor $u, string $businessId, string $email, string $linkOrigin): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $out = Tx::run(static function () use ($u, $businessId, $email, $raw): array {
            AccountFence::assertWritable($u->uid);
            self::owner($u, $businessId, true);
            if ($email === $u->email) {
                throw new HttpError(400, 'Kendini davet edemezsin.');
            }
            $now = Js::nowMs();
            DB::table('business_invitations')->where('business_id', $businessId)->where('status', 'pending')->where('expires_at_ms', '<=', $now)->update(['status' => 'expired', 'updated_at_ms' => $now]);
            if (DB::table('business_invitations')->where('business_id', $businessId)->where('email', $email)->where('status', 'pending')->lockForUpdate()->exists()) {
                throw new HttpError(409, 'Bu adrese açık bir davet var.');
            }
            $memberUid = DB::table('auth_accounts')->where('email', $email)->value('id');
            if (is_string($memberUid) && DB::table('business_memberships')->where('business_id', $businessId)->where('user_id', $memberUid)->exists()) {
                throw new HttpError(409, 'Bu kişi zaten ekipte.');
            }
            if (DB::table('business_invitations')->where('business_id', $businessId)->where('status', 'pending')->count() >= 20) {
                throw new HttpError(409, 'En fazla 20 açık davet olabilir.');
            }
            $id = (string) Str::uuid();
            DB::table('business_invitations')->insert(['id' => $id, 'business_id' => $businessId, 'email' => $email, 'token_hash' => hash('sha256', $raw), 'status' => 'pending', 'invited_by' => $u->uid, 'expires_at_ms' => $now + self::INVITE_TTL_MS, 'created_at_ms' => $now, 'updated_at_ms' => $now]);
            self::audit($businessId, 'invite', $u->uid, self::mask($email));

            return ['invitationId' => $id, 'email' => $email, 'role' => 'staff', 'status' => 'pending', 'expiresAtMs' => $now + self::INVITE_TTL_MS];
        });
        SendAuthEmail::dispatch($email, 'team_invite', $raw, App::getLocale(), $linkOrigin);

        return $out;
    }

    public static function accept(Actor $u, string $raw): array
    {
        return Tx::run(static function () use ($u, $raw): array {
            AccountFence::assertWritable($u->uid);
            $i = DB::table('business_invitations')->where('token_hash', hash('sha256', $raw))->lockForUpdate()->first();
            $now = Js::nowMs();
            if ($i === null || $i->status !== 'pending' || (int) $i->expires_at_ms <= $now || ! $u->emailVerified || $i->email !== $u->email) {
                throw new HttpError(400, 'Davet geçersiz, süresi dolmuş veya bu hesaba ait değil.');
            }
            $existing = DB::table('business_memberships')->where('business_id', $i->business_id)->where('user_id', $u->uid)->lockForUpdate()->first();
            if ($existing !== null) {
                throw new HttpError(409, 'Bu işletmede zaten bir üyeliğin var.');
            }
            DB::table('business_memberships')->insert(['user_id' => $u->uid, 'business_id' => $i->business_id, 'role' => 'staff', 'active' => true, 'version' => 1]);
            DB::table('business_invitations')->where('id', $i->id)->update(['status' => 'accepted', 'accepted_by' => $u->uid, 'updated_at_ms' => $now]);
            self::audit($i->business_id, 'accept', $u->uid, $u->uid);

            return ['businessId' => $i->business_id, 'role' => 'staff'];
        });
    }

    /** @param array{businessId:string,memberId?:string,invitationId?:string,expectedVersion?:int,action:string} $in */
    public static function change(Actor $u, array $in): array
    {
        return Tx::run(static function () use ($u, $in): array {
            AccountFence::assertWritable($u->uid);
            self::owner($u, $in['businessId'], true);
            $now = Js::nowMs();
            if ($in['action'] === 'cancel_invitation') {
                $n = DB::table('business_invitations')->where('id', $in['invitationId'] ?? '')->where('business_id', $in['businessId'])->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at_ms' => $now]);
                if ($n !== 1) {
                    throw new HttpError(409, 'Davet değişti veya sonuçlandı.');
                }
                self::audit($in['businessId'], 'cancel_invitation', $u->uid, (string) $in['invitationId']);

                return ['ok' => true];
            }
            $m = DB::table('business_memberships')->where('business_id', $in['businessId'])->where('user_id', $in['memberId'] ?? '')->lockForUpdate()->first();
            if ($m === null) {
                throw new HttpError(404, 'Üye bulunamadı.');
            }
            if ($m->role !== 'staff' || $m->user_id === $u->uid) {
                throw new HttpError(409, 'Sahip üyeliği bu işlemle değiştirilemez.');
            }
            if ((int) $m->version !== ($in['expectedVersion'] ?? -1)) {
                throw new HttpError(409, 'Üyelik değişti. Listeyi yenile.');
            }
            $where = DB::table('business_memberships')->where('business_id', $in['businessId'])->where('user_id', $m->user_id);
            if ($in['action'] === 'remove') {
                $where->delete();
            } else {
                $active = $in['action'] === 'resume';
                if ((bool) $m->active === $active) {
                    throw new HttpError(409, 'Üyelik zaten bu durumda.');
                }
                $where->update(['active' => $active, 'version' => (int) $m->version + 1]);
            }
            self::audit($in['businessId'], $in['action'], $u->uid, $m->user_id);

            return ['ok' => true, 'version' => $in['action'] === 'remove' ? null : (int) $m->version + 1];
        });
    }

    private static function audit(string $businessId, string $action, string $actor, string $subject): void
    {
        DB::table('business_team_audit')->insert(['business_id' => $businessId, 'id' => (string) Str::uuid(), 'action' => $action, 'actor_uid' => $actor, 'subject' => mb_substr($subject, 0, 300), 'created_at_ms' => Js::nowMs()]);
    }
}
