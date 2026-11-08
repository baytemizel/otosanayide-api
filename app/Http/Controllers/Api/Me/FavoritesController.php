<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Account\AccountFence;
use App\Domain\Catalogue\BusinessReader;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Query;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class FavoritesController
{
    use Guards;

    private function id(): Z
    {
        return Z::string()->regex('/^[A-Za-z0-9_-]{1,120}$/');
    }

    private function run(Request $request, bool $write): JsonResponse
    {
        $p = Query::from($request);
        $this->assertKeys($p, ['cursor', 'businessId']);
        if (($write && ! $p->isEmpty()) || ($p->has('cursor') && $p->has('businessId'))) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $cursor = $p->has('cursor') ? $this->id()->parse($p->get('cursor')) : null;
        $id = $p->has('businessId') ? $this->id()->parse($p->get('businessId')) : null;
        if ($write) {
            $this->jsonPrefix($request);
        }
        $u = $this->verified($request);
        if ($write) {
            return Api::json($this->change($u->uid, Z::strictObject(['businessId' => $this->id(), 'adding' => Z::boolean()])->parse(Body::json($request))));
        }
        if ($id !== null) {
            return Api::json(['saved' => DB::table('user_favorites')->where('user_id', $u->uid)->where('business_id', $id)->exists()]);
        }

        return Api::json($this->page($u->uid, $cursor));
    }

    public function page(string $uid, ?string $cursor): array
    {
        $q = DB::table('user_favorites')->where('user_id', $uid);
        if ($cursor !== null) {
            $q->where('business_id', '>', $cursor);
        }
        $rows = $q->orderBy('business_id')->limit(21)->pluck('business_id');
        $ids = $rows->take(20)->values()->all();
        $records = BusinessReader::records($ids);

        return ['entries' => array_map(static fn (string $id): array => ['id' => $id, 'business' => isset($records[$id]) ? BusinessReader::publicBusiness($id, $records[$id]) : null], $ids), 'next' => $rows->count() > 20 ? $ids[array_key_last($ids)] : null];
    }

    /** @param array{businessId:string,adding:bool} $v */
    private function change(string $uid, array $v): array
    {
        return Tx::run(function () use ($uid, $v): array {
            AccountFence::assertWritable($uid);
            if ($v['adding']) {
                DB::table('businesses')->where('id', $v['businessId'])->sharedLock()->first();
                $record = BusinessReader::records([$v['businessId']])[$v['businessId']] ?? null;
                if ($record === null || BusinessReader::publicBusiness($v['businessId'], $record) === null) {
                    throw new HttpError(404, 'İşletme bulunamadı.');
                }
                DB::table('user_favorites')->upsert([['user_id' => $uid, 'business_id' => $v['businessId']]], ['user_id', 'business_id'], ['user_id']);
            } else {
                DB::table('user_favorites')->where('user_id', $uid)->where('business_id', $v['businessId'])->delete();
            }

            return ['saved' => $v['adding']];
        });
    }

    public function index(Request $request): JsonResponse
    {
        return $this->run($request, false);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run($request, true);
    }

    public function options(Request $request): Response
    {
        return $this->preflight($request, 'GET, POST, OPTIONS');
    }
}
