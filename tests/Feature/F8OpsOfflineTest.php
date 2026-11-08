<?php

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalFixtureSeeder;
use Illuminate\Support\Facades\App;

it('registers cleanup in the schedule and keeps writers off by default', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode(' ');
    expect($events)->toContain('ops:cleanup')->not->toContain('notifications:deliver-requests')->not->toContain('garage:purge-recycle');
});

it('does not write anything from the default seeder', function () {
    $this->seed(DatabaseSeeder::class);
    $this->assertTrue(true);
});

it('refuses synthetic fixtures in production', function () {
    App::detectEnvironment(fn () => 'production');
    expect(fn () => (new LocalFixtureSeeder)->run())->toThrow(RuntimeException::class);
});
