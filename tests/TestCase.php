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

    /** RefreshDatabase semayi silmeden once calisir: setUp kontrolu bunun icin gec kalir. */
    protected function beforeRefreshingDatabase()
    {
        if (config('database.connections.mysql.database') !== 'otosanayide_test' || config('database.default') !== 'mysql') {
            throw new \RuntimeException('migrate:fresh yalniz otosanayide_test uzerinde calisir.');
        }
    }

    /** Symfony test istegi varsayilan olarak en-us gonderir; gercek istemci davranisi basliksizdir. */
    protected function transformHeadersToServerVars(array $headers)
    {
        return parent::transformHeadersToServerVars($headers) + ['HTTP_ACCEPT_LANGUAGE' => ''];
    }
}
