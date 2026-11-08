<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** JSON.stringify ciktisi ve birebir Cache-Control icin tek yanit fabrikasi. */
final class Api
{
    public const PRIVATE = ['Cache-Control' => 'private, no-store'];

    /** @param array<string,string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = self::PRIVATE): JsonResponse
    {
        $response = new JsonResponse($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::exact($response, $headers);
    }

    /** @param array<string,string> $headers */
    public static function empty(int $status, array $headers = self::PRIVATE): Response
    {
        return self::exact(new Response(null, $status), $headers);
    }

    /**
     * @template T of \Symfony\Component\HttpFoundation\Response
     *
     * @param  T  $response
     * @param  array<string,string>  $headers
     * @return T
     */
    public static function exact($response, array $headers)
    {
        $bag = new ExactHeaderBag;
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $bag->set($name, $values);
        }
        foreach ($headers as $name => $value) {
            $bag->set($name, $value);
        }
        $response->headers = $bag;

        return $response;
    }
}
