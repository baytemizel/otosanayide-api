<?php

use App\Support\I18n;
use App\Support\Js;
use App\Support\Origin;
use App\Support\Z;
use App\Support\ZodError;

dataset('origins', [
    'null is native' => [null, 'https://a.test', null, true],
    'same' => ['https://a.test', 'https://a.test', null, true],
    'path rejected' => ['https://a.test/x', 'https://a.test', null, false],
    'uppercase rejected' => ['https://A.test', 'https://a.test', null, false],
    'default port rejected' => ['https://a.test:443', 'https://a.test', null, false],
    'userinfo rejected' => ['https://u@a.test', 'https://a.test', null, false],
    'garbage' => ['not a url', 'https://a.test', null, false],
    'configured' => ['https://m.test', 'https://a.test', 'https://m.test', true],
    'configured http refused' => ['http://m.test', 'https://a.test', 'http://m.test', false],
    'configured with slash never matches' => ['https://m.test', 'https://a.test', 'https://m.test/', false],
    'loopback same port' => ['http://127.0.0.1:3000', 'http://localhost:3000', null, true],
    'loopback other port' => ['http://127.0.0.1:3001', 'http://localhost:3000', null, false],
    'loopback does not generalize' => ['http://localhost:3000', 'https://a.test', null, false],
]);

it('matches garageOriginAllowed', function (?string $o, string $s, ?string $c, bool $e) {
    expect(Origin::allowed($o, $s, $c))->toBe($e);
})->with('origins');

it('measures strings like JavaScript', function () {
    expect(Js::length('ş'))->toBe(1)->and(Js::length('😀'))->toBe(2);
    expect(Js::trim("\u{00A0}\u{3000} a \u{FEFF}"))->toBe('a');
});

it('translates with placeholders and keeps padding', function () {
    expect(I18n::t('  Giriş gerekli.  ', [], 'tr'))->toBe('  Giriş gerekli.  ');
    expect(I18n::t('{n} adet {x}', ['n' => 3], 'tr'))->toBe('3 adet {x}');
    expect(I18n::t('Bilinmeyen anahtar', [], 'en'))->toBe('Bilinmeyen anahtar');
    expect(I18n::t('1 gün önce', [], 'en'))->toBe('1 day before');
});

it('resolves locale', function () {
    expect(I18n::resolve('en', 'tr'))->toBe('en')
        ->and(I18n::resolve(null, 'de,en;q=0.5,tr;q=0.8'))->toBe('tr')
        ->and(I18n::resolve(null, 'de'))->toBe('tr')
        ->and(I18n::resolve('xx', 'en-US'))->toBe('en')
        ->and(I18n::resolve(null, null))->toBe('tr');
});

function issue(Closure $f): string
{
    try {
        $f();
    } catch (ZodError $e) {
        return $e->issue;
    }

    return 'none';
}

it('z: strict objects, strip, optional, nullable, default', function () {
    $s = Z::strictObject(['a' => Z::string(), 'b' => Z::number()->int()->optional()]);
    expect($s->parse(json_decode('{"a":"x"}')))->toBe(['a' => 'x']);
    expect(issue(fn () => $s->parse(json_decode('{"a":"x","c":1}'))))->toBe('unrecognized_keys');
    expect(issue(fn () => $s->parse(json_decode('{"b":1}'))))->toBe('invalid_type');
    expect(Z::object(['a' => Z::string()])->parse(json_decode('{"a":"x","c":1}')))->toBe(['a' => 'x']);
    expect(Z::object(['a' => Z::string()->default('d')])->parse(new stdClass))->toBe(['a' => 'd']);
    expect(Z::object(['a' => Z::string()->nullable()])->parse(json_decode('{"a":null}')))->toBe(['a' => null]);
    expect(issue(fn () => Z::object(['a' => Z::string()])->parse(json_decode('[]'))))->toBe('invalid_type');
});

it('z: numbers follow JS semantics', function () {
    expect(Z::number()->int()->parse(1.0))->toBe(1);
    expect(issue(fn () => Z::number()->int()->parse(1.5)))->toBe('invalid_type');
    expect(issue(fn () => Z::number()->parse(INF)))->toBe('invalid_type');
    expect(issue(fn () => Z::number()->int()->parse(9007199254740992)))->toBe('invalid_type');
    expect(issue(fn () => Z::number()->parse('1')))->toBe('invalid_type');
});

it('z: strings trim before measuring and regex end is strict', function () {
    expect(Z::string()->trim()->min(2)->parse(' ab '))->toBe('ab');
    expect(issue(fn () => Z::string()->trim()->min(3)->parse(' ab ')))->toBe('too_small');
    expect(issue(fn () => Z::string()->regex('/^a$/')->parse("a\n")))->toBe('invalid_format');
    expect(Z::string()->max(1)->parse('ş'))->toBe('ş');
});

it('z: first issue wins in shape order and unions pick first match', function () {
    $s = Z::object(['a' => Z::string(), 'b' => Z::string()->refine(fn () => false, 'm')]);
    expect(issue(fn () => $s->parse(json_decode('{"a":1,"b":"x"}'))))->toBe('invalid_type');
    expect(Z::union([Z::string(), Z::number()])->parse(1))->toBe(1);
    $d = Z::discriminatedUnion('t', ['a' => Z::strictObject(['t' => Z::literal('a')])]);
    expect($d->parse(json_decode('{"t":"a"}')))->toBe(['t' => 'a']);
    expect(issue(fn () => $d->parse(json_decode('{"t":"b"}'))))->toBe('invalid_union');
});
