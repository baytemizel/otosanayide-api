<?php

use App\Support\Z;
use App\Support\ZodError;

function bad(callable $f): bool
{
    try {
        $f();
    } catch (ZodError) {
        return true;
    }

    return false;
}

it('validates iso dates including leap years', function () {
    expect(Z::string()->isoDate()->parse('2024-02-29'))->toBe('2024-02-29');
    expect(bad(fn () => Z::string()->isoDate()->parse('2023-02-29')))->toBeTrue();
});

it('validates datetimes with and without offsets', function () {
    expect(Z::string()->isoDatetime()->parse('2026-01-01T10:00:00.000Z'))->toBeString();
    expect(bad(fn () => Z::string()->isoDatetime()->parse('2026-01-01T10:00:00+03:00')))->toBeTrue();
    expect(Z::string()->isoDatetime(true)->parse('2026-01-01T10:00:00+03:00'))->toBeString();
});

it('validates uuid and coerces numbers like Number()', function () {
    expect(Z::string()->uuid()->parse('123e4567-e89b-12d3-a456-426614174000'))->toBeString();
    expect(bad(fn () => Z::string()->uuid()->parse('nope')))->toBeTrue();
    expect(Z::coerceNumber()->parse('12'))->toBe(12)
        ->and(Z::coerceNumber()->parse(''))->toBe(0)
        ->and(Z::coerceNumber()->parse(true))->toBe(1)
        ->and(Z::coerceNumber()->parse('1.5'))->toBe(1.5);
    expect(bad(fn () => Z::coerceNumber()->parse('abc')))->toBeTrue();
});
