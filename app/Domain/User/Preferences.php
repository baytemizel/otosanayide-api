<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * web-09: bildirim tercihleri. Uygulama ici talep bildirimi kapatilamaz. Urun duyurulari varsayilan KAPALI;
 * acma acik onay olarak (zaman, kaynak, metin surumu) kaydedilir. Pazarlama gonderimi YAZILMADI (IYS/hukuk karari).
 * reminderEmail hesap duzeyinde ust anahtardir: kapaliysa hatirlatma basina `channels` icinde e-posta secili olsa da
 * reminders:deliver-email gondermez ve kuyrugu `preference_changed` ile kapatir.
 */
final class Preferences
{
    /** Onay metni surumleri; web/native metniyle ayni anahtar gonderilir. Oneri: metin hukuk onayindan sonra degisir. */
    public const TEXT_VERSIONS = ['product-news-2026-11'];

    public static function schema(): Z
    {
        $changes = Z::strictObject([
            'requestUpdatesPush' => Z::boolean()->optional(),
            'requestUpdatesEmail' => Z::boolean()->optional(),
            'reminderEmail' => Z::boolean()->optional(),
            'productNews' => Z::boolean()->optional(),
            'productNewsTextVersion' => Z::enum(self::TEXT_VERSIONS)->optional(),
        ])->refine(static fn (array $c): bool => $c !== [] && (($c['productNews'] ?? false) !== true || isset($c['productNewsTextVersion'])), 'changes');

        return Z::strictObject(['expectedVersion' => Z::number()->int()->nonnegative(), 'changes' => $changes]);
    }

    /** @return array<string,mixed> */
    public static function view(?stdClass $r): array
    {
        return [
            'version' => (int) ($r->version ?? 0),
            'requestUpdates' => ['inApp' => true, 'push' => (bool) ($r->request_updates_push ?? false), 'email' => (bool) ($r->request_updates_email ?? false)],
            'reminderEmail' => (bool) ($r->reminder_email ?? true),
            'productNews' => [
                'enabled' => (bool) ($r->product_news ?? false),
                'consentAtMs' => isset($r->product_news_consent_at_ms) ? (int) $r->product_news_consent_at_ms : null,
                'source' => $r->product_news_consent_source ?? null,
                'textVersion' => $r->product_news_text_version ?? null,
            ],
            'delivery' => ['requestUpdatesPush' => 'not_enabled', 'requestUpdatesEmail' => 'not_enabled', 'productNews' => 'not_enabled'],
            'updatedAtMs' => isset($r->updated_at_ms) ? (int) $r->updated_at_ms : null,
        ];
    }

    public static function read(string $uid): array
    {
        return self::view(DB::table('user_preferences')->where('user_id', $uid)->first());
    }

    /** @param array{expectedVersion:int,changes:array<string,mixed>} $input */
    public static function update(Actor $u, array $input, ?string $source): array
    {
        $c = $input['changes'];
        $enablingEmail = ($c['requestUpdatesEmail'] ?? false) === true || ($c['reminderEmail'] ?? false) === true || ($c['productNews'] ?? false) === true;
        if ($enablingEmail && ! $u->emailVerified) {
            throw new HttpError(403, 'E-posta doğrulaması gerekli.');
        }
        if (array_key_exists('productNews', $c) && $source === null) {
            throw new HttpError(400, 'Geçersiz istek.');
        }

        return Tx::run(static function () use ($u, $input, $c, $source): array {
            AccountFence::assertWritable($u->uid);
            $row = DB::table('user_preferences')->where('user_id', $u->uid)->lockForUpdate()->first();
            if ((int) ($row->version ?? 0) !== $input['expectedVersion']) {
                throw new HttpError(409, 'Tercihler değişti. Sayfayı yenile.');
            }
            $now = Js::nowMs();
            $update = ['version' => (int) ($row->version ?? 0) + 1, 'updated_at_ms' => $now];
            foreach (['requestUpdatesPush' => 'request_updates_push', 'requestUpdatesEmail' => 'request_updates_email', 'reminderEmail' => 'reminder_email'] as $k => $col) {
                if (array_key_exists($k, $c)) {
                    $update[$col] = $c[$k];
                }
            }
            if (array_key_exists('productNews', $c) && $c['productNews'] !== (bool) ($row->product_news ?? false)) {
                $update['product_news'] = $c['productNews'];
                $update += $c['productNews']
                    ? ['product_news_consent_at_ms' => $now, 'product_news_consent_source' => $source, 'product_news_text_version' => $c['productNewsTextVersion']]
                    : ['product_news_consent_at_ms' => null, 'product_news_consent_source' => null, 'product_news_text_version' => null];
                DB::table('user_consent_events')->insert(['user_id' => $u->uid, 'id' => (string) Str::uuid(), 'kind' => 'product_news', 'granted' => $c['productNews'], 'source' => $source, 'text_version' => $c['productNewsTextVersion'] ?? ($row->product_news_text_version ?? self::TEXT_VERSIONS[0]), 'created_at_ms' => $now]);
            }
            if ($row === null) {
                DB::table('user_preferences')->insert(['user_id' => $u->uid] + $update);
            } else {
                DB::table('user_preferences')->where('user_id', $u->uid)->update($update);
            }

            return self::view(DB::table('user_preferences')->where('user_id', $u->uid)->first());
        });
    }

    public static function reminderEmailAllowed(string $uid): bool
    {
        $v = DB::table('user_preferences')->where('user_id', $uid)->value('reminder_email');

        return $v === null || (bool) $v;
    }
}
