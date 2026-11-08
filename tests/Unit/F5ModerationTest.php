<?php

use App\Domain\Content\Moderation;

// packages/core/content-moderation.test.ts ornekleri.

it('catches every requested symbol and ordered pair between letters', function () {
    $symbols = ['.', '*', '?', '\\', '/', '+', '^', "'", '!', '&', '(', ')', '='];
    $separators = $symbols;
    foreach ($symbols as $a) {
        foreach ($symbols as $b) {
            $separators[] = $a.$b;
        }
    }
    foreach ([['siktir', 'tr-profanity'], ['piç', 'tr-profanity'], ['fuck', 'en-profanity'], ['shit', 'en-profanity']] as [$word, $group]) {
        $letters = mb_str_split($word);
        foreach ($separators as $separator) {
            $samples = [implode($separator, $letters)];
            for ($i = 1; $i < count($letters); $i++) {
                $samples[] = implode('', array_slice($letters, 0, $i)).$separator.implode('', array_slice($letters, $i));
            }
            foreach ($samples as $sample) {
                expect(Moderation::inspect("TEST: {$sample}.")['signals'])->toContain($group);
            }
        }
    }
});

it('keeps mixed separators flagged and benign punctuation clean', function () {
    foreach (["s*!i?/k\\+t^'i!&r", 'f()u==c/*k'] as $text) {
        expect(Moderation::inspect($text)['flagged'])->toBeTrue();
    }
    foreach (['Bakım (yağ + filtre) = tamam! Cam/kaporta? A**B, C\\D & E.', 'Service (oil + filter) = done! Glass/bodywork? A**B, C\\D & E.', 'Klasik araç: s**i? Test sonucu iyi.', 'Classic car: f**u? Test result fine.'] as $text) {
        expect(Moderation::inspect($text)['flagged'])->toBeFalse();
    }
});

it('catches separators, homoglyphs, zero-width and leetspeak', function (string $text) {
    expect(Moderation::inspect($text)['flagged'])->toBeTrue();
})->with(['s.i.k.t.i.r', 'S İ K T İ R', 's💥i💥k💥t💥i💥r', "s\u{200B}i\u{200B}k\u{200B}tir", 's1kt1r', 'ＳＩＫＴＩＲ', 'sіktіr', 'f.u.c.k', 'f@u@c@k', 'f$u$c$k', 'FUUUUCK', 'sh1t', '4mk', 'p.i.ç']);

it('does not flag innocent words or negative feedback', function (string $text) {
    expect(Moderation::inspect($text)['flagged'])->toBeFalse();
})->with(['Cam filmi ve klasik araç bakımı', 'Sık sık aradım; sorun çözülmedi. Çok pahalıydı.', 'Classic car assistance and sheet metal', 'Scunthorpe service was disappointing', 'Dikdörtgen cam', 'İşçilik kötü, ödeme zor.']);

it('never mutates the original and bounds custom rules', function () {
    $text = "S\u{200B}İKTİR";
    expect(Moderation::normalize($text))->not->toBe($text)
        ->and($text)->toBe("S\u{200B}İKTİR")
        ->and(Moderation::inspect('spamword', ['spamword'])['flagged'])->toBeTrue()
        ->and(Moderation::inspect('normal', ['.*'])['flagged'])->toBeFalse();
});

it('reports the policy version, signals and obfuscation', function () {
    $r = Moderation::inspect('s.i.k.t.i.r');
    expect($r['policyVersion'])->toBe('tr-en-1')->and($r['flagged'])->toBeTrue()->and($r['signals'])->toBe(['tr-profanity'])->and($r['obfuscation'])->toBeTrue();
    expect(Moderation::inspect('Temiz metin')['obfuscation'])->toBeFalse();
});

it('reads extra terms from config and limits them to 100', function () {
    config(['otosanayide.moderation_extra_terms' => 'kotukelime,,digerterim']);
    expect(Moderation::local('bu kotukelime geçiyor')['signals'])->toBe(['custom'])->and(Moderation::local('temiz')['flagged'])->toBeFalse();
    $many = array_map(static fn (int $i): string => 'kelime'.chr(97 + $i % 26).chr(97 + ($i >> 3) % 26).'x', range(0, 119));
    expect(Moderation::inspect($many[110], array_values($many))['flagged'])->toBeFalse();
});
