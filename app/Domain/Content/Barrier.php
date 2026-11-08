<?php

namespace App\Domain\Content;

use App\Domain\Account\AccountFence;
use App\Exceptions\WriteConflict;
use App\Support\Tx;
use Closure;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * content-write-barrier.ts, business-write-barrier.ts, media-write-barrier.ts.
 * Hesap silme cercevesi ve iliski kontrolleri yazimla AYNI transaction'da, yazimlardan ONCE okunur.
 * Bunlar rol vermez; rol/surum/icerik kontrolleri cagirandadir.
 */
final class Barrier
{
    /** privacyUid: gecersiz sahip kimligi cakismadir. */
    private static function owner(mixed $uid): string
    {
        if (! is_string($uid) || strlen($uid) < 1 || strlen($uid) > 128 || preg_match('/^[A-Za-z0-9_-]+$/D', $uid) !== 1) {
            throw new WriteConflict('write_relationship');
        }

        return $uid;
    }

    /** @param list<string> $uids */
    private static function fence(string $actor, array $uids): void
    {
        $sorted = array_values(array_unique($uids));
        sort($sorted, SORT_STRING);
        foreach ($sorted as $uid) {
            if ($uid !== $actor) {
                AccountFence::assertWritable($uid);
            }
        }
    }

    private static function row(string $table, string $column, string $id): ?stdClass
    {
        return DB::table($table)->where($column, $id)->lockForUpdate()->first();
    }

    /**
     * @template T
     *
     * @param  array{kind:'actor'}|array{kind:'review'|'review_with_reply'|'claim',id:string}  $policy
     * @param  Closure():T  $run
     * @return T
     */
    public static function content(string $actor, array $policy, Closure $run)
    {
        return Tx::run(static function () use ($actor, $policy, $run) {
            self::owner($actor);
            AccountFence::assertWritable($actor);
            $owners = [$actor];
            if ($policy['kind'] === 'review' || $policy['kind'] === 'review_with_reply') {
                $review = self::row('reviews', 'id', $policy['id']);
                if ($review === null) {
                    throw new WriteConflict('content_write_relationship');
                }
                $owners[] = self::owner($review->user_id);
                if ($policy['kind'] === 'review_with_reply') {
                    $reply = self::row('review_replies', 'review_id', $policy['id']);
                    if ($reply !== null) {
                        $owners[] = self::owner($reply->user_id);
                        if ($reply->review_id !== $policy['id'] || $reply->business_id !== $review->business_id) {
                            throw new WriteConflict('content_write_relationship');
                        }
                    }
                }
            } elseif ($policy['kind'] === 'claim') {
                $claim = self::row('claims', 'id', $policy['id']);
                if ($claim === null) {
                    throw new WriteConflict('content_write_relationship');
                }
                $owners[] = self::owner($claim->user_id);
            }
            self::fence($actor, $owners);

            return $run();
        });
    }

    /**
     * @template T
     *
     * @param  'business'|'profile'|'activation'  $scope
     * @param  Closure():T  $run
     * @return T
     */
    public static function business(string $actor, string $businessId, string $scope, Closure $run)
    {
        self::owner($actor);
        Schemas::id()->parse($businessId);

        return Tx::run(static function () use ($actor, $businessId, $scope, $run) {
            $b = self::row('businesses', 'id', $businessId);
            if ($b === null) {
                throw new WriteConflict('business_write_conflict');
            }
            $subjects = [$actor];
            if ($b->owner_uid !== null) {
                $subjects[] = self::owner($b->owner_uid);
            } elseif ($b->ownership === 'verified') {
                throw new WriteConflict('business_write_conflict');
            }
            if ($scope === 'profile') {
                $old = self::row('business_changes', 'business_id', $businessId);
                if ($old !== null) {
                    $subjects[] = self::owner($old->user_id);
                }
            }
            if ($scope === 'activation') {
                $old = self::row('feature_activations', 'business_id', $businessId);
                if ($old !== null) {
                    $subjects[] = self::owner($old->applicant_id);
                }
            }
            AccountFence::assertWritable($actor);
            self::fence($actor, $subjects);

            return $run();
        });
    }

    /**
     * @param  array{kind:string,id:string,claimId?:string}  $input
     * @return array{subjects:list<string>,paths:list<string>,nonce:?string,hashes:array<string,string>,sourceDigest:string}
     */
    public static function mediaBinding(array $input, ?string $actor): array
    {
        if ($actor !== null) {
            self::owner($actor);
        }
        $subjects = $actor !== null ? [$actor] : [];
        $sources = [];
        $get = static function (string $table, string $column, string $id, bool $required = true, array $extra = []) use (&$sources): ?stdClass {
            $q = DB::table($table)->where($column, $id);
            foreach ($extra as $c => $v) {
                $q->where($c, $v);
            }
            $row = $q->lockForUpdate()->first();
            if ($row === null) {
                if ($required) {
                    throw new WriteConflict('media_write_conflict');
                }

                return null;
            }
            $sources[] = ['path' => $table.'/'.$id, 'digest' => Digest::of((array) $row)];

            return $row;
        };
        $row = null;
        $paths = [];
        switch ($input['kind']) {
            case 'review_parent':
                $subjects[] = self::owner($get('reviews', 'id', $input['id'])->user_id);
                break;
            case 'claim_parent':
                $subjects[] = self::owner($get('claims', 'id', $input['id'])->user_id);
                break;
            case 'business_parent':
                $subjects[] = self::owner($get('businesses', 'id', $input['id'])->owner_uid);
                break;
            case 'review':
                $row = $get('review_photos', 'id', $input['id']);
                $subjects[] = self::owner($row->user_id);
                $r = $get('reviews', 'id', $row->review_id, false);
                if ($r !== null) {
                    if ($r->user_id !== $row->user_id || $r->business_id !== $row->business_id) {
                        throw new WriteConflict('media_write_conflict');
                    }
                    $subjects[] = self::owner($r->user_id);
                }
                $reply = $get('review_replies', 'review_id', $row->review_id, false);
                if ($reply !== null) {
                    if ($reply->business_id !== $row->business_id) {
                        throw new WriteConflict('media_write_conflict');
                    }
                    $subjects[] = self::owner($reply->user_id);
                }
                $paths = ['review-photos/'.$input['id'].'/full.webp', 'review-photos/'.$input['id'].'/thumbnail.webp'];
                break;
            case 'evidence':
                $row = $get('claim_evidence', 'id', $input['id'], true, ['claim_id' => $input['claimId']]);
                $subjects[] = self::owner($row->user_id);
                $c = $get('claims', 'id', $input['claimId'], false);
                if ($c !== null) {
                    if ($c->user_id !== $row->user_id) {
                        throw new WriteConflict('media_write_conflict');
                    }
                    $subjects[] = self::owner($c->user_id);
                }
                $paths = ['claim-evidence/'.$input['claimId'].'/'.$input['id'].'.webp'];
                break;
            case 'business':
                $row = $get('business_photos', 'id', $input['id']);
                $subjects[] = self::owner($row->owner_uid);
                $b = $get('businesses', 'id', $row->business_id, false);
                if ($b !== null && $b->owner_uid !== null) {
                    $subjects[] = self::owner($b->owner_uid);
                }
                $paths = ['business-photos/'.$input['id'].'/full.webp', 'business-photos/'.$input['id'].'/thumbnail.webp'];
                break;
            default:
                throw new WriteConflict('media_write_conflict');
        }
        $nonce = null;
        $hashes = [];
        if ($row !== null) {
            $nonce = $row->upload_nonce;
            foreach ($paths as $path) {
                $hash = str_ends_with($path, '/thumbnail.webp') ? ($row->thumbnail_sha256 ?? null) : $row->sha256;
                if ($hash !== null) {
                    $hashes[$path] = $hash;
                }
            }
        }
        $uids = array_values(array_unique($subjects));
        sort($uids, SORT_STRING);
        foreach ($uids as $uid) {
            AccountFence::assertWritable($uid);
        }

        return ['subjects' => $uids, 'paths' => $paths, 'nonce' => $nonce, 'hashes' => $hashes, 'sourceDigest' => Digest::of($sources)];
    }

    /**
     * @template T
     *
     * @param  array{kind:string,id:string,claimId?:string}  $input
     * @param  Closure():T  $run
     * @return T
     */
    public static function media(?string $actor, array $input, Closure $run)
    {
        return Tx::run(static function () use ($actor, $input, $run) {
            self::mediaBinding($input, $actor);

            return $run();
        });
    }
}
