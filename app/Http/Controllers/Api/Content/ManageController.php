<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Content\Profiles;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Query;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManageController
{
    use Guards;

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, PATCH, OPTIONS');
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::profile($r);
            $u = ActorResolver::resolve($r);
            RateLimit::consume($u->uid);
            $selected = Query::from($r)->get('businessId');
            $v2 = Query::from($r)->get('v') === '2';
            if ($selected !== null && $selected !== '') {
                if (! $u->emailVerified) {
                    throw new HttpError(403, self::VERIFY);
                }

                return Respond::json(Profiles::one($u, $selected, $v2));
            }

            return Respond::json(Profiles::all($u, $v2));
        });
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::profile($r);
            $u = $this->verified($r, self::VERIFY);
            $input = Schemas::businessChange()->parse(Body::json($r));

            return Respond::json(Profiles::submit($u, $input));
        });
    }
}
