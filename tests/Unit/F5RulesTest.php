<?php

use App\Domain\Content\Policy;
use App\Domain\Content\Schemas;
use App\Support\ZodError;

// packages/core/*.test.ts ornekleri: review, claim, business, activation.

function f5ok(mixed $schema, array|stdClass $v): bool
{
    try {
        $schema->parse(json_decode(json_encode($v)));

        return true;
    } catch (ZodError) {
        return false;
    }
}

function f5reviewBase(): array
{
    return ['userId' => 'u', 'businessId' => 'b', 'status' => 'approved', 'version' => 4, 'submissionVersion' => 3, 'rating' => 5];
}

it('rejects other authors, businesses, stale and repeated author mutations', function () {
    $review = f5reviewBase();
    foreach ([['x', 'b', 4], ['u', 'x', 4], ['u', 'b', 3]] as [$uid, $bid, $version]) {
        expect(fn () => Policy::authorReviewTransition($uid, $bid, $review, $version, 'edit'))->toThrow(RuntimeException::class);
    }
    $next = Policy::authorReviewTransition('u', 'b', $review, 4, 'withdraw');
    expect(fn () => Policy::authorReviewTransition('u', 'b', [...$review, ...$next], 4, 'withdraw'))->toThrow(RuntimeException::class)
        ->and(fn () => Policy::authorReviewTransition('u', 'b', [...$review, ...$next], 5, 'withdraw'))->toThrow(RuntimeException::class);
});

it('removes exactly the previous approved rating on edit or withdraw', function () {
    $review = f5reviewBase();
    foreach (['edit', 'withdraw'] as $action) {
        $r = Policy::authorReviewTransition('u', 'b', $review, 4, $action);
        expect($r['delta'])->toBe(-1)->and(Policy::reviewTotals(2, 8, 5, $r['delta']))->toBe(['reviewCount' => 1, 'reviewRatingSum' => 3])->and($r['status'])->toBe($action === 'edit' ? 'pending' : 'withdrawn');
    }
    foreach (['pending', 'rejected'] as $status) {
        expect(Policy::authorReviewTransition('u', 'b', [...$review, 'status' => $status], 4, 'edit')['delta'])->toBe(0);
    }
});

it('cannot bypass admin removal through withdrawal and resubmission', function () {
    $review = f5reviewBase();
    $hidden = [...$review, 'status' => 'hidden'];
    expect(Policy::authorReviewPermissions($hidden)['canEdit'])->toBeFalse()
        ->and(fn () => Policy::authorReviewTransition('u', 'b', $hidden, 4, 'edit'))->toThrow(RuntimeException::class);
    $next = Policy::authorReviewTransition('u', 'b', $hidden, 4, 'withdraw');
    expect($next['moderationHold'])->toBeTrue()
        ->and(fn () => Policy::authorReviewTransition('u', 'b', [...$hidden, ...$next], 5, 'edit'))->toThrow(RuntimeException::class)
        ->and(Policy::authorReviewTransition('u', 'b', [...$review, 'status' => 'withdrawn'], 4, 'edit')['status'])->toBe('pending');
});

it('a new submission invalidates old photos', function () {
    $review = f5reviewBase();
    $next = Policy::authorReviewTransition('u', 'b', $review, 4, 'edit');
    $photo = [...$review, 'status' => 'approved'];
    expect(Policy::photoIsPublic($photo, [...$review, ...$next, 'status' => 'approved'], ['published' => true]))->toBeFalse()->and($next['submissionVersion'])->toBe(5);
});

it('requires withdrawal confirmation and a matching strict edit payload', function () {
    $body = ['action' => 'edit', 'businessId' => 'b', 'expectedVersion' => 4, 'review' => ['businessId' => 'b', 'rating' => 3, 'resolved' => 'yes', 'wc' => 'no', 'text' => 'Updated service experience']];
    expect(f5ok(Schemas::authorReviewAction(), $body))->toBeTrue();
    foreach ([['userId' => 'other'], ['status' => 'approved'], ['expectedVersion' => 0], ['review' => [...$body['review'], 'businessId' => 'other']]] as $change) {
        expect(f5ok(Schemas::authorReviewAction(), [...$body, ...$change]))->toBeFalse();
    }
    expect(f5ok(Schemas::authorReviewAction(), ['action' => 'withdraw', 'businessId' => 'b', 'expectedVersion' => 4]))->toBeFalse()
        ->and(f5ok(Schemas::authorReviewAction(), ['action' => 'withdraw', 'businessId' => 'b', 'expectedVersion' => 4, 'confirmed' => true]))->toBeTrue();
});

it('review wcCleanliness only with wc=yes and bounded scores coerce like Zod', function () {
    $v = ['businessId' => 'b', 'rating' => '4', 'resolved' => 'yes', 'wc' => 'no', 'text' => 'Yeterince uzun bir yorum'];
    expect(Schemas::review()->parse((object) $v)['rating'])->toBe(4);
    expect(f5ok(Schemas::review(), [...$v, 'wcCleanliness' => 3]))->toBeFalse()->and(f5ok(Schemas::review(), [...$v, 'wc' => 'yes', 'wcCleanliness' => 3]))->toBeTrue()->and(f5ok(Schemas::review(), [...$v, 'rating' => 6]))->toBeFalse()->and(f5ok(Schemas::review(), [...$v, 'rating' => 'x']))->toBeFalse();
    expect(f5ok(Schemas::review(), [...$v, 'text' => 'kısa']))->toBeFalse();
});

it('own review requires author and business match and hides moderation data', function () {
    $review = ['userId' => 'u', 'businessId' => 'b', 'text' => 'Test deneyimi', 'rating' => 3, 'status' => 'rejected', 'reason' => 'İlgisiz içerik', 'reviewedBy' => 'secret-admin', 'automatic' => []];
    expect(Policy::ownReview('other', 'b', 'r', $review))->toBeNull()->and(Policy::ownReview('u', 'other', 'r', $review))->toBeNull()->and(Policy::ownReview('u', 'b', 'r', null))->toBeNull();
    expect(Policy::ownReview('u', 'b', 'r', $review))->toEqual(['id' => 'r', 'text' => 'Test deneyimi', 'rating' => 3, 'status' => 'rejected', 'reason' => 'İlgisiz içerik', 'version' => 1, 'resolved' => 'unknown', 'wc' => 'unknown', 'canEdit' => true, 'canWithdraw' => true]);
    expect(Policy::ownReview('u', 'b', 'r', [...$review, 'status' => 'unexpected'])['status'])->toBe('unknown');
});

it('moderates each state once and counts ratings exactly', function () {
    expect(Policy::reviewTransition('pending', 'approve'))->toBe(['status' => 'approved', 'delta' => 1])->and(Policy::reviewTransition('approved', 'hide'))->toBe(['status' => 'hidden', 'delta' => -1]);
    foreach ([['approved', 'approve'], ['hidden', 'hide'], ['pending', 'hide']] as [$s, $d]) {
        expect(fn () => Policy::reviewTransition($s, $d))->toThrow(RuntimeException::class);
    }
    expect(Policy::reviewTotals(2, 7, 5, 1))->toBe(['reviewCount' => 3, 'reviewRatingSum' => 12])->and(Policy::reviewTotals(3, 12, 5, -1))->toBe(['reviewCount' => 2, 'reviewRatingSum' => 7]);
    foreach ([[0, 0, 5, -1], [1, 0, 5, 1], [1, 5, 9, 1]] as $args) {
        expect(fn () => Policy::reviewTotals(...$args))->toThrow(RuntimeException::class);
    }
});

it('blocks owners and active staff from reviewing and keeps public reviews private', function () {
    expect(Policy::affiliatedReviewer('a', ['ownerUid' => 'a'], null))->toBeTrue()
        ->and(Policy::affiliatedReviewer('a', [], ['userId' => 'a', 'active' => true, 'role' => 'staff']))->toBeTrue()
        ->and(Policy::affiliatedReviewer('a', [], ['userId' => 'b', 'active' => true, 'role' => 'owner']))->toBeFalse()
        ->and(Policy::affiliatedReviewer('a', [], ['userId' => 'a', 'active' => false, 'role' => 'staff']))->toBeFalse();
    $p = Policy::publicReview('r', ['rating' => 4, 'text' => 'Deneyim metni', 'resolved' => 'yes', 'wc' => 'no', 'quality' => 5, 'userId' => 'secret', 'email' => 'private', 'reason' => 'internal', 'reviewedBy' => 'admin', 'status' => 'approved']);
    $keys = array_keys($p);
    sort($keys);
    expect($keys)->toBe(['id', 'quality', 'rating', 'resolved', 'text', 'wc']);
});

it('public photo projection only keeps bounded ids and text-bounded replies', function () {
    $id = 'b7a2ac46-eae4-4f25-a422-4d198e14eaf6';
    $v = Policy::publicReview('r', ['rating' => 3, 'text' => 'Test review content', 'resolved' => 'yes', 'wc' => 'unknown', 'publicPhotoIds' => [$id, '../secret'], 'reply' => ['text' => 'Test business response'], 'userId' => 'secret']);
    expect($v['photoIds'])->toBe([$id])->and($v)->not->toHaveKey('userId')->and($v['reply'])->toBe(['text' => 'Test business response']);
    expect(Policy::publicReply(['text' => 42]))->toBeNull()->and(Policy::publicReply(['text' => str_repeat('x', 2001)]))->toBeNull();
    expect(Policy::publicReply(['text' => 'Teşekkür ederiz.', 'userId' => 'secret', 'reason' => 'private']))->toBe(['text' => 'Teşekkür ederiz.']);
});

it('files reports only on published other-author reviews and appeals on own removed ones', function () {
    $review = ['userId' => 'author', 'businessId' => 'b', 'version' => 4, 'status' => 'approved', 'reviewedBy' => 'first'];
    expect(Policy::canFileCase('reporter', 'report', $review))->toBeTrue()->and(Policy::canFileCase('author', 'report', $review))->toBeFalse();
    foreach (['pending', 'withdrawn', 'rejected', 'hidden'] as $status) {
        expect(Policy::canFileCase('reporter', 'report', [...$review, 'status' => $status]))->toBeFalse();
    }
    foreach (['hidden', 'rejected'] as $status) {
        expect(Policy::canFileCase('author', 'appeal', [...$review, 'status' => $status]))->toBeTrue()->and(Policy::canFileCase('other', 'appeal', [...$review, 'status' => $status]))->toBeFalse();
    }
    expect(Policy::canFileCase('author', 'appeal', $review))->toBeFalse()->and(Policy::canFileCase('x', 'report', null))->toBeFalse();
});

it('case decisions reject self, stale and misdirected actions', function () {
    $review = ['userId' => 'author', 'businessId' => 'b', 'version' => 4, 'status' => 'approved', 'reviewedBy' => 'first'];
    $report = ['userId' => 'reporter', 'businessId' => 'b', 'reviewVersion' => 4, 'kind' => 'report', 'status' => 'open'];
    foreach (['author', 'reporter'] as $uid) {
        expect(fn () => Policy::caseAction($uid, $report, $review, 'remove'))->toThrow(RuntimeException::class);
    }
    expect(fn () => Policy::caseAction('first', [...$report, 'kind' => 'appeal'], [...$review, 'status' => 'hidden'], 'reopen'))->toThrow(RuntimeException::class)
        ->and(fn () => Policy::caseAction('admin', [...$report, 'status' => 'closed'], $review, 'remove'))->toThrow(RuntimeException::class)
        ->and(fn () => Policy::caseAction('admin', $report, [...$review, 'version' => 5], 'remove'))->toThrow(RuntimeException::class)
        ->and(Policy::caseAction('admin', $report, [...$review, 'version' => 5], 'superseded'))->toBe('none')
        ->and(fn () => Policy::caseAction('admin', $report, $review, 'superseded'))->toThrow(RuntimeException::class)
        ->and(Policy::caseAction('admin', $report, null, 'superseded'))->toBe('none')
        ->and(Policy::caseAction('admin', $report, $review, 'remove'))->toBe('hide')
        ->and(Policy::caseAction('admin', $report, $review, 'dismiss'))->toBe('none')
        ->and(Policy::caseAction('admin', [...$report, 'kind' => 'appeal'], [...$review, 'status' => 'hidden'], 'reopen'))->toBe('pending');
});

it('case input is strict and the projection hides identity', function () {
    expect(f5ok(Schemas::caseInput(), ['reviewId' => 'r', 'kind' => 'report', 'text' => 'A detailed report']))->toBeTrue()
        ->and(f5ok(Schemas::caseInput(), ['reviewId' => '../r', 'kind' => 'report', 'text' => 'A detailed report']))->toBeFalse()
        ->and(f5ok(Schemas::caseInput(), ['reviewId' => 'r', 'kind' => 'report', 'text' => 'A detailed report', 'userId' => 'spoof']))->toBeFalse();
    $view = Policy::caseView('c', ['userId' => 'reporter', 'reviewId' => 'r', 'businessId' => 'b', 'kind' => 'report', 'text' => 'report', 'status' => 'open', 'reviewedBy' => 'secret']);
    expect($view)->not->toHaveKey('userId')->and($view)->not->toHaveKey('reviewedBy')->and($view['decision'])->toBe('')->and($view['reason'])->toBe('');
});

it('reply requires a published verified business and a matching active membership', function () {
    $b = ['published' => true, 'ownership' => 'verified'];
    $m = ['userId' => 'u', 'businessId' => 'b', 'active' => true, 'role' => 'owner'];
    expect(Policy::mayReply('u', 'b', $b, $m))->toBeTrue();
    foreach ([['active' => false], ['userId' => 'other'], ['businessId' => 'other'], ['role' => 'reader']] as $patch) {
        expect(Policy::mayReply('u', 'b', $b, [...$m, ...$patch]))->toBeFalse();
    }
    foreach ([['published' => false], ['sample' => true], ['ownership' => 'unclaimed']] as $patch) {
        expect(Policy::mayReply('u', 'b', [...$b, ...$patch], $m))->toBeFalse();
    }
    expect(Policy::mayReply('u', 'b', $b, null))->toBeFalse();
});

it('reply submission cannot overwrite pending, approved or stale versions', function () {
    expect(Policy::replyVersion(null, 0))->toBe(1)->and(Policy::replyVersion(['status' => 'rejected', 'version' => 2], 2))->toBe(3);
    foreach (['pending', 'approved'] as $status) {
        expect(fn () => Policy::replyVersion(['status' => $status, 'version' => 2], 2))->toThrow(RuntimeException::class);
    }
    expect(fn () => Policy::replyVersion(['status' => 'hidden', 'version' => 3], 2))->toThrow(RuntimeException::class);
    $v = ['businessId' => 'b', 'reviewId' => 'r', 'expectedVersion' => 0, 'text' => 'Teşekkür ederiz.'];
    expect(f5ok(Schemas::replyInput(), $v))->toBeTrue();
    foreach ([['status' => 'approved'], ['userId' => 'other'], ['reviewId' => '../r'], ['text' => str_repeat('x', 2001)]] as $patch) {
        expect(f5ok(Schemas::replyInput(), [...$v, ...$patch]))->toBeFalse();
    }
});

it('photos bind to author, business and submission', function () {
    $r = ['userId' => 'u', 'businessId' => 'b', 'status' => 'approved', 'submissionVersion' => 3];
    $b = ['published' => true];
    $p = ['userId' => 'u', 'businessId' => 'b', 'submissionVersion' => 3, 'status' => 'pending', 'sha256' => 'hash', 'expiresAtMs' => 1000];
    expect(Policy::photoCanUpload('u', $r))->toBeTrue()->and(Policy::photoCanUpload('other', $r))->toBeFalse()->and(Policy::photoCanUpload('u', [...$r, 'status' => 'hidden']))->toBeFalse()->and(Policy::photoIsPublic([...$p, 'status' => 'approved'], $r, $b))->toBeTrue();
    foreach ([['userId' => 'other'], ['businessId' => 'other'], ['submissionVersion' => 4], ['status' => 'hidden']] as $change) {
        expect(Policy::photoIsPublic([...$p, 'status' => 'approved'], [...$r, ...$change], $b))->toBeFalse();
    }
    expect(Policy::photoIsPublic($p, $r, $b))->toBeFalse()->and(Policy::photoIsPublic([...$p, 'status' => 'approved'], $r, ['published' => false]))->toBeFalse();
    expect(Policy::photoMayPublish($p, $r, $b, ['sha256' => 'hash'], true, 999))->toBeTrue()->and(Policy::photoMayPublish($p, $r, $b, ['sha256' => 'hash'], false, 999))->toBeFalse()->and(Policy::photoMayPublish($p, $r, $b, ['sha256' => 'stale'], true, 999))->toBeFalse()->and(Policy::photoMayPublish($p, $r, $b, null, true, 999))->toBeFalse()->and(Policy::photoMayPublish($p, $r, $b, ['sha256' => 'hash'], true, 1000))->toBeFalse()->and(Policy::photoMayPublish($p, [...$r, 'status' => 'pending'], $b, ['sha256' => 'hash'], true, 999))->toBeFalse();
    expect(Policy::photoIds(['../private', 'https://example.com', 'not-id']))->toBe([]);
    expect(Policy::photoCleanupDue(['status' => 'uploading', 'createdAtMs' => 0], 86400000))->toBeTrue()->and(Policy::photoCleanupDue(['status' => 'pending', 'expiresAtMs' => 100], 100))->toBeTrue()->and(Policy::photoCleanupDue(['status' => 'approved', 'expiresAtMs' => 0], 999))->toBeFalse()->and(Policy::photoCleanupDue(['status' => 'purging'], 0))->toBeTrue()->and(Policy::photoCleanupDue(['status' => 'pending'], 999))->toBeFalse();
});

it('claim views hide reviewers and replies are limited to the claimant', function () {
    $view = Policy::ownClaimView('c', ['name' => 'Test', 'city' => 'İstanbul', 'status' => 'needs_information', 'reviewReason' => 'Açıklama gerekli', 'reviewedBy' => 'secret-admin', 'verificationReference' => 'private', 'evidence' => ['private-path']]);
    expect($view['reviewReason'])->toBe('Açıklama gerekli')->and($view)->not->toHaveKey('reviewedBy')->and($view)->not->toHaveKey('verificationReference')->and($view)->not->toHaveKey('evidence');
    expect(Policy::canReplyToClaim('a', ['userId' => 'a', 'status' => 'needs_information']))->toBeTrue()->and(Policy::canReplyToClaim('b', ['userId' => 'a', 'status' => 'needs_information']))->toBeFalse();
    foreach (['submitted', 'approved', 'rejected'] as $status) {
        expect(Policy::canReplyToClaim('a', ['userId' => 'a', 'status' => $status]))->toBeFalse();
    }
    $base = ['claimId' => 'claim-1', 'message' => 'İstenen ek açıklama.'];
    expect(f5ok(Schemas::claimReply(), $base))->toBeTrue();
    foreach ([['status' => 'approved'], ['businessId' => 'b'], ['userId' => 'other']] as $extra) {
        expect(f5ok(Schemas::claimReply(), [...$base, ...$extra]))->toBeFalse();
    }
});

it('private evidence requires a verified claimant or administrator', function () {
    $c = ['userId' => 'owner', 'status' => 'submitted'];
    expect(Policy::canReadEvidence('other', false, true, $c))->toBeFalse()->and(Policy::canReadEvidence('owner', false, true, $c))->toBeTrue()->and(Policy::canReadEvidence('admin', true, false, $c))->toBeFalse()->and(Policy::canUploadEvidence('admin', true, $c))->toBeFalse()->and(Policy::canUploadEvidence('owner', true, [...$c, 'status' => 'approved']))->toBeFalse();
    $e = ['claimId' => 'c', 'userId' => 'u', 'status' => 'ready', 'expiresAtMs' => 200, 'sha256' => 'abc'];
    $v = ['reviewerId' => 'a', 'sha256' => 'abc'];
    expect(Policy::reviewableEvidence($e, 'c', 'u', $v, 'a', 100))->toBeTrue();
    foreach ([['claimId' => 'other'], ['userId' => 'other'], ['status' => 'uploading'], ['expiresAtMs' => 50]] as $x) {
        expect(Policy::reviewableEvidence([...$e, ...$x], 'c', 'u', $v, 'a', 100))->toBeFalse();
    }
    expect(Policy::reviewableEvidence($e, 'c', 'u', [...$v, 'sha256' => 'old'], 'a', 100))->toBeFalse()->and(Policy::reviewableEvidence($e, 'c', 'u', null, 'a', 100))->toBeFalse();
    $p = ['evidenceRevision' => 2, 'expiresAtMs' => 200];
    $claim = ['evidenceRevision' => 2, 'pendingEvidenceUploads' => 0];
    expect(Policy::currentEvidenceProof($p, $claim, 100))->toBeTrue()->and(Policy::currentEvidenceProof($p, [...$claim, 'evidenceRevision' => 3], 100))->toBeFalse()->and(Policy::currentEvidenceProof($p, [...$claim, 'pendingEvidenceUploads' => 1], 100))->toBeFalse()->and(Policy::currentEvidenceProof($p, $claim, 201))->toBeFalse()->and(Policy::currentEvidenceProof([], $claim, 100))->toBeFalse();
});

function f5profile(): array
{
    return ['name' => 'Test servis', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'address' => 'Test sokak, test adresi 10', 'phone' => '05550100000', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'], 'location' => ['latitude' => 40, 'longitude' => 29]];
}

it('business profile rejects injection, credential urls and invalid location', function () {
    $p = f5profile();
    expect(f5ok(Schemas::businessProfile(), $p))->toBeTrue();
    foreach ([['published' => true], ['ownership' => 'verified'], ['features' => ['quotes' => true]], ['ownerUid' => 'x']] as $extra) {
        expect(f5ok(Schemas::businessProfile(), [...$p, ...$extra]))->toBeFalse();
    }
    expect(f5ok(Schemas::businessProfile(), [...$p, 'website' => 'https://secret:password@example.com']))->toBeFalse()->and(f5ok(Schemas::businessProfile(), [...$p, 'location' => ['latitude' => 100, 'longitude' => 29]]))->toBeFalse()->and(f5ok(Schemas::businessProfile(), [...$p, 'categories' => ['unknown']]))->toBeFalse();
    expect(f5ok(Schemas::businessProfile(), [...$p, 'contactEmail' => 'not-email']))->toBeFalse()->and(f5ok(Schemas::businessProfile(), [...$p, 'contactEmail' => '']))->toBeTrue()->and(f5ok(Schemas::businessProfile(), [...$p, 'website' => 'https://usta.example']))->toBeTrue();
});

it('business edit requires active matching user and verified ownership', function () {
    $m = ['active' => true, 'userId' => 'u1', 'role' => 'owner'];
    expect(Policy::mayEditOwnedProfile('u1', ['ownership' => 'verified'], $m))->toBeTrue()->and(Policy::mayEditOwnedProfile('u2', ['ownership' => 'verified'], $m))->toBeFalse()->and(Policy::mayEditOwnedProfile('u1', ['ownership' => 'unclaimed'], $m))->toBeFalse()->and(Policy::mayEditOwnedProfile('u1', ['ownership' => 'verified'], [...$m, 'active' => false]))->toBeFalse();
});

function f5activation(): array
{
    return ['b' => ['ownerUid' => 'owner', 'ownership' => 'verified', 'published' => true, 'activationVersion' => 0, 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false]], 'request' => ['businessId' => 'b', 'version' => 0, 'action' => 'request', 'features' => ['quotes' => true, 'appointments' => false], 'monitorInbox' => true, 'capacityReady' => true], 'owner' => ['uid' => 'owner', 'owner' => true, 'admin' => false, 'affiliated' => true, 'applicantStillOwner' => true], 'admin' => ['uid' => 'admin', 'owner' => false, 'admin' => true, 'affiliated' => false, 'applicantStillOwner' => true], 'pending' => ['status' => 'pending', 'applicantId' => 'owner', 'features' => ['quotes' => true, 'appointments' => false], 'monitorInbox' => true, 'capacityReady' => true], 'approve' => ['businessId' => 'b', 'version' => 1, 'action' => 'approve', 'reason' => 'Readiness reviewed']];
}

it('activation owner requires exact business, user and active owner', function () {
    ['b' => $b] = f5activation();
    $m = ['businessId' => 'b', 'userId' => 'owner', 'active' => true, 'role' => 'owner'];
    expect(Policy::activationOwner('owner', 'b', $b, $m))->toBeTrue();
    foreach ([['businessId' => 'other'], ['userId' => 'other'], ['active' => false], ['role' => 'staff']] as $patch) {
        expect(Policy::activationOwner('owner', 'b', $b, [...$m, ...$patch]))->toBeFalse();
    }
    expect(Policy::activationOwner('owner', 'b', [...$b, 'ownerUid' => 'new'], $m))->toBeFalse();
});

it('activation request cannot enable features; approval enables requested services only', function () {
    extract(f5activation());
    $n = Policy::activationTransition($b, null, $request, $owner);
    expect($n['business'])->toBe(['activationVersion' => 1]);
    $approved = Policy::activationTransition([...$b, ...$n['business']], $pending, $approve, $admin);
    expect($approved['business']['ownerReady'])->toBeTrue()->and($approved['business']['features'])->toBe(['quotes' => true, 'appointments' => false]);
});

it('activation input is strict', function () {
    ['request' => $request] = f5activation();
    expect(f5ok(Schemas::activationAction(), $request))->toBeTrue();
    foreach ([['monitorInbox' => false], ['capacityReady' => false], ['features' => ['quotes' => false, 'appointments' => false]], ['version' => -1], ['ownerReady' => true]] as $patch) {
        expect(f5ok(Schemas::activationAction(), [...$request, ...$patch]))->toBeFalse();
    }
});

it('approval rejects affiliation, revoked owner and hidden or sample business', function () {
    extract(f5activation());
    $active = [...$b, 'activationVersion' => 1];
    foreach ([[...$admin, 'affiliated' => true], [...$admin, 'uid' => 'owner'], [...$admin, 'applicantStillOwner' => false], [...$admin, 'admin' => false]] as $who) {
        expect(fn () => Policy::activationTransition($active, $pending, $approve, $who))->toThrow(RuntimeException::class);
    }
    foreach ([['sample' => true], ['published' => false], ['ownership' => 'unclaimed']] as $patch) {
        expect(fn () => Policy::activationTransition([...$active, ...$patch], $pending, $approve, $admin))->toThrow(RuntimeException::class);
    }
    expect(fn () => Policy::activationTransition($active, [...$pending, 'monitorInbox' => false], $approve, $admin))->toThrow(RuntimeException::class);
});

it('pause turns off flags; rejection and replacement preserve live services', function () {
    extract(f5activation());
    $paused = Policy::activationTransition([...$b, 'activationVersion' => 1], $pending, [...$approve, 'action' => 'pause'], $owner);
    expect($paused['business'])->toBe(['activationVersion' => 2, 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false]]);
    expect(fn () => Policy::activationTransition([...$b, ...$paused['business']], [...$pending, 'status' => 'paused'], [...$approve, 'version' => 2], $admin))->toThrow(RuntimeException::class, 'stale');
    $live = [...$b, 'activationVersion' => 1, 'ownerReady' => true, 'features' => ['quotes' => true, 'appointments' => true]];
    expect(Policy::activationTransition($live, $pending, [...$approve, 'action' => 'reject'], $admin)['business'])->toBe(['activationVersion' => 2])
        ->and(Policy::activationTransition($live, $pending, [...$request, 'version' => 1], $owner)['business'])->toBe(['activationVersion' => 2])
        ->and(fn () => Policy::activationTransition($live, $pending, $request, $owner))->toThrow(RuntimeException::class)
        ->and(fn () => Policy::activationTransition($b, null, $request, $admin))->toThrow(RuntimeException::class);
});

it('authorization precedes stale-version disclosure', function () {
    extract(f5activation());
    $stale = [...$b, 'activationVersion' => 9];
    foreach ([$request, ['businessId' => 'b', 'version' => 0, 'action' => 'pause', 'reason' => 'TEST pause reason'], ['businessId' => 'b', 'version' => 0, 'action' => 'approve', 'reason' => 'TEST approval reason']] as $action) {
        expect(fn () => Policy::activationTransition($stale, $pending, $action, [...$owner, 'owner' => false]))->toThrow(RuntimeException::class, 'forbidden');
    }
    expect(fn () => Policy::activationTransition($stale, $pending, $request, $owner))->toThrow(RuntimeException::class, 'stale')
        ->and(fn () => Policy::activationTransition($stale, $pending, ['businessId' => 'b', 'version' => 0, 'action' => 'approve', 'reason' => 'TEST approval reason'], [...$admin, 'affiliated' => true]))->toThrow(RuntimeException::class, 'forbidden');
});

it('activation projection omits applicants and permits paused history', function () {
    $b = ['name' => 'TEST Workshop', 'ownerUid' => 'alice', 'ownership' => 'verified', 'published' => true, 'sample' => false, 'activationVersion' => 0, 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false]];
    $m = ['businessId' => 'b1', 'userId' => 'alice', 'active' => true, 'role' => 'owner'];
    expect(Policy::projectActivation('alice', 'b1', $b, $m))->toBe(['id' => 'b1', 'name' => 'TEST Workshop', 'version' => 0, 'published' => true, 'features' => ['quotes' => false, 'appointments' => false], 'ownerReady' => false, 'request' => null]);
    $paused = Policy::projectActivation('alice', 'b1', $b, $m, ['status' => 'paused', 'reason' => 'TEST paused before a request', 'applicantId' => 'PRIVATE']);
    expect($paused['request'])->toBe(['status' => 'paused', 'reason' => 'TEST paused before a request']);
    expect(Policy::projectActivation('bob', 'b1', $b, $m))->toBeNull()->and(Policy::projectActivation('alice', 'b1', $b, [...$m, 'role' => 'staff']))->toBeNull();
    expect(Policy::mayManageActivation('alice', 'b1', [...$b, 'published' => false], $m))->toBeTrue()->and(Policy::mayManageActivation('alice', 'b1', [...$b, 'sample' => true], $m))->toBeFalse();
});

it('console branches require a canonical active membership per scope', function () {
    $b = ['name' => 'TEST Workshop', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'ownerUid' => 'alice', 'ownership' => 'verified', 'published' => true, 'sample' => false];
    $m = ['businessId' => 'b1', 'userId' => 'alice', 'active' => true, 'role' => 'owner'];
    expect(Policy::projectConsoleBranch('alice', 'alice_b1', $m, $b, 'activation')['id'])->toBe('b1');
    foreach ([[[...$m, 'role' => 'staff'], $b], [[...$m, 'active' => false], $b], [[...$m, 'businessId' => 'b2'], $b], [$m, [...$b, 'ownerUid' => 'bob']], [$m, [...$b, 'sample' => true]], [$m, [...$b, 'ownership' => 'unclaimed']]] as [$mm, $bb]) {
        expect(Policy::projectConsoleBranch('alice', 'alice_b1', $mm, $bb, 'activation'))->toBeNull();
    }
    expect(Policy::projectConsoleBranch('alice', 'other_b1', $m, $b, 'activation'))->toBeNull();
    $unpublished = [...$b, 'published' => false];
    expect(Policy::projectConsoleBranch('alice', 'alice_b1', $m, $unpublished))->toBeNull()->and(Policy::projectConsoleBranch('alice', 'alice_b1', $m, $unpublished, true)['id'])->toBe('b1');
    foreach ([['sample' => true], ['ownership' => 'unclaimed']] as $patch) {
        expect(Policy::projectConsoleBranch('alice', 'alice_b1', $m, [...$unpublished, ...$patch], true))->toBeNull();
    }
    expect(Policy::projectConsoleBranch('alice', 'alice_b1', $m, $b, 'campaigns'))->not->toBeNull();
});

function f5pid(int $n): string
{
    return '00000000-0000-4000-8000-'.str_pad((string) $n, 12, '0', STR_PAD_LEFT);
}

it('business photos require an exact active owner and never staff or sample', function () {
    $bid = 'b1';
    $m = ['userId' => 'alice', 'businessId' => $bid, 'active' => true, 'role' => 'owner'];
    $b = ['ownerUid' => 'alice', 'ownership' => 'verified', 'published' => true, 'sample' => false, 'businessPhotoSlots' => [f5pid(1), f5pid(2)], 'publicBusinessPhotoIds' => [f5pid(1), f5pid(2)], 'businessCoverPhotoId' => f5pid(1)];
    expect(Policy::businessPhotoOwner('alice', $bid, $b, $m))->toBeTrue();
    foreach ([[[...$b, 'sample' => true], $m], [[...$b, 'ownerUid' => 'bob'], $m], [[...$b, 'ownership' => 'unclaimed'], $m], [$b, [...$m, 'role' => 'staff']], [$b, [...$m, 'userId' => 'bob']], [$b, [...$m, 'businessId' => 'b2']], [$b, [...$m, 'active' => false]]] as [$bb, $mm]) {
        expect(Policy::businessPhotoOwner('alice', $bid, $bb, $mm))->toBeFalse();
    }
    expect(Policy::businessPhotoOwner('alice', $bid, [...$b, 'published' => false], $m))->toBeTrue();
});

it('public media needs an approved referenced photo, current owner and publication', function () {
    $id = f5pid(1);
    $bid = 'b1';
    $m = ['userId' => 'alice', 'businessId' => $bid, 'active' => true, 'role' => 'owner'];
    $b = ['ownerUid' => 'alice', 'ownership' => 'verified', 'published' => true, 'sample' => false, 'publicBusinessPhotoIds' => [$id]];
    $p = ['id' => $id, 'businessId' => $bid, 'ownerUid' => 'alice', 'status' => 'approved'];
    expect(Policy::businessPhotoPublic($p, $b, $m))->toBeTrue();
    foreach ([['status' => 'pending'], ['status' => 'hidden'], ['status' => 'withdrawn'], ['ownerUid' => 'bob'], ['id' => $id.'x']] as $patch) {
        expect(Policy::businessPhotoPublic([...$p, ...$patch], $b, $m))->toBeFalse();
    }
    foreach ([[...$b, 'published' => false], [...$b, 'ownerUid' => 'bob'], [...$b, 'sample' => true], [...$b, 'publicBusinessPhotoIds' => []]] as $bb) {
        expect(Policy::businessPhotoPublic($p, $bb, $m))->toBeFalse();
    }
    expect(Policy::businessPhotoPublic($p, $b, [...$m, 'active' => false]))->toBeFalse();
});

it('photo references reject paths, duplicates and more than eight entries', function () {
    expect(Policy::businessPhotoIds(['../x', f5pid(1), f5pid(1), f5pid(2)]))->toBe([f5pid(1), f5pid(2)])
        ->and(Policy::businessPhotoIds(array_map('f5pid', range(0, 11))))->toHaveCount(8)
        ->and(Policy::businessPhotoIds('not-array'))->toBe([]);
});

it('upload reservation is bounded by slots and a rolling attempts window', function () {
    $now = 1793000000000;
    $id = f5pid(1);
    expect(Policy::businessPhotoReservation(['businessPhotoSlots' => []], $id, $now))->toBe(['businessPhotoSlots' => [$id], 'photoAttempts' => 1, 'photoAttemptWindow' => $now]);
    $quota = ['photoAttempts' => 40, 'photoAttemptWindow' => $now - 1];
    expect(Policy::businessPhotoAttempts($quota, $now))->toBe(40)
        ->and(fn () => Policy::businessPhotoReservation($quota, $id, $now))->toThrow(RuntimeException::class, 'photo_capacity')
        ->and(Policy::businessPhotoAttempts([...$quota, 'photoAttemptWindow' => $now - 30 * 86400000], $now))->toBe(0)
        ->and(Policy::businessPhotoReservation([...$quota, 'photoAttemptWindow' => $now - 30 * 86400000], $id, $now)['photoAttempts'])->toBe(1)
        ->and(fn () => Policy::businessPhotoReservation(['businessPhotoSlots' => array_map(static fn (int $i): string => f5pid($i + 10), range(0, 7))], $id, $now))->toThrow(RuntimeException::class);
});

it('withdraw unlinks public, cover and slot; hide preserves slot and other cover', function () {
    $b = ['businessPhotoSlots' => [f5pid(1), f5pid(2)], 'publicBusinessPhotoIds' => [f5pid(1), f5pid(2)], 'businessCoverPhotoId' => f5pid(1)];
    expect(Policy::businessPhotoUnlink($b, f5pid(1), true))->toBe(['publicBusinessPhotoIds' => [f5pid(2)], 'businessCoverPhotoId' => f5pid(2), 'businessPhotoSlots' => [f5pid(2)]])
        ->and(Policy::businessPhotoUnlink($b, f5pid(2), false))->toBe(['publicBusinessPhotoIds' => [f5pid(1)], 'businessCoverPhotoId' => f5pid(1)])
        ->and(Policy::businessPhotoUnlink([...$b, 'publicBusinessPhotoIds' => [f5pid(1)]], f5pid(1), true)['businessCoverPhotoId'])->toBeNull();
});

it('approval needs the exact full-image hash, rights, owner, slot and pending state', function () {
    $id = f5pid(1);
    $sha = str_repeat('a', 64);
    $now = 1793000000000;
    $m = ['userId' => 'alice', 'businessId' => 'b1', 'active' => true, 'role' => 'owner'];
    $b = ['ownerUid' => 'alice', 'ownership' => 'verified', 'published' => true, 'sample' => false, 'businessPhotoSlots' => [$id], 'publicBusinessPhotoIds' => []];
    $p = ['id' => $id, 'businessId' => 'b1', 'ownerUid' => 'alice', 'rightsAttested' => true, 'status' => 'pending', 'version' => 1, 'sha256' => $sha, 'expiresAtMs' => $now + 90000];
    expect(Policy::businessPhotoMayApprove($p, $b, $m, ['sha256' => $sha], true, $now))->toBeTrue();
    foreach ([['status' => 'approved'], ['rightsAttested' => false], ['expiresAtMs' => $now], ['sha256' => 'invalid'], ['ownerUid' => 'bob']] as $patch) {
        expect(Policy::businessPhotoMayApprove([...$p, ...$patch], $b, $m, ['sha256' => $sha], true, $now))->toBeFalse();
    }
    expect(Policy::businessPhotoMayApprove($p, $b, $m, ['sha256' => str_repeat('b', 64)], true, $now))->toBeFalse()->and(Policy::businessPhotoMayApprove($p, $b, $m, ['thumbnailSha256' => $sha], true, $now))->toBeFalse()->and(Policy::businessPhotoMayApprove($p, $b, $m, ['sha256' => $sha], false, $now))->toBeFalse()->and(Policy::businessPhotoMayApprove($p, [...$b, 'published' => false], $m, ['sha256' => $sha], true, $now))->toBeFalse()->and(Policy::businessPhotoMayApprove($p, [...$b, 'businessPhotoSlots' => []], $m, ['sha256' => $sha], true, $now))->toBeFalse()->and(Policy::businessPhotoMayApprove($p, $b, [...$m, 'active' => false], ['sha256' => $sha], true, $now))->toBeFalse();
});

it('private expiry excludes approved media and reviewer privilege is strict', function () {
    $now = 1793000000000;
    $p = ['status' => 'pending', 'expiresAtMs' => $now + 90000, 'ownerUid' => 'alice', 'createdAtMs' => $now];
    expect(Policy::businessPhotoReadable($p, $now))->toBeTrue()->and(Policy::businessPhotoReadable([...$p, 'expiresAtMs' => $now], $now))->toBeFalse()->and(Policy::businessPhotoReadable([...$p, 'status' => 'uploading'], $now))->toBeFalse();
    expect(Policy::businessPhotoCleanupDue([...$p, 'status' => 'approved', 'expiresAtMs' => 1], $now))->toBeFalse()->and(Policy::businessPhotoCleanupDue([...$p, 'status' => 'uploading', 'createdAtMs' => $now - 86400000], $now))->toBeTrue()->and(Policy::businessPhotoCleanupDue([...$p, 'status' => 'withdrawn', 'expiresAtMs' => $now + 1], $now))->toBeFalse();
    $b = ['ownerUid' => 'alice'];
    expect(Policy::businessPhotoReviewer('reviewer', true, $p, $b, null))->toBeTrue()->and(Policy::businessPhotoReviewer('alice', true, $p, $b, ['active' => true]))->toBeFalse()->and(Policy::businessPhotoReviewer('bob', true, [...$p, 'ownerUid' => 'bob'], $b, null))->toBeFalse()->and(Policy::businessPhotoReviewer('staff', true, $p, $b, ['active' => true]))->toBeFalse()->and(Policy::businessPhotoReviewer('reviewer', 'true', $p, $b, null))->toBeFalse()->and(Policy::businessPhotoReviewer('reviewer', false, $p, $b, null))->toBeFalse();
});

it('builds deterministic ids exactly like JSON.stringify', function () {
    expect(App\Domain\Content\Ids::sha(['u', 'b']))->toBe(hash('sha256', '["u","b"]'))
        ->and(App\Domain\Content\Ids::stringify(['İ/ç', "a\u{2028}"]))->toBe('["İ/ç","a'."\u{2028}".'"]')
        ->and(App\Domain\Content\Ids::auto())->toMatch('/^[A-Za-z0-9]{20}$/')
        ->and(App\Domain\Content\Digest::of(['b' => 1, 'a' => [2, ['y' => true, 'x' => null]]]))->toBe(hash('sha256', '{"a":[2,{"x":null,"y":true}],"b":1}'));
});
