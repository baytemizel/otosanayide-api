<?php

namespace App\Domain\Auth;

use App\Exceptions\HttpError;
use App\Support\Origin;
use Illuminate\Http\Request;

/**
 * Oturum tasima kanali (Sozlesme Eki v1, docs/AUTH_API.md):
 *  web     Origin = PUBLIC_WEB_ORIGIN (veya loopback esdegeri)  -> otosanayide_refresh cerezi
 *  portal  Origin = PORTAL_WEB_ORIGIN                           -> otosanayide_portal_refresh cerezi
 *  native  Origin yok + X-Client-Platform ios|android + X-Client-Version semver -> govdede refreshToken
 * Origin basligi varsa yenileme jetonu govdede asla donmez; yabanci origin OriginGuard'da 403 alir.
 */
final class AuthClient
{
    public const WEB_COOKIE = 'otosanayide_refresh';

    public const PORTAL_COOKIE = 'otosanayide_portal_refresh';

    public const PLATFORMS = ['ios', 'android'];

    private const SEMVER = '/^(?:0|[1-9]\d{0,8})\.(?:0|[1-9]\d{0,8})\.(?:0|[1-9]\d{0,8})(?:-[0-9A-Za-z.-]{1,32})?(?:\+[0-9A-Za-z.-]{1,32})?$/D';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $platform = null,
        public readonly ?string $version = null,
    ) {}

    /** Oturum veren/tuketen uclar (login, mfa/verify, refresh, logout) icin; kanal belirsizse 400. */
    public static function of(Request $request): self
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            return new self(Origin::isPortal($origin) ? 'portal' : 'web');
        }
        $platform = $request->headers->get('X-Client-Platform');
        $version = $request->headers->get('X-Client-Version');
        if (! in_array($platform, self::PLATFORMS, true) || ! is_string($version) || preg_match(self::SEMVER, $version) !== 1) {
            throw new HttpError(400, 'Geçersiz istek.');
        }

        return new self('native', $platform, $version);
    }

    /** E-posta baglantisinin kurulacagi origin: portaldan baslatildiysa portal, aksi halde (native dahil) web. */
    public static function linkOrigin(Request $request): string
    {
        $origin = $request->headers->get('Origin');

        return Origin::isPortal($origin) ? (string) $origin : (string) config('otosanayide.public_web_origin');
    }

    public function native(): bool
    {
        return $this->kind === 'native';
    }

    /** Native icin null: cerez kurulmaz ve okunmaz. */
    public function cookie(): ?string
    {
        return match ($this->kind) {
            'web' => self::WEB_COOKIE,
            'portal' => self::PORTAL_COOKIE,
            default => null,
        };
    }
}
