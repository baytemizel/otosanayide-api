<?php

use App\Domain\Admin\AdminPolicy;
use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\AiModeration;
use App\Domain\Admin\ClaimReviewConflict;
use App\Support\ZodError;
use Illuminate\Support\Facades\Http;

// packages/core: claim-review, claim-target, business-management, catalogue-publication/draft/history, content-moderation.

function f6ok(mixed $schema, array $v): bool
{
    try {
        $schema->parse(json_decode(json_encode($v)));

        return true;
    } catch (ZodError) {
        return false;
    }
}

function f6approval(): array
{
    return AdminSchemas::claimReview()->parse(json_decode(json_encode(['claimId' => 'claim1', 'decision' => 'approve', 'reason' => 'İşletme yetkilisi kanıtı incelendi.', 'evidenceChecked' => true, 'verificationReference' => 'check1'])));
}

it('evidence must be verified and match all three identities', function () {
    $target = ['claimId' => 'claim1', 'businessId' => 'business1', 'userId' => 'owner1'];
    $proof = [...$target, 'status' => 'verified'];
    expect(AdminPolicy::matchesVerifiedEvidence($proof, $target))->toBeTrue()->and(AdminPolicy::matchesVerifiedEvidence(null, $target))->toBeFalse();
    foreach ([['status' => 'pending'], ['claimId' => 'other'], ['businessId' => 'other'], ['userId' => 'other']] as $change) {
        expect(AdminPolicy::matchesVerifiedEvidence([...$proof, ...$change], $target))->toBeFalse();
    }
});

it('claim approval requires explicit evidence attestation and a reference', function () {
    $a = f6approval();
    expect(f6ok(AdminSchemas::claimReview(), [...$a, 'evidenceChecked' => false]))->toBeFalse()
        ->and(f6ok(AdminSchemas::claimReview(), [...$a, 'verificationReference' => '']))->toBeFalse()
        ->and(f6ok(AdminSchemas::claimReview(), [...$a, 'userId' => 'other']))->toBeFalse()
        ->and(f6ok(AdminSchemas::claimReview(), [...$a, 'claimId' => '../claims']))->toBeFalse();
});

it('reviewer cannot decide own claim and terminal states cannot be replayed', function () {
    $a = f6approval();
    $claim = ['status' => 'submitted', 'userId' => 'owner1', 'businessId' => 'business1'];
    expect(fn () => AdminPolicy::decideClaim($a, $claim, 'owner1', ['ownership' => 'unclaimed']))->toThrow(ClaimReviewConflict::class)
        ->and(fn () => AdminPolicy::decideClaim(['claimId' => 'claim1', 'decision' => 'reject', 'reason' => 'x'], $claim, 'owner1', null))->toThrow(ClaimReviewConflict::class);
    foreach (['approved', 'rejected', 'cancelled', 'unknown'] as $status) {
        expect(fn () => AdminPolicy::decideClaim($a, [...$claim, 'status' => $status], 'admin1', ['ownership' => 'unclaimed']))->toThrow(ClaimReviewConflict::class);
    }
});

it('approval refuses missing, sample or already claimed business', function () {
    $a = f6approval();
    $claim = ['status' => 'submitted', 'userId' => 'owner1', 'businessId' => 'business1'];
    expect(fn () => AdminPolicy::decideClaim($a, $claim, 'admin1', null))->toThrow(ClaimReviewConflict::class)
        ->and(fn () => AdminPolicy::decideClaim($a, [...$claim, 'businessId' => null], 'admin1', ['ownership' => 'unclaimed']))->toThrow(ClaimReviewConflict::class);
    foreach ([['ownership' => 'verified'], ['ownership' => 'unclaimed', 'ownerUid' => 'someone'], ['ownership' => 'unclaimed', 'sample' => true], []] as $business) {
        expect(fn () => AdminPolicy::decideClaim($a, $claim, 'admin1', $business))->toThrow(ClaimReviewConflict::class);
    }
});

it('request information and rejection confer no ownership; eligible claim can be approved', function () {
    $a = f6approval();
    $claim = ['status' => 'submitted', 'userId' => 'owner1', 'businessId' => 'business1'];
    expect(AdminPolicy::decideClaim(['claimId' => 'claim1', 'decision' => 'request_information', 'reason' => 'x'], $claim, 'admin1', null))->toBe('needs_information')
        ->and(AdminPolicy::decideClaim(['claimId' => 'claim1', 'decision' => 'reject', 'reason' => 'x'], $claim, 'admin1', null))->toBe('rejected')
        ->and(AdminPolicy::decideClaim($a, [...$claim, 'status' => 'needs_information'], 'admin1', ['ownership' => 'unclaimed']))->toBe('approved');
});

it('treats a business id of 0 as present like JS', function () {
    expect(AdminPolicy::mayMapClaim(['userId' => 'owner', 'status' => 'submitted', 'businessId' => '0'], 'admin'))->toBeFalse();
});

it('claim mapping rejects self review, bound or terminal claims and pending uploads', function () {
    $c = ['userId' => 'owner', 'status' => 'submitted'];
    expect(AdminPolicy::mayMapClaim($c, 'admin'))->toBeTrue();
    foreach ([[...$c, 'userId' => 'admin'], [...$c, 'businessId' => 'already'], [...$c, 'status' => 'approved'], [...$c, 'pendingEvidenceUploads' => 1]] as $value) {
        expect(AdminPolicy::mayMapClaim($value, 'admin'))->toBeFalse();
    }
});

it('target must be a real unclaimed business without an existing owner', function () {
    expect(AdminPolicy::eligibleClaimTarget(['ownership' => 'unclaimed']))->toBeTrue();
    foreach ([null, ['ownership' => 'verified'], ['ownership' => 'unclaimed', 'sample' => true], ['ownership' => 'unclaimed', 'ownerUid' => 'other']] as $value) {
        expect(AdminPolicy::eligibleClaimTarget($value))->toBeFalse();
    }
});

function f6profile(array $over = []): array
{
    return $over + ['name' => 'TEST Servis', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'address' => 'Test adresi 10 sokak', 'phone' => '05550100000', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => [], 'brands' => [], 'location' => null];
}

it('mapping requires attestations and rejects forged permissions', function () {
    $v = ['mode' => 'new', 'claimId' => 'c', 'reason' => 'Test eşlemesi kontrol edildi.', 'identityChecked' => true, 'duplicateChecked' => true, 'profile' => f6profile()];
    expect(f6ok(AdminSchemas::claimTarget(), $v))->toBeTrue()->and(f6ok(AdminSchemas::claimTarget(), [...$v, 'duplicateChecked' => false]))->toBeFalse()->and(f6ok(AdminSchemas::claimTarget(), [...$v, 'profile' => f6profile(['published' => true])]))->toBeFalse();
    expect(AdminPolicy::businessIdentityText(f6profile()))->toBe(AdminPolicy::businessIdentityText(f6profile(['name' => ' test   servis '])));
});

it('evidence verification refuses duplicates and forged result fields', function () {
    $id = '44444444-4444-4444-8444-444444444444';
    $v = ['claimId' => 'c', 'evidenceIds' => [$id], 'evidenceRevision' => 1, 'attested' => true, 'reason' => 'Belge kontrol edildi.'];
    expect(f6ok(AdminSchemas::evidenceVerification(), $v))->toBeTrue()->and(f6ok(AdminSchemas::evidenceVerification(), [...$v, 'status' => 'verified']))->toBeFalse()->and(f6ok(AdminSchemas::evidenceVerification(), [...$v, 'evidenceIds' => [$id, $id]]))->toBeFalse();
});

it('review decisions reject path injection and unexpected fields', function () {
    expect(f6ok(AdminSchemas::reviewDecision(), ['id' => 'r', 'version' => 1, 'decision' => 'approve', 'reason' => 'İnceleme tamamlandı.', 'userId' => 'other']))->toBeFalse()
        ->and(f6ok(AdminSchemas::reviewDecision(), ['id' => 'r', 'version' => 0, 'decision' => 'hide', 'reason' => 'İnceleme tamamlandı.']))->toBeFalse()
        ->and(f6ok(AdminSchemas::reviewDecision(), ['id' => '../r', 'version' => 1, 'decision' => 'hide', 'reason' => 'İnceleme tamamlandı.']))->toBeFalse()
        ->and(f6ok(AdminSchemas::caseDecision(), ['id' => 'c', 'decision' => 'remove', 'reason' => 'A detailed reason', 'reviewed' => false]))->toBeFalse()
        ->and(f6ok(AdminSchemas::reviewPhotoDecision(), ['id' => '1', 'version' => 1, 'decision' => 'approve', 'reason' => 'Valid reason', 'attested' => true]))->toBeFalse()
        ->and(f6ok(AdminSchemas::businessPhotoDecision(), ['id' => '44444444-4444-4444-8444-444444444444', 'version' => 1, 'decision' => 'approve', 'attested' => true, 'reason' => 'short']))->toBeFalse();
});

it('location or address changes invalidate old coordinate verification', function () {
    $p = f6profile(['location' => ['latitude' => 40, 'longitude' => 29], 'services' => ['Fren'], 'brands' => ['BMW'], 'name' => 'Test servis', 'address' => 'Test sokak, test adresi 10']);
    $old = [...$p, 'latitude' => 40.0, 'longitude' => 29.0, 'coordinateStatus' => 'verified'];
    expect(AdminPolicy::profileProjection($p, $old)['coordinateStatus'])->toBe('verified')
        ->and(AdminPolicy::profileProjection([...$p, 'address' => 'Yeni adres sokak 25'], $old)['coordinateStatus'])->toBe('unverified')
        ->and(AdminPolicy::profileProjection([...$p, 'location' => null], $old)['latitude'])->toBeNull()
        ->and(AdminPolicy::profileProjection($p, $old)['phone'])->toBe('+905550100000')
        ->and(AdminPolicy::profileProjection($p, $old))->not->toHaveKey('published');
});

it('profile decision and draft inputs are strict', function () {
    expect(f6ok(AdminSchemas::profileDecision(), ['businessId' => 'b', 'revisionId' => '44444444-4444-4444-8444-444444444444', 'decision' => 'approve', 'reason' => 'İnceleme tamamlandı.', 'extra' => 1]))->toBeFalse();
});

function f6check(): array
{
    return ['checked' => true, 'reference' => 'Manually reviewed evidence reference'];
}

function f6publication(): array
{
    return AdminSchemas::publicationAction()->parse(json_decode(json_encode(['businessId' => 'test', 'version' => 0, 'profileVersion' => 2, 'action' => 'publish', 'reason' => 'Evidence checked by administrator', 'checks' => ['rights' => f6check(), 'identity' => f6check(), 'location' => f6check(), 'duplicates' => f6check()]])));
}

function f6business(): array
{
    return ['name' => 'Test Turbo', 'city' => 'İstanbul', 'district' => 'Ataşehir', 'address' => 'Test Sanayi Sokak No:1', 'phone' => '05321112233', 'categories' => ['turbo'], 'services' => ['Turbo tamiri'], 'brands' => [], 'latitude' => 40.99, 'longitude' => 29.12, 'profileVersion' => 2, 'publicationVersion' => 0, 'published' => false, 'ownership' => 'unclaimed'];
}

$f6admin = ['uid' => 'reviewer', 'admin' => true, 'affiliated' => false];

it('publication requires four independent attestations and rejects injected status', function () {
    $a = f6publication();
    expect(f6ok(AdminSchemas::publicationAction(), [...$a, 'published' => true]))->toBeFalse()
        ->and(f6ok(AdminSchemas::publicationAction(), [...$a, 'checks' => ['rights' => f6check()]]))->toBeFalse()
        ->and(f6ok(AdminSchemas::publicationAction(), [...$a, 'checks' => [...$a['checks'], 'location' => [...f6check(), 'checked' => false]]]))->toBeFalse();
});

it('approval does not grant ownership, readiness or paid features', function () use ($f6admin) {
    $next = AdminPolicy::publicationTransition(f6business(), f6publication(), $f6admin);
    expect($next['published'])->toBeTrue()->and($next['ownerReady'])->toBeFalse()->and($next['features']['quotes'])->toBeFalse()->and($next)->not->toHaveKey('ownership');
});

it('publication blocks stale profiles, own businesses, demo and incomplete coordinates', function () use ($f6admin) {
    $b = f6business();
    foreach ([[...$b, 'profileVersion' => 3], [...$b, 'ownerUid' => 'reviewer'], [...$b, 'sample' => true], [...$b, 'latitude' => 0], [...$b, 'phone' => '']] as $x) {
        $thrown = false;
        try {
            AdminPolicy::publicationTransition($x, f6publication(), $f6admin);
        } catch (Throwable) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue();
    }
    expect(fn () => AdminPolicy::publicationTransition($b, f6publication(), [...$f6admin, 'affiliated' => true]))->toThrow(RuntimeException::class, 'forbidden')
        ->and(fn () => AdminPolicy::publicationTransition($b, f6publication(), [...$f6admin, 'admin' => false]))->toThrow(RuntimeException::class, 'forbidden');
});

it('withdrawal closes new requests', function () use ($f6admin) {
    $visible = [...f6business(), ...AdminPolicy::publicationTransition(f6business(), f6publication(), $f6admin)];
    $stopped = AdminPolicy::publicationTransition($visible, ['businessId' => 'test', 'version' => 1, 'profileVersion' => 2, 'action' => 'withdraw', 'reason' => 'Address requires another review'], $f6admin);
    expect($stopped['published'])->toBeFalse()->and($stopped['features']['appointments'])->toBeFalse()->and($stopped['publicationVersion'])->toBe(2);
});

function f6draftInput(): array
{
    return AdminSchemas::catalogueDraft()->parse(json_decode(json_encode(['businessId' => 'test', 'profileVersion' => 2, 'publicationVersion' => 0, 'reason' => 'Source details reviewed', 'profile' => ['name' => 'Test Turbo', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'address' => 'Test Sanayi Sokak No:1', 'phone' => '05321112233', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['turbo'], 'services' => ['Turbo tamiri'], 'brands' => [], 'location' => ['latitude' => 40.99, 'longitude' => 29.12]]])));
}

it('draft saves normalized profile but never publishes or verifies coordinates', function () use ($f6admin) {
    $next = AdminPolicy::catalogueDraftTransition(['published' => false, 'ownership' => 'unclaimed', 'profileVersion' => 2, 'publicationVersion' => 0, 'coordinateStatus' => 'verified'], f6draftInput(), $f6admin);
    expect($next['profileVersion'])->toBe(3)->and($next['phone'])->toBe('+905321112233')->and($next['coordinateStatus'])->toBe('unverified')->and($next['features']['quotes'])->toBeFalse()->and($next)->not->toHaveKey('published')->and($next)->not->toHaveKey('ownership');
});

it('draft rejects stale, published, claimed, affiliated and nonadmin changes', function () use ($f6admin) {
    $b = ['published' => false, 'ownership' => 'unclaimed', 'profileVersion' => 2, 'publicationVersion' => 0];
    foreach ([['published' => true], ['ownership' => 'verified'], ['ownerUid' => 'someone'], ['sample' => true], ['profileVersion' => 3], ['publicationVersion' => 1]] as $changes) {
        expect(fn () => AdminPolicy::catalogueDraftTransition([...$b, ...$changes], f6draftInput(), $f6admin))->toThrow(RuntimeException::class);
    }
    expect(fn () => AdminPolicy::catalogueDraftTransition($b, f6draftInput(), [...$f6admin, 'admin' => false]))->toThrow(RuntimeException::class)
        ->and(fn () => AdminPolicy::catalogueDraftTransition($b, f6draftInput(), [...$f6admin, 'affiliated' => true]))->toThrow(RuntimeException::class)
        ->and(f6ok(AdminSchemas::catalogueDraft(), [...f6draftInput(), 'published' => true]))->toBeFalse();
});

it('evidence projection strips raw data and unsafe URLs and coordinates', function () {
    $p = AdminPolicy::catalogueEvidenceProjection(['name' => 'Source', 'source' => 'javascript:alert(1)', 'website' => 'https://user:secret@example.com', 'rawSource' => ['private' => 'hidden'], 'ownerUid' => 'hidden', 'coordinateEvidence' => ['latitude' => INF, 'longitude' => 29, 'source' => 'https://example.com/evidence']]);
    expect($p['source'])->toBeNull()->and($p['website'])->toBeNull()->and($p['coordinates'])->toBeNull()->and($p)->not->toHaveKey('rawSource')->and($p)->not->toHaveKey('ownerUid')->and($p['coordinateSource'])->toBe('https://example.com/evidence');
    expect(AdminPolicy::catalogueEvidenceProjection(null))->toBeNull();
    expect(AdminPolicy::safeHref('HTTPS://Example.COM'))->toBe('https://example.com/');
});

it('quality projection only exposes known statuses from K165/K166 reviews', function () {
    expect(AdminPolicy::qualityEvidenceProjection(['quality_review' => ['decision' => 'K1']]))->toBeNull()->and(AdminPolicy::qualityEvidenceProjection('x'))->toBeNull();
    $q = AdminPolicy::qualityEvidenceProjection(['quality_review' => ['decision' => 'K165', 'internetAttempted' => true, 'checkedAt' => '2026-01-01T00:00:00Z', 'statuses' => ['address' => 'missing', 'phone' => 'secret-status', 'other' => 'missing'], 'sources' => ['https://example.com/a', 'javascript:alert(1)']]]);
    expect($q['statuses'])->toBe([['field' => 'address', 'status' => 'missing']])->and($q['sources'])->toBe(['https://example.com/a'])->and($q['googleVerified'])->toBeFalse()->and($q['checkedAt'])->toBe('2026-01-01T00:00:00Z');
});

it('history allows only safe ids and known query fields', function () {
    expect(f6ok(AdminSchemas::catalogueHistoryQuery(), ['businessId' => 'test', 'after' => 'source-import']))->toBeTrue();
    foreach ([['businessId' => '../private'], ['businessId' => 'test', 'after' => 'bad/path'], ['businessId' => 'test', 'limit' => 1000]] as $input) {
        expect(f6ok(AdminSchemas::catalogueHistoryQuery(), $input))->toBeFalse();
    }
});

it('publication history keeps approved references and strips identities', function () {
    $p = AdminPolicy::catalogueHistoryProjection('test', ['kind' => 'catalogue_publication', 'action' => 'publish', 'reason' => 'Checked', 'version' => 2, 'profileVersion' => 4, 'actorId' => 'private', 'rawSource' => ['secret' => true], 'checks' => ['rights' => ['checked' => true, 'reference' => 'Evidence reference'], 'identity' => ['checked' => false, 'reference' => 'Not checked'], 'unknown' => ['checked' => true, 'reference' => 'Secret']]], '2026-10-19T09:00:00.000Z');
    expect($p['action'])->toBe('publish')->and($p['publicationVersion'])->toBe(2)->and($p['checks'])->toBe([['key' => 'rights', 'reference' => 'Evidence reference']])->and($p)->not->toHaveKey('actorId')->and($p)->not->toHaveKey('rawSource');
});

it('unknown events expose no content and malformed dates and versions are absent', function () {
    $p = AdminPolicy::catalogueHistoryProjection('test', ['kind' => 'private_event', 'reason' => 'Secret', 'profileVersion' => 4, 'checks' => [], 'actorId' => 'private'], 'invalid');
    expect($p['kind'])->toBe('other')->and($p['reason'])->toBe('')->and($p['profileVersion'])->toBeNull()->and($p['createdAt'])->toBeNull();
    $reviewed = AdminPolicy::catalogueHistoryProjection('test', ['kind' => 'profile_reviewed', 'decision' => 'reject', 'profileVersion' => -1], null);
    expect($reviewed['action'])->toBe('reject')->and($reviewed['profileVersion'])->toBeNull();
});

// content-moderation.test.ts: AI

it('AI failures and malformed responses are unavailable, never clean', function () {
    expect(AiModeration::check('test', null))->toBe(['status' => 'unavailable']);
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['flagged' => false]]])]);
    expect(AiModeration::check('test', 'TEST'))->toBe(['status' => 'unavailable']);
});

it('AI transport errors are unavailable', function () {
    Http::fake(['api.openai.com/*' => Http::failedConnection()]);
    expect(AiModeration::check('test', 'TEST'))->toBe(['status' => 'unavailable']);
});

it('AI evaluates original and comparison copies and merges flags', function () {
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'test-model', 'results' => [['flagged' => false, 'categories' => ['harassment' => false]], ['flagged' => true, 'categories' => ['harassment' => true]]]])]);
    expect(AiModeration::check('s.i.k.t.i.r', 'TEST'))->toBe(['status' => 'checked', 'model' => 'test-model', 'flagged' => true, 'categories' => ['harassment']]);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/moderations' && count($r['input']) === 2 && array_keys($r->data()) === ['model', 'input']);
});

it('AI result cannot attach to edited, reviewed or replaced work', function () {
    $expected = ['version' => 2, 'text' => 'review', 'runId' => 'run'];
    $current = ['status' => 'pending', 'version' => 2, 'text' => 'review', 'aiRunId' => 'run'];
    expect(AdminPolicy::mayApplyAiResult($current, $expected))->toBeTrue();
    foreach ([['version' => 3], ['text' => 'edited'], ['status' => 'approved'], ['aiRunId' => 'other']] as $change) {
        expect(AdminPolicy::mayApplyAiResult([...$current, ...$change], $expected))->toBeFalse();
    }
});
