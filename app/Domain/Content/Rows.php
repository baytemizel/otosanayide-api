<?php

namespace App\Domain\Content;

use App\Domain\Account\Membership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Transaction icinde tekrar eden okumalar ve geçmis yazimlari. */
final class Rows
{
    public static function business(string $id, bool $lock = false): ?stdClass
    {
        $q = DB::table('businesses')->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function member(string $uid, string $businessId, bool $lock = false): ?stdClass
    {
        return Membership::find($uid, $businessId, $lock);
    }

    public static function review(string $id, bool $lock = false): ?stdClass
    {
        $q = DB::table('reviews')->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function publicRow(string $businessId, string $reviewId, bool $lock = false): ?stdClass
    {
        $q = DB::table('public_reviews')->where('business_id', $businessId)->where('review_id', $reviewId);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function reply(string $reviewId, bool $lock = false): ?stdClass
    {
        $q = DB::table('review_replies')->where('review_id', $reviewId);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function claim(string $id, bool $lock = false): ?stdClass
    {
        $q = DB::table('claims')->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    /** @param array<string,mixed> $fields */
    public static function history(string $table, string $parentColumn, string $parentId, array $fields): void
    {
        DB::table($table)->insert([$parentColumn => $parentId, 'id' => Ids::uuid()] + $fields);
    }

    /**
     * tx.create: birincil anahtar veya UNIQUE cakismasi kaynaktaki 409 mesajina cevrilir.
     *
     * @param  array<string,mixed>  $row
     * @param  callable():never  $conflict
     */
    public static function create(string $table, array $row, callable $conflict): void
    {
        try {
            DB::table($table)->insert($row);
        } catch (UniqueConstraintViolationException) {
            $conflict();
        }
    }

    /**
     * Sirali referans listesini yeniden yazar (dizi alanlari).
     *
     * @param  list<string>  $ids
     * @param  array<string,string>  $key
     */
    public static function writeList(string $table, array $key, array $ids, string $column = 'photo_id'): void
    {
        DB::table($table)->where($key)->delete();
        foreach ($ids as $position => $id) {
            DB::table($table)->insert($key + ['position' => $position, $column => $id]);
        }
    }
}
