<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Gercek veritabanina karsi yanlislikla calismasin.
        if (config('database.connections.mysql.database') !== 'otosanayide_test') {
            throw new \RuntimeException('Testler yalniz otosanayide_test ile calisir.');
        }
    }

    /** Symfony test istegi varsayilan olarak en-us gonderir; gercek istemci davranisi basliksizdir. */
    protected function transformHeadersToServerVars(array $headers)
    {
        return parent::transformHeadersToServerVars($headers) + ['HTTP_ACCEPT_LANGUAGE' => ''];
    }
}
