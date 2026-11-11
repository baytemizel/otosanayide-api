<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * web-13: adli favori listeleri ve karsilastirma (en fazla 3). Mevcut duz favoriler (user_favorites, "Kaydedilenler")
 * bu uclardan etkilenmez. Sinirlar: hesap basina 20 liste, liste basina 100 isletme.
 */
final class FavoriteLists
{
    public const MAX_LISTS = 20;

    public const MAX_ITEMS = 100;

    public const MAX_COMPARE = 3;

    private static function ids(): Z
    {
        return Z::array(Z::string()->regex('/^[a-zA-Z0-9_-]{1,120}$/'))->max(self::MAX_ITEMS)->refine(static fn (array $a): bool => count(array_unique($a)) === count($a), 'duplicate');
    }

    public static function createSchema(): Z
    {
        return Z::strictObject(['name' => Z::string()->trim()->min(1)->max(60), 'kind' => Z::enum(['list', 'comparison']), 'businessIds' => self::ids(), 'operationId' => Z::string()->uuid()]);
    }

    public static function updateSchema(): Z
    {
        return Z::strictObject(['id' => Z::string()->uuid(), 'expectedVersion' => Z::number()->int()->positive(), 'name' => Z::string()->trim()->min(1)->max(60)->optional(), 'add' => self::ids()->optional(), 'remove' => self::ids()->optional()])
            ->refine(static fn (array $v): bool => isset($v['name']) || isset($v['add']) || isset($v['remove']), 'empty');
    }

    /** @param list<string> $ids @return list<string> yayinda olmayan veya ornek kayit */
    private static function unavailable(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ok = DB::table('businesses')->whereIn('id', $ids)->where('published', true)->where('sample', false)->pluck('id')->all();

        return array_values(array_diff($ids, $ok));
    }

    private static function view(stdClass $l): array
    {
        $ids = DB::table('favorite_list_items')->where('list_id', $l->id)->orderBy('position')->pluck('business_id')->all();

        return ['id' => $l->id, 'name' => $l->name, 'kind' => $l->kind, 'businessIds' => $ids, 'unavailableIds' => self::unavailable($ids), 'version' => (int) $l->version, 'createdAtMs' => (int) $l->created_at_ms, 'updatedAtMs' => (int) $l->updated_at_ms];
    }

    public static function list(string $uid): array
    {
        return ['items' => DB::table('favorite_lists')->where('user_id', $uid)->orderBy('created_at_ms')->orderBy('id')->limit(self::MAX_LISTS)->get()->map(fn (stdClass $l) => self::view($l))->all()];
    }

    /** @param array{name:string,kind:string,businessIds:list<string>,operationId:string} $in @return array{created:bool,list:array<string,mixed>} */
    public static function create(string $uid, array $in): array
    {
        self::limits($in['kind'], count($in['businessIds']));
        if (self::unavailable($in['businessIds']) !== []) {
            throw new HttpError(400, 'Listeye yalnız yayındaki işletmeler eklenebilir.');
        }

        return Tx::run(static function () use ($uid, $in): array {
            AccountFence::assertWritable($uid);
            $same = DB::table('favorite_lists')->where('user_id', $uid)->where('operation_id', $in['operationId'])->lockForUpdate()->first();
            if ($same !== null) {
                return ['created' => false, 'list' => self::view($same)];
            }
            if (DB::table('favorite_lists')->where('user_id', $uid)->lockForUpdate()->count() >= self::MAX_LISTS) {
                throw new HttpError(409, 'En fazla 20 liste oluşturabilirsin.');
            }
            $now = Js::nowMs();
            $id = (string) Str::uuid();
            DB::table('favorite_lists')->insert(['id' => $id, 'user_id' => $uid, 'operation_id' => $in['operationId'], 'name' => $in['name'], 'kind' => $in['kind'], 'version' => 1, 'created_at_ms' => $now, 'updated_at_ms' => $now]);
            self::writeItems($id, $in['businessIds']);

            return ['created' => true, 'list' => self::view(DB::table('favorite_lists')->where('id', $id)->first())];
        });
    }

    /** @param array<string,mixed> $in */
    public static function update(string $uid, array $in): array
    {
        if (self::unavailable($in['add'] ?? []) !== []) {
            throw new HttpError(400, 'Listeye yalnız yayındaki işletmeler eklenebilir.');
        }

        return Tx::run(static function () use ($uid, $in): array {
            AccountFence::assertWritable($uid);
            $l = DB::table('favorite_lists')->where('id', $in['id'])->lockForUpdate()->first();
            if ($l === null || $l->user_id !== $uid) {
                throw new HttpError(404, 'Liste bulunamadı.');
            }
            if ((int) $l->version !== $in['expectedVersion']) {
                throw new HttpError(409, 'Liste değişti. Yenileyip tekrar dene.');
            }
            $ids = DB::table('favorite_list_items')->where('list_id', $l->id)->orderBy('position')->pluck('business_id')->all();
            $ids = array_values(array_diff($ids, $in['remove'] ?? []));
            foreach ($in['add'] ?? [] as $id) {
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            self::limits($l->kind, count($ids));
            DB::table('favorite_lists')->where('id', $l->id)->update(['name' => $in['name'] ?? $l->name, 'version' => (int) $l->version + 1, 'updated_at_ms' => Js::nowMs()]);
            self::writeItems($l->id, $ids);

            return self::view(DB::table('favorite_lists')->where('id', $l->id)->first());
        });
    }

    public static function delete(string $uid, string $id): void
    {
        Tx::run(static function () use ($uid, $id): void {
            AccountFence::assertWritable($uid);
            $l = DB::table('favorite_lists')->where('id', $id)->lockForUpdate()->first();
            if ($l === null || $l->user_id !== $uid) {
                throw new HttpError(404, 'Liste bulunamadı.');
            }
            DB::table('favorite_list_items')->where('list_id', $id)->delete();
            DB::table('favorite_lists')->where('id', $id)->delete();
        });
    }

    private static function limits(string $kind, int $count): void
    {
        if ($count > ($kind === 'comparison' ? self::MAX_COMPARE : self::MAX_ITEMS)) {
            throw new HttpError(400, $kind === 'comparison' ? 'Karşılaştırmaya en fazla 3 işletme eklenebilir.' : 'Bir listeye en fazla 100 işletme eklenebilir.');
        }
    }

    /** @param list<string> $ids */
    private static function writeItems(string $listId, array $ids): void
    {
        DB::table('favorite_list_items')->where('list_id', $listId)->delete();
        foreach ($ids as $i => $bid) {
            DB::table('favorite_list_items')->insert(['list_id' => $listId, 'business_id' => $bid, 'position' => $i]);
        }
    }
}
