<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Content\Barrier;
use App\Domain\Content\Docs;
use App\Domain\Content\Ids;
use App\Domain\Content\MediaStore;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** /api/admin/claims, /claim-evidence, /claim-targets */
final class ClaimAdmin
{
    /** @return array{claims:list<array<string,mixed>>} */
    public static function open(): array
    {
        $rows = DB::table('claims')->whereIn('status', ['submitted', 'needs_information'])->orderBy('id')->limit(50)->get();

        return ['claims' => $rows->map(static fn (\stdClass $c): array => ['id' => $c->id, 'name' => $c->name, 'city' => $c->city, 'address' => $c->address, 'contactName' => $c->contact_name, 'phone' => $c->phone, 'note' => $c->note ?? '', 'reply' => $c->reply ?? '', 'status' => $c->status, 'businessId' => $c->business_id, 'verificationReference' => $c->verification_reference])->all()];
    }

    /**
     * @param  array<string,mixed>  $input  claimReview ciktisi
     * @return array{id:string,status:string}
     */
    public static function decide(Actor $reviewer, array $input): array
    {
        $claimId = $input['claimId'];
        $status = Barrier::content($reviewer->uid, ['kind' => 'claim', 'id' => $claimId], static function () use ($reviewer, $input, $claimId): string {
            $row = Rows::claim($claimId, true);
            if ($row === null) {
                throw new HttpError(404, 'Başvuru bulunamadı.');
            }
            if ($row->user_id === '' || str_contains($row->user_id, '/')) {
                throw new HttpError(409, 'Başvuru hesabı incelenmeli.');
            }
            $businessId = ($row->business_id !== null && $row->business_id !== '') ? Schemas::id()->parse($row->business_id) : null;
            $businessRow = $businessId !== null ? Rows::business($businessId, true) : null;
            $next = AdminPolicy::decideClaim($input, ['status' => $row->status, 'userId' => $row->user_id, 'businessId' => $businessId], $reviewer->uid, Docs::business($businessRow));
            if ($next === 'approved') {
                if ($input['decision'] !== 'approve') {
                    throw new HttpError(409, 'Karar tutarsız.');
                }
                $proofRow = DB::table('claim_verifications')->where('id', $input['verificationReference'])->lockForUpdate()->first();
                $proof = $proofRow === null ? null : ['claimId' => $proofRow->claim_id, 'businessId' => $proofRow->business_id, 'userId' => $proofRow->user_id, 'status' => $proofRow->status, 'evidenceRevision' => (int) $proofRow->evidence_revision, 'expiresAtMs' => (int) $proofRow->expires_at_ms];
                if (! AdminPolicy::matchesVerifiedEvidence($proof, ['claimId' => $claimId, 'businessId' => $businessId, 'userId' => $row->user_id]) || ! Policy::currentEvidenceProof($proof, Docs::claim($row), Ids::nowMs())) {
                    throw new HttpError(409, 'Bu başvuruya bağlı doğrulanmış kanıt kaydı gerekli.');
                }
                $membership = Rows::member($row->user_id, (string) $businessId, true);
                if ($membership !== null && (bool) $membership->active) {
                    throw new HttpError(409, 'Mevcut üyelik ayrıca incelenmeli.');
                }
            }
            $update = ['status' => $next, 'reviewed_by' => $reviewer->uid, 'reviewed_at' => Ids::now(), 'review_reason' => $input['reason']];
            if ($next === 'needs_information') {
                $update['evidence_revision'] = ((int) ($row->evidence_revision ?? 0)) + 1;
            }
            DB::table('claims')->where('id', $claimId)->update($update);
            // Kaynak denetim belgesinde `kind` yok; NOT NULL kolon bos dize tasir.
            Rows::history('claim_history', 'claim_id', $claimId, ['kind' => '', 'actor_id' => $reviewer->uid, 'details' => Ids::stringify(['from' => $row->status, 'to' => $next, 'reason' => $input['reason']] + ($input['decision'] === 'approve' ? ['verificationReference' => $input['verificationReference'], 'evidenceChecked' => true] : [])), 'created_at' => Ids::now()]);
            if ($next === 'approved' && $businessId !== null) {
                DB::table('businesses')->where('id', $businessId)->update(['ownership' => 'verified', 'owner_uid' => $row->user_id, 'owner_ready' => false, 'feature_quotes' => false, 'feature_appointments' => false, 'updated_at' => Ids::now()]);
                DB::table('business_memberships')->updateOrInsert(['user_id' => $row->user_id, 'business_id' => $businessId], ['role' => 'owner', 'active' => true, 'claim_id' => $claimId, 'granted_by' => $reviewer->uid, 'created_at' => Ids::now()]);
                SearchProjection::sync($businessId);
            }

            return $next;
        });

        return ['id' => $claimId, 'status' => $status];
    }

    /** @param array<string,mixed> $input evidenceVerification ciktisi */
    public static function verifyEvidence(Actor $u, array $input): string
    {
        $proofId = Ids::auto();
        $claimId = $input['claimId'];
        Barrier::content($u->uid, ['kind' => 'claim', 'id' => $claimId], static function () use ($u, $input, $claimId, $proofId): void {
            $row = Rows::claim($claimId, true);
            if ($row === null || ! in_array($row->status, ['submitted', 'needs_information'], true) || $row->user_id === $u->uid) {
                throw new HttpError(409, 'Başvuru incelemeye uygun değil.');
            }
            if (! is_string($row->business_id) || preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $row->business_id) !== 1) {
                throw new HttpError(409, 'Yeni başvuru önce gerçek işletme kaydına bağlanmalı.');
            }
            $b = Rows::business($row->business_id, true);
            if ($b === null || (bool) $b->sample || ! in_array($b->ownership, ['unclaimed', 'pending'], true) || ($b->owner_uid !== null && $b->owner_uid !== '')) {
                throw new HttpError(409, 'İşletme sahipliği yeniden incelenmeli.');
            }
            $c = Docs::claim($row);
            if (($c['evidenceRevision'] ?? null) !== $input['evidenceRevision'] || ($c['pendingEvidenceUploads'] ?? 0) !== 0 || ($c['evidenceCount'] ?? null) !== count($input['evidenceIds'])) {
                throw new HttpError(409, 'Belge listesi değişti. Tüm güncel belgeleri yeniden aç.');
            }
            $key = Ids::viewKey($u->uid);
            $now = Ids::nowMs();
            $expires = [];
            foreach ($input['evidenceIds'] as $id) {
                $e = Docs::evidence(DB::table('claim_evidence')->where('claim_id', $claimId)->where('id', $id)->lockForUpdate()->first());
                $viewRow = DB::table('claim_evidence_views')->where('claim_id', $claimId)->where('evidence_id', $id)->where('viewer_key', $key)->first();
                $view = $viewRow === null ? null : ['reviewerId' => $viewRow->reviewer_id, 'sha256' => $viewRow->sha256];
                if (! Policy::reviewableEvidence($e, $claimId, $row->user_id, $view, $u->uid, $now)) {
                    throw new HttpError(409, 'Her güncel belgeyi tam boyutta açıp kontrol etmelisin.');
                }
                $expires[] = (int) $e['expiresAtMs'];
            }
            DB::table('claim_verifications')->insert(['id' => $proofId, 'claim_id' => $claimId, 'business_id' => $row->business_id, 'user_id' => $row->user_id, 'status' => 'verified', 'evidence_revision' => $input['evidenceRevision'], 'expires_at_ms' => min($expires), 'reviewed_by' => $u->uid, 'reason' => $input['reason'], 'created_at' => Ids::now()]);
            foreach (array_values($input['evidenceIds']) as $position => $id) {
                DB::table('claim_verification_evidence')->insert(['verification_id' => $proofId, 'position' => $position, 'evidence_id' => $id]);
            }
            DB::table('claims')->where('id', $claimId)->update(['verification_reference' => $proofId]);
            Rows::history('claim_history', 'claim_id', $claimId, ['kind' => 'evidence_verified', 'actor_id' => $u->uid, 'details' => Ids::stringify(['verificationReference' => $proofId, 'evidenceIds' => $input['evidenceIds'], 'reviewerId' => $u->uid, 'reason' => $input['reason']]), 'created_at' => Ids::now()]);
        });

        return $proofId;
    }

    /** Depo kapaliysa 503: kaynak sira geregi govdeden once. */
    public static function assertEvidenceStore(): void
    {
        MediaStore::disk(MediaStore::EVIDENCE);
    }

    /** @return array{business:array<string,mixed>} */
    public static function target(string $id): array
    {
        $v = Docs::business(Rows::business($id));
        if ($v === null) {
            throw new HttpError(404, 'İşletme kaydı bulunamadı.');
        }

        return ['business' => ['id' => $id, 'name' => $v['name'] ?? null, 'city' => $v['city'] ?? null, 'district' => $v['district'] ?? null, 'address' => $v['address'] ?? null, 'phone' => $v['phone'] ?? '', 'published' => ($v['published'] ?? null) === true, 'eligible' => AdminPolicy::eligibleClaimTarget($v)]];
    }

    /**
     * @param  array<string,mixed>  $input  claimTarget ciktisi
     * @return array{businessId:string,published?:false}
     */
    public static function mapTarget(Actor $u, array $input): array
    {
        $claimId = $input['claimId'];
        $businessId = $input['mode'] === 'existing' ? $input['businessId'] : 'new-'.substr(hash('sha256', $claimId), 0, 32);
        Barrier::content($u->uid, ['kind' => 'claim', 'id' => $claimId], static function () use ($u, $input, $claimId, $businessId): void {
            $row = Rows::claim($claimId, true);
            $claim = Docs::claim($row);
            $businessRow = Rows::business($businessId, true);
            if (! AdminPolicy::mayMapClaim($claim, $u->uid)) {
                throw new HttpError(409, 'Başvuru eşlemeye uygun değil veya daha önce bağlanmış.');
            }
            if ($input['mode'] === 'existing') {
                if (! AdminPolicy::eligibleClaimTarget(Docs::business($businessRow))) {
                    throw new HttpError(409, 'Seçilen işletme sahiplenmeye uygun değil.');
                }
            } else {
                if ($businessRow !== null) {
                    throw new HttpError(409, 'Bu başvurunun işletme kaydı zaten var.');
                }
                $duplicate = static function (): never {
                    throw new HttpError(409, 'Aynı bilgilerle daha önce kayıt oluşturulmuş. Mevcut işletmeyi incele.');
                };
                $key = hash('sha256', AdminPolicy::businessIdentityText($input['profile']));
                if (DB::table('businesses')->where('identity_key', $key)->lockForUpdate()->exists()) {
                    $duplicate();
                }
                $projection = AdminPolicy::profileProjection($input['profile'], []);
                try {
                    DB::table('businesses')->insert(['id' => $businessId, 'published' => false, 'sample' => false, 'ownership' => 'unclaimed', 'owner_ready' => false, 'feature_quotes' => false, 'feature_appointments' => false, 'profile_version' => 0, 'duplicate_review_status' => 'required', 'source' => 'admin_claim_review', 'identity_key' => $key, 'created_by' => $u->uid, 'created_at' => Ids::now()] + array_replace(BusinessRecords::profileColumns($projection), ['coordinate_status' => 'unverified']));
                } catch (UniqueConstraintViolationException) {
                    $duplicate();
                }
                BusinessRecords::writeValues($businessId, $projection);
            }
            DB::table('claims')->where('id', $claimId)->update(['business_id' => $businessId, 'verification_reference' => null, 'evidence_revision' => ((int) ($row->evidence_revision ?? 0)) + 1, 'mapped_by' => $u->uid, 'mapped_at' => Ids::now()]);
            Rows::history('claim_history', 'claim_id', $claimId, ['kind' => 'business_mapped', 'actor_id' => $u->uid, 'details' => Ids::stringify(['mode' => $input['mode'], 'businessId' => $businessId, 'reason' => $input['reason'], 'reviewerId' => $u->uid]), 'created_at' => Ids::now()]);
            Rows::history('business_history', 'business_id', $businessId, ['kind' => $input['mode'] === 'new' ? 'created_from_claim' : 'claim_linked', 'details' => Ids::stringify(['claimId' => $claimId, 'reviewerId' => $u->uid, 'reason' => $input['reason']]), 'created_at' => Ids::now()]);
        });

        return ['businessId' => $businessId] + ($input['mode'] === 'new' ? ['published' => false] : []);
    }
}
