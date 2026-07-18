<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    config()->set('erin.health.search_required', false);
    config()->set('erin.health.clamav_required', false);
});

it('returns a correlation id and preserves only valid incoming UUIDs', function () {
    $uuid = '1e7d84c6-388e-4aa8-97f1-6e4171bcf2fe';

    $this->get(route('home'), ['X-Request-ID' => $uuid])
        ->assertOk()
        ->assertHeader('X-Request-ID', $uuid);

    $response = $this->get(route('home'), [
        'X-Request-ID' => "not-valid\ninjected",
    ])->assertOk();

    expect($response->headers->get('X-Request-ID'))
        ->toMatch('/^[0-9a-f-]{36}$/')
        ->not->toBe("not-valid\ninjected");
});

it('fails closed on a stale scheduler heartbeat and becomes ready after recovery', function () {
    Cache::forget('erin:ops:scheduler-heartbeat');

    $this->getJson(route('health.ready'))
        ->assertServiceUnavailable()
        ->assertJsonPath('status', 'unavailable')
        ->assertJsonPath('checks.scheduler', false);

    Cache::put('erin:ops:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(3));

    $this->getJson(route('health.ready'))
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true)
        ->assertJsonPath('checks.storage', true);
});

it('exposes dependency and queue metrics only with the configured scrape token', function () {
    config()->set('erin.health.metrics_token', 'metrics-test-token');
    Cache::put('erin:ops:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(5));

    $this->get(route('health.metrics'))->assertNotFound();
    $this->withToken('wrong-token')
        ->get(route('health.metrics'))
        ->assertNotFound();
    $this->withToken('metrics-test-token')
        ->get(route('health.metrics'))
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; version=0.0.4; charset=UTF-8')
        ->assertSee('erin_dependency_up{dependency="database"} 1', false)
        ->assertSee('erin_queue_backlog_jobs{queue="default"}', false)
        ->assertSee('erin_scheduler_lag_seconds', false);
});
