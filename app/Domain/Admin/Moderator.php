<?php

namespace App\Domain\Admin;

use App\Domain\Auth\Actor;
use App\Domain\Auth\ActorResolver;
use App\Domain\Content\Moderation;
use App\Exceptions\HttpError;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use stdClass;

/** Tum admin uclarinin giris kapisi ve ortak yardimcilari. */
final class Moderator
{
    public const REQUIRED = 'Yönetici yetkisi gerekli.';

    public const REQUIRED_VERIFIED = 'Yönetici yetkisi ve doğrulanmış e-posta gerekli.';

    /** admin(req): token -> rol+e-posta -> limit. */
    public static function admin(Request $request, string $message = self::REQUIRED): Actor
    {
        $u = ActorResolver::resolve($request);
        if (! $u->admin || ! $u->emailVerified) {
            throw new HttpError(403, $message);
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    /** @return array{status:string,flagged?:bool,categories?:list<string>,model?:string} */
    public static function ai(stdClass $row): array
    {
        $out = ['status' => $row->ai_status];
        if ($row->ai_flagged !== null) {
            $out['flagged'] = (bool) $row->ai_flagged;
        }
        if ($row->ai_categories !== null) {
            $out['categories'] = json_decode((string) $row->ai_categories, true);
        }
        if ($row->ai_model !== null) {
            $out['model'] = $row->ai_model;
        }

        return $out;
    }

    /** automatic: yerel sonuc her okumada guncel terimlerle yeniden hesaplanir (kaynak gibi). */
    public static function automatic(string $text, stdClass $row): array
    {
        return ['local' => Moderation::local($text), 'ai' => self::ai($row)];
    }

    public static function aiEnabled(): bool
    {
        return config('otosanayide.flags.ai_moderation') === true && self::openAiKey() !== null;
    }

    public static function openAiKey(): ?string
    {
        $key = config('otosanayide.openai_api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
