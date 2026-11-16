<?php

use App\Models\CandidateProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

uses(RefreshDatabase::class);

it('rebuilds only published candidate documents and waits for every indexing task', function () {
    config(['scout.driver' => 'collection']);
    $published = CandidateProfile::factory()->count(2)->create();
    CandidateProfile::factory()->create(['published_at' => null]);
    $deleted = CandidateProfile::factory()->create();
    $deleted->delete();
    $count = CandidateProfile::withTrashed()->count();
    config(['scout.driver' => 'meilisearch']);
    $client = Mockery::mock(Client::class);
    $index = Mockery::mock(Indexes::class);
    app()->instance(Client::class, $client);
    $client->shouldReceive('getIndex')->once()->with((new CandidateProfile)->searchableAs())->andReturn($index);
    $index->shouldReceive('updateSettings')->once()->with(config('scout.meilisearch.index-settings')[CandidateProfile::class])->andReturn(['taskUid' => 1]);
    $index->shouldReceive('deleteAllDocuments')->once()->andReturn(['taskUid' => 2]);
    $index->shouldReceive('addDocuments')->once()->withArgs(function (array $documents, string $key) use ($published): bool {
        return $key === 'id'
            && array_column($documents, 'id') === $published->modelKeys()
            && ! array_key_exists('email', $documents[0])
            && ! array_key_exists('user_id', $documents[0]);
    })->andReturn(['taskUid' => 3]);
    foreach ([1, 2, 3] as $id) {
        $client->shouldReceive('waitForTask')->once()->with($id, 50000)->andReturn(['status' => 'succeeded']);
    }

    $this->artisan('erin:search:rebuild-candidates')->assertSuccessful();
    expect(CandidateProfile::withTrashed()->count())->toBe($count);
});

it('rejects rebuilds for another search driver without touching an index', function () {
    config(['scout.driver' => 'collection']);
    $client = Mockery::mock(Client::class);
    $client->shouldNotReceive('getIndex');
    app()->instance(Client::class, $client);
    $this->artisan('erin:search:rebuild-candidates')->assertFailed();
});

it('reports a failed indexing task and releases the rebuild lock', function () {
    config(['scout.driver' => 'meilisearch']);
    $client = Mockery::mock(Client::class);
    $index = Mockery::mock(Indexes::class);
    app()->instance(Client::class, $client);
    $client->shouldReceive('getIndex')->once()->andReturn($index);
    $index->shouldReceive('updateSettings')->once()->andReturn(['taskUid' => 1]);
    $client->shouldReceive('waitForTask')->once()->with(1, 50000)->andReturn(['status' => 'failed', 'error' => ['code' => 'invalid_settings']]);
    $index->shouldNotReceive('deleteAllDocuments');

    try {
        $this->artisan('erin:search:rebuild-candidates')->run();
        $this->fail('A failed indexing task must not be reported as success.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('invalid_settings');
    }
    $lock = Cache::lock('candidate-search-rebuild:'.(new CandidateProfile)->searchableAs(), 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
