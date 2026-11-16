<?php

namespace App\Console\Commands;

use App\Models\CandidateProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Client as Meilisearch;
use Meilisearch\Exceptions\ApiException;
use RuntimeException;

class RebuildCandidateSearch extends Command
{
    protected $signature = 'erin:search:rebuild-candidates';

    protected $description = 'Ersetzt ausschließlich den abgeleiteten Fachkräfte-Suchindex durch aktuelle veröffentlichte Profile.';

    public function handle(Meilisearch $client): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->error('Dieser Befehl benötigt den Meilisearch-Treiber.');

            return self::FAILURE;
        }

        $name = (new CandidateProfile)->searchableAs();
        $lock = Cache::lock('candidate-search-rebuild:'.$name, 3600);
        if (! $lock->get()) {
            $this->error('Der Fachkräfte-Index wird bereits neu aufgebaut.');

            return self::FAILURE;
        }

        try {
            try {
                $index = $client->getIndex($name);
            } catch (ApiException $exception) {
                if ($exception->errorCode !== 'index_not_found') {
                    throw $exception;
                }
                $this->awaitTask($client, $client->createIndex($name, ['primaryKey' => 'id']));
                $index = $client->index($name);
            }

            $this->awaitTask($client, $index->updateSettings(config('scout.meilisearch.index-settings')[CandidateProfile::class]));
            $this->awaitTask($client, $index->deleteAllDocuments());
            $count = 0;
            CandidateProfile::query()->published()->with(['occupation', 'skills', 'languages'])
                ->chunkById(250, function ($profiles) use ($client, $index, &$count): void {
                    $documents = $profiles->map(fn (CandidateProfile $profile): array => $profile->toSearchableArray())->all();
                    $this->awaitTask($client, $index->addDocuments($documents, 'id'));
                    $count += count($documents);
                });

            $this->info("{$count} veröffentlichte Profile synchron in {$name} indexiert. Keine Profildaten gelöscht.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $task */
    private function awaitTask(Meilisearch $client, array $task): void
    {
        $result = $client->waitForTask($task['taskUid'], 50000);
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('Suchindex-Aufgabe fehlgeschlagen: '.($result['error']['code'] ?? 'unknown'));
        }
    }
}
