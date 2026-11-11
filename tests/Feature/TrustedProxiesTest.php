<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// K179 bulgu 3: TRUSTED_PROXIES yoksa davranis degismez; varsa yalniz listedeki proxy'nin X-Forwarded-For'u okunur.

beforeEach(function () {
    Route::get('/_test/ip', fn (Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure(), 'host' => $r->getHost()]));
});

function proxyCall($test, string $remote, array $headers = [])
{
    return $test->call('GET', '/_test/ip', [], [], [], ['REMOTE_ADDR' => $remote, 'HTTP_HOST' => 'api.otosanayide.test'] + $headers);
}

it('ignores forwarded headers when no proxy is trusted', function () {
    config(['otosanayide.trusted_proxies' => null]);
    expect(proxyCall($this, '173.245.48.10', ['HTTP_X_FORWARDED_FOR' => '203.0.113.7'])->json('ip'))->toBe('173.245.48.10');
});

it('reads the client address only through a trusted proxy', function () {
    config(['otosanayide.trusted_proxies' => '173.245.48.0/20, 2400:cb00::/32']);
    $r = proxyCall($this, '173.245.48.10', ['HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'evil.test']);
    expect($r->json('ip'))->toBe('203.0.113.7')->and($r->json('secure'))->toBeTrue()->and($r->json('host'))->not->toBe('evil.test');
    // Listede olmayan kaynak sahte baslikla IP degistiremez.
    expect(proxyCall($this, '198.51.100.9', ['HTTP_X_FORWARDED_FOR' => '203.0.113.7'])->json('ip'))->toBe('198.51.100.9');
    // Istemcinin kendi ekledigi sahte deger, guvenilen zincirin solunda kalir.
    expect(proxyCall($this, '173.245.48.10', ['HTTP_X_FORWARDED_FOR' => '10.0.0.1, 203.0.113.7'])->json('ip'))->toBe('203.0.113.7');
});
