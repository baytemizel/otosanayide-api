<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Gercek veritabanina karsi yanlislikla calismasin.
        if (config('database.connections.mysql.database') !== env('ALLOWED_TEST_DATABASE')) {
            throw new \RuntimeException('Testler yalniz izin verilen veritabaninda calisir.');
        }
    }

    /** RefreshDatabase semayi silmeden once calisir: setUp kontrolu bunun icin gec kalir. */
    protected function beforeRefreshingDatabase()
    {
        if (config('database.connections.mysql.database') !== env('ALLOWED_TEST_DATABASE') || config('database.default') !== 'mysql') {
            throw new \RuntimeException('migrate:fresh yalniz izin verilen veritabaninda calisir.');
        }
        foreach (['auth_accounts', 'businesses'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException('Veritabaninda kayit var; migrate:fresh silmeyi reddetti: '.$table);
            }
        }
    }

    /** Symfony test istegi varsayilan olarak en-us gonderir; gercek istemci davranisi basliksizdir. */
    protected function transformHeadersToServerVars(array $headers)
    {
        return parent::transformHeadersToServerVars($headers) + ['HTTP_ACCEPT_LANGUAGE' => ''];
    }
}
