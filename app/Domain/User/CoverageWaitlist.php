<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Domain\Catalogue\Taxonomy;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** web-12: kapsam disi il icin acilis haberi istegi. Yalniz dogrulanmis hesap; serbest e-posta toplanmaz; gonderim isi yok. */
final class CoverageWaitlist
{
    public const MAX = 5;

    /** @var list<string>|null */
    private static ?array $provinces = null;

    /** @return list<string> */
    public static function provinces(): array
    {
        return self::$provinces ??= File::json(resource_path('data/provinces.json'))['provinces'];
    }

    public static function eligible(string $city): bool
    {
        return in_array($city, self::provinces(), true) && ! in_array($city, Taxonomy::cities(), true);
    }

    /** @return array{items:list<array{city:string,createdAtMs:int}>} */
    public static function list(string $uid): array
    {
        $rows = DB::table('coverage_waitlist')->where('user_id', $uid)->orderBy('created_at_ms')->orderBy('city')->limit(self::MAX)->get();

        return ['items' => $rows->map(fn ($r) => ['city' => $r->city, 'createdAtMs' => (int) $r->created_at_ms])->all()];
    }

    /** @return array{created:bool,item:array{city:string,createdAtMs:int}} */
    public static function add(string $uid, string $city): array
    {
        if (! self::eligible($city)) {
            throw new HttpError(400, 'Bu il için bekleme listesi yok.');
        }

        return Tx::run(static function () use ($uid, $city): array {
            AccountFence::assertWritable($uid);
            $rows = DB::table('coverage_waitlist')->where('user_id', $uid)->lockForUpdate()->get();
            $existing = $rows->firstWhere('city', $city);
            if ($existing !== null) {
                return ['created' => false, 'item' => ['city' => $city, 'createdAtMs' => (int) $existing->created_at_ms]];
            }
            if ($rows->count() >= self::MAX) {
                throw new HttpError(409, 'En fazla 5 il için haber isteyebilirsin.');
            }
            $now = Js::nowMs();
            DB::table('coverage_waitlist')->insert(['user_id' => $uid, 'city' => $city, 'created_at_ms' => $now]);

            return ['created' => true, 'item' => ['city' => $city, 'createdAtMs' => $now]];
        });
    }

    public static function remove(string $uid, string $city): void
    {
        Tx::run(static function () use ($uid, $city): void {
            AccountFence::assertWritable($uid);
            DB::table('coverage_waitlist')->where('user_id', $uid)->where('city', $city)->delete();
        });
    }
}
