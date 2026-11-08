<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class ExactHeaderBag extends ResponseHeaderBag
{
    public function set(string $key, string|array|null $values, bool $replace = true): void
    {
        parent::set($key, $values, $replace);
        if (strtolower($key) === 'cache-control' && $values !== null && $values !== '') {
            $this->headers['cache-control'] = array_values((array) $values);
        }
    }
}
