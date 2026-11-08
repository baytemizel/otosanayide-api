<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Symfony test istegi varsayilan olarak en-us gonderir; gercek istemci davranisi basliksizdir. */
    protected function transformHeadersToServerVars(array $headers)
    {
        return parent::transformHeadersToServerVars($headers) + ['HTTP_ACCEPT_LANGUAGE' => ''];
    }
}
