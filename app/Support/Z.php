<?php

namespace App\Support;

use Closure;
use stdClass;

/**
 * Kaynaktaki Zod semalarini kurali kaybetmeden tasimak icin kucuk karsilik.
 * Hata modeli: kaynak yalniz issues[0] kullanir; ilk sorunda durmak ayni sonucu verir.
 * Girdi Body::json() ciktisidir: nesneler stdClass, diziler listedir. Cikis nesneleri PHP dizisidir.
 * Dizgi uzunlugu UTF-16 ile olculur; regex'ler JS `$` anlami icin D ile calisir.
 */
final class Z
{
    private static ?stdClass $undefined = null;

    private function __construct(private readonly Closure $run) {}

    private static function undef(): stdClass
    {
        return self::$undefined ??= new stdClass;
    }

    public function parse(mixed $input): mixed
    {
        $out = ($this->run)($input);

        return $out === self::undef() ? null : $out;
    }

    private function then(Closure $next): self
    {
        $run = $this->run;

        return new self(static fn (mixed $v): mixed => $next($run($v)));
    }

    private static function fail(string $code, string $message = ''): never
    {
        throw new ZodError($code, $message);
    }

    public static function string(): self
    {
        return new self(static fn (mixed $v): string => is_string($v) ? $v : self::fail('invalid_type'));
    }

    public static function number(): self
    {
        return new self(static function (mixed $v): int|float {
            if ((! is_int($v) && ! is_float($v)) || (is_float($v) && ! is_finite($v))) {
                self::fail('invalid_type');
            }

            return $v;
        });
    }

    public static function boolean(): self
    {
        return new self(static fn (mixed $v): bool => is_bool($v) ? $v : self::fail('invalid_type'));
    }

    public static function literal(string|int|bool|null ...$values): self
    {
        return new self(static function (mixed $v) use ($values): mixed {
            if (is_float($v) && $v == floor($v) && abs($v) <= PHP_INT_MAX) {
                $v = (int) $v; // JSON'da 1 ile 1.0 ayni sayidir.
            }

            return in_array($v, $values, true) ? $v : self::fail('invalid_value');
        });
    }

    /** @param list<string> $values */
    public static function enum(array $values): self
    {
        return self::literal(...$values);
    }

    public static function any(): self
    {
        return new self(static fn (mixed $v): mixed => $v);
    }

    /** @param array<string,self> $shape */
    public static function object(array $shape, string $unknown = 'strip'): self
    {
        return new self(static function (mixed $v) use ($shape, $unknown): array {
            if (! $v instanceof stdClass) {
                self::fail('invalid_type');
            }
            $out = [];
            foreach ($shape as $key => $schema) {
                $value = property_exists($v, (string) $key) ? $v->{$key} : self::undef();
                $parsed = ($schema->run)($value);
                if ($parsed !== self::undef()) {
                    $out[$key] = $parsed;
                }
            }
            $extra = array_diff_key(get_object_vars($v), $shape);
            if ($extra !== []) {
                if ($unknown === 'strict') {
                    self::fail('unrecognized_keys');
                }
                if ($unknown === 'passthrough') {
                    $out += $extra;
                }
            }

            return $out;
        });
    }

    /** @param array<string,self> $shape */
    public static function strictObject(array $shape): self
    {
        return self::object($shape, 'strict');
    }

    public static function array(self $item): self
    {
        return new self(static function (mixed $v) use ($item): array {
            if (! is_array($v)) {
                self::fail('invalid_type');
            }

            return array_map(static function (mixed $x) use ($item): mixed {
                $r = ($item->run)($x);

                return $r === self::undef() ? null : $r;
            }, $v);
        });
    }

    public static function record(self $key, self $value): self
    {
        return new self(static function (mixed $v) use ($key, $value): array {
            if (! $v instanceof stdClass) {
                self::fail('invalid_type');
            }
            $out = [];
            foreach (get_object_vars($v) as $k => $x) {
                $out[($key->run)((string) $k)] = ($value->run)($x);
            }

            return $out;
        });
    }

    /** @param list<self> $options */
    public static function union(array $options): self
    {
        return new self(static function (mixed $v) use ($options): mixed {
            foreach ($options as $option) {
                try {
                    return ($option->run)($v);
                } catch (ZodError) {
                }
            }
            self::fail('invalid_union');
        });
    }

    /** @param array<string,self> $options discriminator degeri => sema */
    public static function discriminatedUnion(string $key, array $options): self
    {
        return new self(static function (mixed $v) use ($key, $options): mixed {
            if (! $v instanceof stdClass) {
                self::fail('invalid_type');
            }
            $d = property_exists($v, $key) ? $v->{$key} : null;
            if (! is_string($d) || ! isset($options[$d])) {
                self::fail('invalid_union');
            }

            return ($options[$d]->run)($v);
        });
    }

    public function optional(): self
    {
        $run = $this->run;

        return new self(static fn (mixed $v): mixed => $v === self::undef() ? $v : $run($v));
    }

    public function nullable(): self
    {
        $run = $this->run;

        return new self(static fn (mixed $v): mixed => $v === null ? null : $run($v));
    }

    public function default(mixed $value): self
    {
        $run = $this->run;

        return new self(static fn (mixed $v): mixed => $v === self::undef() ? $value : $run($v));
    }

    public function trim(): self
    {
        return $this->then(static fn (string $v): string => Js::trim($v));
    }

    public function toLowerCase(): self
    {
        return $this->then(static fn (string $v): string => mb_strtolower($v, 'UTF-8'));
    }

    public function min(int|float $n): self
    {
        return $this->then(static function (mixed $v) use ($n): mixed {
            $size = is_string($v) ? Js::length($v) : (is_array($v) ? count($v) : $v);

            return $size >= $n ? $v : self::fail('too_small');
        });
    }

    public function max(int|float $n): self
    {
        return $this->then(static function (mixed $v) use ($n): mixed {
            $size = is_string($v) ? Js::length($v) : (is_array($v) ? count($v) : $v);

            return $size <= $n ? $v : self::fail('too_big');
        });
    }

    public function length(int $n): self
    {
        return $this->min($n)->max($n);
    }

    public function regex(string $pattern): self
    {
        $parts = strrpos($pattern, '/');
        $pattern = substr($pattern, 0, $parts + 1).'D'.substr($pattern, $parts + 1);

        return $this->then(static fn (string $v): string => preg_match($pattern, $v) === 1 ? $v : self::fail('invalid_format'));
    }

    /** Zod 4 e-posta deseni. */
    public function email(): self
    {
        return $this->regex("/^(?!\\.)(?!.*\\.\\.)([A-Za-z0-9_'+\\-\\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\\-]*\\.)+[A-Za-z]{2,}$/");
    }

    /** Number.isSafeInteger; 1.0 gibi degerler int'e iner. */
    public function int(): self
    {
        return $this->then(static function (int|float $v): int {
            if ($v != floor($v) || abs($v) > 9007199254740991) {
                self::fail('invalid_type');
            }

            return (int) $v;
        });
    }

    public function nonnegative(): self
    {
        return $this->min(0);
    }

    public function positive(): self
    {
        return $this->then(static fn (int|float $v): int|float => $v > 0 ? $v : self::fail('too_small'));
    }

    /** Zod 4 z.uuid()/z.string().uuid() deseni. */
    public function uuid(): self
    {
        return $this->regex('/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)$/');
    }

    private const ISO_DATE = '((\d\d[2468][048]|\d\d[13579][26]|\d\d0[48]|[02468][048]00|[13579][26]00)-02-29|\d{4}-((0[13578]|1[02])-(0[1-9]|[12]\d|3[01])|(0[469]|11)-(0[1-9]|[12]\d|30)|(02)-(0[1-9]|1\d|2[0-8])))';

    /** z.iso.date(). */
    public function isoDate(): self
    {
        return $this->regex('/^'.self::ISO_DATE.'$/');
    }

    /** z.iso.datetime() / z.string().datetime(); $offset: {offset:true}. */
    public function isoDatetime(bool $offset = false): self
    {
        $tz = $offset ? '(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)' : 'Z';

        return $this->regex('/^'.self::ISO_DATE.'T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?'.$tz.'$/');
    }

    /** z.coerce.number(): JS Number() donusumu, NaN reddedilir. */
    public static function coerceNumber(): self
    {
        return new self(static function (mixed $v): int|float {
            if ($v === self::undef()) {
                self::fail('invalid_type');
            }
            if (is_array($v)) {
                $v = $v === [] ? 0 : (count($v) === 1 ? $v[0] : 'NaN');
            }
            if (is_bool($v)) {
                return $v ? 1 : 0;
            }
            if ($v === null) {
                return 0;
            }
            if (is_int($v) || is_float($v)) {
                return is_finite((float) $v) ? $v : self::fail('invalid_type');
            }
            if (! is_string($v)) {
                self::fail('invalid_type');
            }
            $t = Js::trim($v);
            if ($t === '') {
                return 0;
            }
            if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $t) === 1) {
                return (int) hexdec(substr($t, 2));
            }
            if (preg_match('/^0[bB][01]+$/D', $t) === 1) {
                return (int) bindec(substr($t, 2));
            }
            if (preg_match('/^0[oO][0-7]+$/D', $t) === 1) {
                return (int) octdec(substr($t, 2));
            }
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $t) !== 1) {
                self::fail('invalid_type');
            }
            $n = (float) $t;
            if (! is_finite($n)) {
                self::fail('invalid_type');
            }

            return $n == floor($n) && abs($n) <= 9007199254740991 ? (int) $n : $n;
        });
    }

    public function refine(Closure $check, string $message = 'Invalid input'): self
    {
        return $this->then(static fn (mixed $v): mixed => $check($v) ? $v : self::fail('custom', $message));
    }

    public function transform(Closure $map): self
    {
        return $this->then($map);
    }
}
