<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(TestCase::class)->use(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('mysql')->in('Mysql');
