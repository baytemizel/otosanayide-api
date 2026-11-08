<?php

namespace App\Domain\Content;

use App\Domain\Catalogue\Contact;
use App\Domain\Catalogue\Taxonomy;
use App\Support\Z;

/** Kaynaktaki Zod semalari; alan sirasi hata onceligini belirler. */
final class Schemas
{
    /** reviewIdSchema / documentId */
    public static function id(): Z
    {
        return Z::string()->regex('/^[a-zA-Z0-9_-]{1,120}$/');
    }

    public static function uuid(): Z
    {
        return Z::string()->uuid();
    }

    public static function membershipCursor(): Z
    {
        return Z::string()->regex('/^[A-Za-z0-9_-]{1,260}$/');
    }

    private static function score(): Z
    {
        return Z::coerceNumber()->int()->min(1)->max(5);
    }

    /** reviewSchema */
    public static function review(): Z
    {
        return Z::strictObject([
            'businessId' => Z::string()->min(1),
            'rating' => self::score(),
            'quality' => self::score()->optional(),
            'payment' => self::score()->optional(),
            'cleanliness' => self::score()->optional(),
            'resolved' => Z::enum(['yes', 'partial', 'no', 'unknown']),
            'wc' => Z::enum(['yes', 'no', 'unknown']),
            'wcCleanliness' => self::score()->optional(),
            'text' => Z::string()->trim()->min(10)->max(3000),
        ])->refine(static fn (array $v): bool => $v['wc'] === 'yes' || ! array_key_exists('wcCleanliness', $v), 'WC temizliği sadece WC kullanımı belirtildiğinde puanlanabilir.');
    }

    public static function authorReviewAction(): Z
    {
        return Z::discriminatedUnion('action', [
            'edit' => Z::strictObject([
                'action' => Z::literal('edit'),
                'businessId' => self::id(),
                'expectedVersion' => Z::number()->int()->positive(),
                'review' => self::review(),
            ]),
            'withdraw' => Z::strictObject([
                'action' => Z::literal('withdraw'),
                'businessId' => self::id(),
                'expectedVersion' => Z::number()->int()->positive(),
                'confirmed' => Z::literal(true),
            ]),
        ])->refine(static fn (array $v): bool => $v['action'] !== 'edit' || $v['businessId'] === $v['review']['businessId'], 'Yorum işletmesi değiştirilemez.');
    }

    public static function caseInput(): Z
    {
        return Z::strictObject([
            'reviewId' => self::id(),
            'kind' => Z::enum(['report', 'appeal']),
            'text' => Z::string()->trim()->min(10)->max(2000),
        ]);
    }

    /** claimSchema; \s JS anlaminda Unicode bosluklari da icerir. */
    public static function claim(): Z
    {
        return Z::strictObject([
            'businessId' => Z::string()->regex('/^[a-zA-Z0-9_-]{1,120}$/')->optional(),
            'name' => Z::string()->trim()->min(2)->max(160),
            'city' => Z::string()->refine(static fn (string $s): bool => in_array($s, Taxonomy::cities(), true)),
            'address' => Z::string()->trim()->min(10)->max(500),
            'contactName' => Z::string()->trim()->min(2)->max(100),
            'phone' => Z::string()->regex('/^\+?[0-9\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}()\-]{10,20}$/u'),
            'note' => Z::string()->trim()->max(2000),
        ]);
    }

    public static function claimReply(): Z
    {
        return Z::strictObject(['claimId' => self::id(), 'message' => Z::string()->trim()->min(10)->max(2000)]);
    }

    public static function evidenceKind(): Z
    {
        return Z::enum(['registration', 'authorization', 'workplace']);
    }

    public static function evidenceWithdraw(): Z
    {
        return Z::strictObject(['claimId' => self::id(), 'id' => self::uuid(), 'action' => Z::literal('withdraw')]);
    }

    public static function reviewPhotoWithdraw(): Z
    {
        return Z::strictObject(['id' => self::uuid(), 'version' => Z::number()->int()->positive()]);
    }

    public static function replyInput(): Z
    {
        return Z::strictObject([
            'businessId' => self::id(),
            'reviewId' => self::id(),
            'expectedVersion' => Z::number()->int()->nonnegative(),
            'text' => Z::string()->trim()->min(10)->max(2000),
        ]);
    }

    public static function replyWithdraw(): Z
    {
        return Z::strictObject([
            'businessId' => self::id(),
            'reviewId' => self::id(),
            'expectedVersion' => Z::number()->int()->nonnegative(),
        ]);
    }

    /** new URL(s) + http/https + kullanici adi/parola yok. */
    public static function websiteAllowed(string $s): bool
    {
        if ($s === '') {
            return true;
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\s\/?#]/', $s) !== 1) {
            return false;
        }
        $u = parse_url($s);
        if (! is_array($u) || empty($u['host']) || ! in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if (preg_match('/[\s<>^|\\\\]/', $u['host']) === 1) {
            return false;
        }

        return ! isset($u['user']) && ! isset($u['pass']);
    }

    public static function businessProfile(): Z
    {
        return Z::strictObject([
            'name' => Z::string()->trim()->min(2)->max(160),
            'city' => Z::string()->refine(static fn (string $s): bool => in_array($s, Taxonomy::cities(), true)),
            'district' => Z::string()->trim()->min(2)->max(100),
            'address' => Z::string()->trim()->min(10)->max(500),
            'phone' => Z::string()->trim()->refine(static fn (string $s): bool => Contact::phone($s) !== null, 'Telefon biçimini kontrol et.'),
            'website' => Z::string()->trim()->max(500)->refine(self::websiteAllowed(...), 'Geçerli bir http/https adresi gir.'),
            'contactEmail' => Z::string()->trim()->max(254)->refine(static fn (string $s): bool => $s === '' || Contact::businessEmail($s) !== null, 'Geçerli bir işletme e-posta adresi gir.')->optional(),
            'hours' => Z::string()->trim()->max(300),
            'description' => Z::string()->trim()->max(2000),
            'categories' => Z::array(Z::string()->refine(static fn (string $s): bool => Taxonomy::category($s) !== null))->min(1)->max(12),
            'services' => Z::array(Z::string()->trim()->min(2)->max(120))->max(50),
            'brands' => Z::array(Z::string()->trim()->min(1)->max(80))->max(100),
            'location' => self::location(),
        ]);
    }

    public static function location(): Z
    {
        return Z::strictObject([
            'latitude' => Z::number()->min(-90)->max(90),
            'longitude' => Z::number()->min(-180)->max(180),
        ])->nullable();
    }

    public static function businessChange(): Z
    {
        return Z::strictObject([
            'businessId' => self::id(),
            'expectedVersion' => Z::number()->int()->nonnegative(),
            'expectedRevisionId' => self::uuid()->nullable(),
            'profile' => self::businessProfile(),
        ]);
    }

    /** feature-activation.ts activationAction */
    public static function activationAction(): Z
    {
        $base = ['businessId' => self::id(), 'version' => Z::number()->int()->nonnegative()];
        $decision = Z::strictObject($base + ['action' => Z::enum(['approve', 'reject', 'pause']), 'reason' => Z::string()->trim()->min(10)->max(1000)]);

        return Z::discriminatedUnion('action', [
            'request' => Z::strictObject($base + [
                'action' => Z::literal('request'),
                'features' => Z::strictObject(['quotes' => Z::boolean(), 'appointments' => Z::boolean()])->refine(static fn (array $v): bool => $v['quotes'] || $v['appointments']),
                'monitorInbox' => Z::literal(true),
                'capacityReady' => Z::literal(true),
            ]),
            'approve' => $decision,
            'reject' => $decision,
            'pause' => $decision,
        ]);
    }

    public static function placeQuery(): Z
    {
        return Z::strictObject([
            'businessId' => self::id(),
            'query' => Z::string()->trim()->min(3)->max(200),
            'locale' => Z::enum(['tr', 'en']),
        ]);
    }

    public static function businessPhotoAction(): Z
    {
        return Z::strictObject([
            'businessId' => self::id(),
            'id' => self::uuid(),
            'version' => Z::number()->int()->positive()->max(9007199254740991),
            'action' => Z::enum(['withdraw', 'cover']),
        ]);
    }

    /** businessPhotoItem: ownPhoto.extend(...) */
    public static function businessPhotoItem(): Z
    {
        return Z::strictObject([
            'id' => self::uuid(),
            'status' => Z::enum(['uploading', 'pending', 'approved', 'rejected', 'hidden', 'failed']),
            'version' => Z::number()->int()->positive()->max(9007199254740991),
            'width' => Z::number()->int()->min(0)->max(1600),
            'height' => Z::number()->int()->min(0)->max(1600),
            'bytes' => Z::number()->int()->min(0)->max(2500000),
            'reason' => Z::string()->max(2000),
            'expiresAtMs' => Z::number()->int()->positive()->nullable(),
            'canOpen' => Z::boolean(),
            'canCover' => Z::boolean(),
            'public' => Z::boolean(),
        ]);
    }

    /** activationView; okuma projeksiyonu. Hata tasimaz, null doner. */
    public static function activationView(): Z
    {
        $flags = Z::strictObject(['quotes' => Z::boolean(), 'appointments' => Z::boolean()]);

        return Z::strictObject([
            'id' => self::id(),
            'name' => Z::string()->min(1)->max(200),
            'version' => Z::number()->int()->nonnegative()->max(9007199254740991),
            'published' => Z::boolean(),
            'features' => $flags,
            'ownerReady' => Z::boolean(),
            'request' => Z::strictObject([
                'status' => Z::enum(['pending', 'approved', 'rejected', 'paused']),
                'features' => $flags->optional(),
                'monitorInbox' => Z::boolean()->optional(),
                'capacityReady' => Z::boolean()->optional(),
                'reason' => Z::string()->max(1000),
            ])->nullable(),
        ]);
    }

    /** consoleBranch */
    public static function consoleBranch(): Z
    {
        return Z::strictObject([
            'id' => self::id(),
            'name' => Z::string()->min(1)->max(200),
            'city' => Z::string()->max(100),
            'district' => Z::string()->max(100),
            'role' => Z::enum(['owner', 'staff']),
        ]);
    }

    /** managedProfile; eksik eski profiller okunabilir, yazimlar businessProfile kullanir. */
    public static function managedProfile(): Z
    {
        return Z::strictObject([
            'name' => Z::string()->max(160),
            'city' => Z::string()->max(100),
            'district' => Z::string()->max(100),
            'address' => Z::string()->max(500),
            'phone' => Z::string()->max(100),
            'contactEmail' => Z::string()->max(254)->optional(),
            'website' => Z::string()->max(500),
            'hours' => Z::string()->max(300),
            'description' => Z::string()->max(2000),
            'categories' => Z::array(Z::string()->max(120))->max(12),
            'services' => Z::array(Z::string()->max(120))->max(50),
            'brands' => Z::array(Z::string()->max(80))->max(100),
            'location' => self::location(),
            'id' => self::id(),
            'role' => Z::enum(['owner', 'staff']),
            'version' => Z::number()->int()->nonnegative()->max(9007199254740991),
            'change' => Z::strictObject([
                'revisionId' => self::uuid(),
                'status' => Z::enum(['pending', 'approved', 'rejected']),
                'profile' => self::businessProfile(),
                'reason' => Z::string()->max(1000),
            ])->nullable(),
        ]);
    }
}
