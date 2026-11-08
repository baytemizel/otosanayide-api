<?php

it('requires auth on admin queues', function (string $m, string $u) {
    $this->call($m, $u, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
})->with([['GET', '/api/admin/notifications'], ['PATCH', '/api/admin/notifications'], ['GET', '/api/admin/privacy'], ['POST', '/api/admin/privacy']]);

it('admin privacy refuses the mobile origin', function () {
    $this->call('GET', '/api/admin/privacy', [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test', 'HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(403);
});
