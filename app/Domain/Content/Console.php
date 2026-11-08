<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use Illuminate\Support\Facades\DB;

/** /api/businesses/console: uyeliklerden yetkili subeler. */
final class Console
{
    /**
     * @param  bool|'activation'|'campaigns'  $scope
     * @return array{businesses:list<array<string,mixed>>,nextCursor:?string}
     */
    public static function branches(Actor $u, ?string $after, bool|string $scope): array
    {
        $q = DB::table('business_memberships')->where('user_id', $u->uid)->orderBy('business_id')->limit(21);
        if ($after !== null) {
            $q->whereRaw("CONCAT(user_id, '_', business_id) > ?", [$after]);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $out = [];
        foreach ($visible as $row) {
            $v = Docs::membership($row);
            $docId = $row->user_id.'_'.$row->business_id;
            $id = Policy::consoleMembershipId($u->uid, $docId, $v);
            if ($id === null) {
                continue;
            }
            $b = Docs::business(Rows::business($id));
            if ($b === null) {
                continue;
            }
            $item = Policy::projectConsoleBranch($u->uid, $docId, $v, $b, $scope);
            if ($item !== null) {
                $out[] = $item;
            }
        }
        $last = end($visible);

        return ['businesses' => $out, 'nextCursor' => count($page) > 20 && $last !== false ? $last->user_id.'_'.$last->business_id : null];
    }
}
