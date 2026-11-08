<?php

namespace App\Support;

use Illuminate\Http\Request;

/** URLSearchParams karsiligi: Laravel query() tekrar eden anahtarlari ve a[b] bicimini gizler. */
final class Query
{
    /** @param list<array{0:string,1:string}> $pairs */
    private function __construct(private readonly array $pairs) {}

    public static function from(Request $request): self
    {
        return self::parse((string) $request->server->get('QUERY_STRING', ''));
    }

    public static function parse(string $raw): self
    {
        $pairs = [];
        foreach (explode('&', $raw) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $key = $eq === false ? $part : substr($part, 0, $eq);
            $value = $eq === false ? '' : substr($part, $eq + 1);
            $pairs[] = [self::decode($key), self::decode($value)];
        }

        return new self($pairs);
    }

    private static function decode(string $value): string
    {
        $bytes = rawurldecode(str_replace('+', ' ', $value));

        return (string) mb_scrub($bytes, 'UTF-8');
    }

    public function isEmpty(): bool
    {
        return $this->pairs === [];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (array $p): string => $p[0], $this->pairs);
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function get(string $key): ?string
    {
        foreach ($this->pairs as [$k, $v]) {
            if ($k === $key) {
                return $v;
            }
        }

        return null;
    }

    public function count(string $key): int
    {
        return count(array_keys($this->keys(), $key, true));
    }
}
