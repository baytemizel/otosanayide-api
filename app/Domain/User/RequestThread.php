<?php

namespace App\Domain\User;

use App\Domain\Account\AccountFence;
use App\Domain\Account\Membership;
use App\Domain\Catalogue\BusinessReader;
use App\Domain\Content\TextRules;
use App\Exceptions\HttpError;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * web-15 madde 2: talep ici ek bilgi yazismasi. Taraflar yalniz talep sahibi ve talebi yonetebilen isletme uyesi.
 * Kapanmis talepte (iptal, ret, tamamlandi) yazilamaz. Metin 1-1000, baglanti/iletisim bilgisi yok; talep basina 100 mesaj.
 */
final class RequestThread
{
    public const MAX = 100;

    public static function postSchema(): Z
    {
        return Z::strictObject(['id' => RequestRules::id(), 'text' => Z::string()->trim()->min(1)->max(1000), 'operationId' => Z::string()->uuid()]);
    }

    /** @return array{0:stdClass,1:string} talep satiri ve rol */
    private static function role(string $uid, string $requestId, bool $lock): array
    {
        $q = DB::table('requests')->where('id', $requestId);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($r === null) {
            throw new HttpError(404, 'Talep bulunamadı.');
        }
        if ($r->user_id === $uid) {
            return [$r, 'customer'];
        }
        $b = BusinessReader::records([$r->business_id])[$r->business_id] ?? null;
        if (Requests::mayManage($uid, $r->business_id, $b, Membership::find($uid, $r->business_id, $lock))) {
            return [$r, 'business'];
        }
        throw new HttpError(403, 'Bu talep için yetkin yok.');
    }

    private static function view(stdClass $m): array
    {
        return ['id' => $m->id, 'author' => $m->author, 'text' => $m->text, 'createdAtMs' => (int) $m->created_at_ms];
    }

    public static function page(string $uid, string $requestId, ?string $after): array
    {
        self::role($uid, $requestId, false);
        $q = DB::table('request_messages')->where('request_id', $requestId);
        if ($after !== null) {
            $cursor = DB::table('request_messages')->where('request_id', $requestId)->where('id', $after)->first();
            if ($cursor === null) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            $q->where(fn ($w) => $w->where('created_at_ms', '>', $cursor->created_at_ms)->orWhere(fn ($x) => $x->where('created_at_ms', $cursor->created_at_ms)->where('id', '>', $cursor->id)));
        }
        $rows = $q->orderBy('created_at_ms')->orderBy('id')->limit(51)->get();
        $page = $rows->take(50);

        return ['items' => $page->map(fn (stdClass $m) => self::view($m))->values()->all(), 'next' => $rows->count() > 50 ? $page->last()->id : null];
    }

    /** @param array{id:string,text:string,operationId:string} $in @return array{created:bool,message:array<string,mixed>} */
    public static function post(string $uid, array $in): array
    {
        if (! TextRules::acceptable($in['text'])) {
            throw new HttpError(400, 'Mesajda bağlantı, iletişim bilgisi veya uygunsuz ifade olmamalı.');
        }

        return Tx::run(static function () use ($uid, $in): array {
            [$r, $role] = self::role($uid, $in['id'], true);
            $same = DB::table('request_messages')->where('request_id', $r->id)->where('operation_id', $in['operationId'])->first();
            if ($same !== null) {
                if ($same->author_uid !== $uid || $same->text !== $in['text']) {
                    throw new HttpError(409, 'İşlem anahtarı farklı içerikte kullanılmış.');
                }

                return ['created' => false, 'message' => self::view($same)];
            }
            AccountFence::assertWritable($uid);
            AccountFence::assertWritable($r->user_id);
            if (in_array($r->status, ['cancelled', 'declined', 'completed'], true)) {
                throw new HttpError(409, 'Kapanmış talebe mesaj yazılamaz.');
            }
            if (DB::table('request_messages')->where('request_id', $r->id)->count() >= self::MAX) {
                throw new HttpError(409, 'Bu talepte mesaj sınırına ulaşıldı.');
            }
            $row = ['request_id' => $r->id, 'id' => (string) Str::uuid(), 'operation_id' => $in['operationId'], 'author' => $role, 'author_uid' => $uid, 'text' => $in['text'], 'created_at_ms' => Js::nowMs()];
            DB::table('request_messages')->insert($row);

            return ['created' => true, 'message' => self::view((object) $row)];
        });
    }
}
