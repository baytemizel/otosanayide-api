<?php

function jsonPost($test, string $uri, string $body, array $server = [])
{
    return $test->call('POST', $uri, [], [], [], $server + ['HTTP_ACCEPT_LANGUAGE' => '', 'CONTENT_TYPE' => 'application/json'], $body);
}

// Veritabani gerektirmeyen ret yollari: dogrulama sirasi ve sozlesme bicimi.

it('requires a bearer token on protected auth endpoints', function () {
    jsonPost($this, '/api/auth/mfa/totp/enroll', '{}')->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
    jsonPost($this, '/api/auth/email/resend', '{}')->assertStatus(401);
});

it('rejects non-JSON content types with 415', function () {
    $this->call('POST', '/api/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://otosanayide.test', 'HTTP_ACCEPT_LANGUAGE' => '', 'CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
});

it('rejects unknown fields and short passwords with 400', function () {
    jsonPost($this, '/api/auth/register', '{"email":"a@b.co","password":"long-enough-1","extra":1}')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
    jsonPost($this, '/api/auth/register', '{"email":"a@b.co","password":"short"}')->assertStatus(400);
    jsonPost($this, '/api/auth/register', '{"email":"not-email","password":"long-enough-1"}')->assertStatus(400);
});

it('rejects a body over 20000 bytes with 413', function () {
    jsonPost($this, '/api/auth/login', '{"email":"'.str_repeat('a', 20000).'"}', ['HTTP_ORIGIN' => 'https://otosanayide.test'])->assertStatus(413);
});

it('refuses a foreign origin on cookie endpoints', function () {
    $this->withHeader('Origin', 'https://evil.test')->post('/api/auth/refresh')->assertStatus(403);
    $this->flushHeaders()->withHeader('Origin', 'https://m.otosanayide.test')->post('/api/auth/refresh')->assertStatus(403);
});

it('refresh without a cookie is 401', function () {
    $this->withHeader('Origin', 'https://otosanayide.test')->post('/api/auth/refresh')->assertStatus(401)->assertExactJson(['error' => 'Oturum geçersiz veya süresi dolmuş.']);
});
