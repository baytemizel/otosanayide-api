<?php

namespace App\Domain\Content;

use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use App\Support\Body;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** /api/claims/evidence */
final class ClaimEvidence
{
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_BYTES = 4194304;

    private const MAX_FILES = 5;

    private const RETENTION_DAYS = 90;

    /** @return array<string,mixed> */
    public static function list(Actor $u, string $claimId): array
    {
        $claim = Docs::claim(Rows::claim($claimId));
        if (! Policy::canReadEvidence($u->uid, $u->admin, $u->emailVerified, $claim)) {
            throw new HttpError(404, 'Başvuru bulunamadı.');
        }
        $docs = DB::table('claim_evidence')->where('claim_id', $claimId)->where('status', 'ready')->orderBy('id')->limit(self::MAX_FILES)->get();

        return [
            'enabled' => MediaStore::enabled(MediaStore::EVIDENCE),
            'evidenceRevision' => $claim['evidenceRevision'] ?? 0,
            'pendingUploads' => $claim['pendingEvidenceUploads'] ?? 0,
            'items' => $docs->map(static fn (\stdClass $d): array => ['id' => $d->id, 'kind' => $d->kind, 'bytes' => (int) $d->bytes, 'width' => (int) $d->width, 'height' => (int) $d->height, 'expiresAtMs' => (int) $d->expires_at_ms])->all(),
        ];
    }

    public static function download(Actor $u, string $claimId, string $id): string
    {
        $claim = Docs::claim(Rows::claim($claimId));
        if (! Policy::canReadEvidence($u->uid, $u->admin, $u->emailVerified, $claim)) {
            throw new HttpError(404, 'Başvuru bulunamadı.');
        }
        Schemas::uuid()->parse($id);
        $item = Docs::evidence(DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->first());
        if ($item === null || ($item['claimId'] ?? null) !== $claimId || ($item['userId'] ?? null) !== ($claim['userId'] ?? null) || ($item['status'] ?? null) !== 'ready' || ! is_int($item['expiresAtMs'] ?? null) || $item['expiresAtMs'] <= Ids::nowMs()) {
            throw new HttpError(404, 'Belge bulunamadı veya erişim süresi doldu.');
        }
        $disk = MediaStore::disk(MediaStore::EVIDENCE);
        $bytes = MediaStore::read($disk, MediaStore::evidencePath($claimId, $id));
        if (strlen($bytes) !== ($item['bytes'] ?? null) || hash('sha256', $bytes) !== ($item['sha256'] ?? null)) {
            throw new HttpError(409, 'Belge bütünlüğü doğrulanamadı.');
        }
        if ($u->admin) {
            Barrier::media($u->uid, ['kind' => 'evidence', 'claimId' => $claimId, 'id' => $id], static function () use ($u, $claimId, $id, $item): void {
                $current = DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->lockForUpdate()->first();
                if (($current->sha256 ?? null) !== $item['sha256'] || ($current->status ?? null) !== 'ready') {
                    throw new HttpError(409, 'Belge bütünlüğü doğrulanamadı.');
                }
                DB::statement('INSERT INTO `claim_evidence_views` (`claim_id`, `evidence_id`, `viewer_key`, `reviewer_id`, `sha256`, `viewed_at`) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE `reviewer_id` = VALUES(`reviewer_id`), `sha256` = VALUES(`sha256`), `viewed_at` = VALUES(`viewed_at`)', [$claimId, $id, Ids::viewKey($u->uid), $u->uid, $item['sha256']]);
            });
        }

        return $bytes;
    }

    /** @return array{id:string,status:string} */
    public static function upload(Actor $u, Request $req, string $claimId, string $kind): array
    {
        $id = Ids::uuid();
        $nonce = Ids::uuid();
        $resource = ['kind' => 'evidence', 'claimId' => $claimId, 'id' => $id];
        $reserved = false;
        try {
            $preliminary = Docs::claim(Rows::claim($claimId));
            if (! Policy::canUploadEvidence($u->uid, true, $preliminary)) {
                throw new HttpError(404, 'Belge eklenebilecek başvuru bulunamadı.');
            }
            if (! in_array($req->headers->get('Content-Type') ?? '', self::TYPES, true)) {
                throw new HttpError(415, 'JPEG, PNG veya WebP gerekli.');
            }
            $disk = MediaStore::disk(MediaStore::EVIDENCE);
            Barrier::media($u->uid, ['kind' => 'claim_parent', 'id' => $claimId], static function () use ($u, $claimId, $id, $nonce, $kind): void {
                $row = Rows::claim($claimId, true);
                $v = Docs::claim($row);
                if (! Policy::canUploadEvidence($u->uid, true, $v)) {
                    throw new HttpError(409, 'Başvuru durumu değişti.');
                }
                if (($v['totalEvidenceUploads'] ?? 0) >= 20) {
                    throw new HttpError(409, 'Belge deneme sınırına ulaşıldı. Destek ekibine başvur.');
                }
                if (($v['evidenceCount'] ?? 0) >= self::MAX_FILES) {
                    throw new HttpError(409, 'Başvuru başına en fazla beş belge sayfası eklenebilir.');
                }
                DB::table('claim_evidence')->insert(['claim_id' => $claimId, 'id' => $id, 'user_id' => $u->uid, 'kind' => $kind, 'status' => 'uploading', 'upload_nonce' => $nonce, 'created_at' => Ids::now(), 'created_at_ms' => Ids::nowMs()]);
                DB::table('claims')->where('id', $claimId)->update(['total_evidence_uploads' => ($v['totalEvidenceUploads'] ?? 0) + 1, 'evidence_count' => ($v['evidenceCount'] ?? 0) + 1, 'pending_evidence_uploads' => ($v['pendingEvidenceUploads'] ?? 0) + 1]);
            });
            $reserved = true;
            $input = Body::bytes($req, self::MAX_BYTES);
            try {
                $image = ImageCodec::evidence($input);
            } catch (Throwable) {
                throw new HttpError(400, 'Belge fotoğrafı okunamadı veya boyut sınırını aşıyor. JPEG, PNG veya WebP sayfasını yeniden seç.');
            }
            $expiresAtMs = Ids::nowMs() + self::RETENTION_DAYS * 86400000;
            MediaStore::save($disk, $u->uid, $resource, MediaStore::evidencePath($claimId, $id), $image['data']);
            Barrier::media($u->uid, $resource, static function () use ($u, $claimId, $id, $image, $expiresAtMs): void {
                $v = Docs::claim(Rows::claim($claimId, true));
                $e = DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->lockForUpdate()->first();
                if (! Policy::canUploadEvidence($u->uid, true, $v) || ($e->status ?? null) !== 'uploading') {
                    throw new HttpError(409, 'Başvuru durumu değişti.');
                }
                DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->update(['status' => 'ready', 'bytes' => strlen($image['data']), 'width' => $image['width'], 'height' => $image['height'], 'sha256' => $image['sha256'], 'expires_at_ms' => $expiresAtMs, 'ready_at' => Ids::now()]);
                DB::table('claims')->where('id', $claimId)->update(['pending_evidence_uploads' => max(0, ($v['pendingEvidenceUploads'] ?? 1) - 1), 'evidence_revision' => ($v['evidenceRevision'] ?? 0) + 1]);
                Rows::history('claim_history', 'claim_id', $claimId, ['kind' => 'evidence_uploaded', 'actor_id' => $u->uid, 'details' => Ids::stringify(['evidenceId' => $id]), 'created_at' => Ids::now()]);
            });

            return ['id' => $id, 'status' => 'ready'];
        } catch (Throwable $e) {
            // Sonlandirma tekrari islenmis olabilir; ready nesne silinmez.
            if ($reserved) {
                self::cleanup($u->uid, $claimId, $id, $resource);
            }
            throw $e;
        }
    }

    /** @param array{kind:string,id:string,claimId:string} $resource */
    private static function cleanup(string $uid, string $claimId, string $id, array $resource): void
    {
        try {
            $failed = Barrier::media($uid, $resource, static function () use ($claimId, $id): bool {
                $claim = Rows::claim($claimId, true);
                $file = DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->lockForUpdate()->first();
                if (($file->status ?? null) !== 'uploading') {
                    return false;
                }
                DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->update(['status' => 'failed', 'failed_at' => Ids::now()]);
                if ($claim !== null) {
                    $v = Docs::claim($claim);
                    DB::table('claims')->where('id', $claimId)->update(['evidence_count' => max(0, ($v['evidenceCount'] ?? 1) - 1), 'pending_evidence_uploads' => max(0, ($v['pendingEvidenceUploads'] ?? 1) - 1)]);
                }

                return true;
            });
            if ($failed) {
                MediaStore::delete(MediaStore::disk(MediaStore::EVIDENCE), $uid, $resource, MediaStore::evidencePath($claimId, $id));
            }
        } catch (Throwable) {
            Log::error('Evidence cleanup requires reconciliation');
        }
    }

    /** @param array{claimId:string,id:string,action:string} $input */
    public static function withdraw(Actor $u, array $input): void
    {
        Barrier::media($u->uid, ['kind' => 'evidence', 'claimId' => $input['claimId'], 'id' => $input['id']], static function () use ($u, $input): void {
            $claim = Docs::claim(Rows::claim($input['claimId'], true));
            $file = DB::table('claim_evidence')->where('claim_id', $input['claimId'])->where('id', $input['id'])->lockForUpdate()->first();
            if (! Policy::canUploadEvidence($u->uid, true, $claim) || ($file->user_id ?? null) !== $u->uid || ($file->status ?? null) !== 'ready') {
                throw new HttpError(409, 'Bu belge başvurudan çıkarılamıyor.');
            }
            DB::table('claim_evidence')->where('claim_id', $input['claimId'])->where('id', $input['id'])->update(['status' => 'withdrawn', 'withdrawn_at' => Ids::now()]);
            DB::table('claims')->where('id', $input['claimId'])->update(['evidence_count' => max(0, ($claim['evidenceCount'] ?? 1) - 1), 'evidence_revision' => ($claim['evidenceRevision'] ?? 0) + 1]);
            Rows::history('claim_history', 'claim_id', $input['claimId'], ['kind' => 'evidence_withdrawn', 'actor_id' => $u->uid, 'details' => Ids::stringify(['evidenceId' => $input['id']]), 'created_at' => Ids::now()]);
        });
    }
}
