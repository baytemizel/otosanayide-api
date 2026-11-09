<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // .env uretim veritabanini gosterir; test paketi asla oraya ulasmamali (phpunit.xml: DB_PORT=1).
        if ((string) config('database.connections.mysql.port') !== '1') {
            throw new \RuntimeException('Testler veritabanina baglanmaz: DB_PORT=1 olmali.');
        }
    }

    /** Symfony test istegi varsayilan olarak en-us gonderir; gercek istemci davranisi basliksizdir. */
    protected function transformHeadersToServerVars(array $headers)
    {
        return parent::transformHeadersToServerVars($headers) + ['HTTP_ACCEPT_LANGUAGE' => ''];
    }
}
