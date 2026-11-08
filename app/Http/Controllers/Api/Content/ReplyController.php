<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\Actor;
use App\Domain\Content\Replies;
use App\Domain\Content\Schemas;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReplyController
{
    use Guards;

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, POST, PATCH, OPTIONS');
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::console($r);
            $u = $this->verified($r, self::VERIFY);
            $q = Query::from($r);
            $id = Schemas::id()->parse($q->get('businessId'));
            $after = $q->get('after');
            $cursor = ($after !== null && $after !== '') ? Schemas::id()->parse($after) : null;

            return Respond::json(Replies::page($u, $id, $cursor));
        });
    }

    public function store(Request $r): Response
    {
        return Respond::guarded($r, fn (): Response => $this->write($r, false));
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, fn (): Response => $this->write($r, true));
    }

    private function write(Request $r, bool $withdraw): Response
    {
        HttpInput::console($r);
        $u = $this->verified($r, self::VERIFY);
        $raw = Body::json($r);
        $input = $withdraw ? Schemas::replyWithdraw()->parse($raw) : Schemas::replyInput()->parse($raw);
        Replies::write($u, $input, $withdraw, $withdraw ? null : $input['text']);

        return Respond::json(['ok' => true]);
    }
}
