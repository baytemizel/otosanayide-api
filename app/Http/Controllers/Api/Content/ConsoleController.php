<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Content\Console;
use App\Exceptions\HttpError;
use App\Support\Query;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Kaynak dosyadaki uc hata metni bozuk kodlanmistir (mojibake); sozlesme geregi birebir korunur.
 * Duzeltilmek istenirse yalniz bu sabitler degisir.
 */
final class ConsoleController
{
    private const FORBIDDEN = "Bu i\u{C5}\u{178}lem i\u{C3}\u{A7}in yetkiniz yok.";

    private const INVALID = "Ge\u{C3}\u{A7}ersiz istek.";

    private const VERIFY = "E-posta do\u{C4}\u{178}rulamas\u{C4}\u{B1} gerekli.";

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, OPTIONS', Respond::READ_HEADERS);
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            try {
                HttpInput::console($r, true);
            } catch (Throwable) {
                throw new HttpError(400, self::INVALID);
            }
            $u = ActorResolver::resolve($r);
            if (! $u->emailVerified) {
                throw new HttpError(403, self::VERIFY);
            }
            RateLimit::consume($u->uid);
            $q = Query::from($r);
            $after = $q->get('after');
            $scopeParam = $q->get('scope');
            $scope = $scopeParam === 'campaigns' ? 'campaigns' : ($scopeParam === 'activation' ? 'activation' : $scopeParam === 'profile');

            return Respond::json(Console::branches($u, ($after !== null && $after !== '') ? $after : null, $scope));
        }, self::FORBIDDEN);
    }
}
